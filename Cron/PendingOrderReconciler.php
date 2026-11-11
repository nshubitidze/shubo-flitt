<?php

declare(strict_types=1);

namespace Shubo\Flitt\Cron;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Exception\FlittApiException;
use Shubo\Flitt\Exception\PaymentInFlightException;
use Shubo\Flitt\Gateway\Config;
use Shubo\Flitt\Model\Callback\OrderSettler;
use Shubo\Flitt\Model\Checkout\PaidQuotePlacer;
use Shubo\Flitt\Model\Checkout\TokenManager;
use Shubo\Flitt\Model\FlittClient;
use Shubo\Flitt\Model\Ledger;
use Shubo\Flitt\Model\OrderLock;
use Shubo\Flitt\Model\Ui\ConfigProvider;

/**
 * The safety net: callbacks can be lost and browsers die — this
 * cron owns every ledger row nothing else finished.
 *
 * Two sweeps, both serialized per order via OrderLock and decided on the
 * SIGNED /status envelope (never on local state alone):
 *
 * 1. STALE MINTS — `minted` AND `declined` rows past their own lifetime +
 *    grace: the session can no longer be paid (`declined` is swept too —
 *    with delayed=Y a declined attempt keeps the session payable, so only
 *    lifetime expiry ends it). Paid after all → park `approved` (the hold
 *    sweep owns it next run); dead → mark expired and cancel the stranded
 *    pending_payment order (offline — no auth txn exists). This sweep is
 *    the ONLY place unpaid redirect orders are cancelled: the return leg
 *    leaves them pending (the Flitt session stays payable after the return).
 *    A session Flitt answers "Order not found" for is dead too.
 *
 * 2. PARKED HOLDS — `approved` rows idle past grace: money with no settled
 *    order (browser-death orphans, double-method holds, superseded mints,
 *    payments for cancelled orders). A matching pending order settles
 *    (missed callback); a settled same-session order is marked settled
 *    (missed observer); an orderless row whose QUOTE still matches gets its
 *    order PLACED server-side (PaidQuotePlacer, the "settle by creating
 *    the order" path); anything else gets its hold REVERSED — fee-free,
 *    since checkout only ever holds the money; full amount, reverse_id
 *    "rec-{increment}".
 *
 * The grace window (payment/shubo_flitt/reconcile_grace) exists because
 * `approved` is ALSO the live paid-but-not-placed resume state: the
 * sweep must never reverse (or place over) a hold a customer is about to
 * resume placing.
 */
class PendingOrderReconciler
{
    /**
     * Per-sweep row cap per run — a backlog drains across runs instead of
     * one run holding locks and hammering the status API.
     */
    private const int BATCH_LIMIT = 50;

    /**
     * reverse_id prefix for reconciler reversals ("rec-{increment_id}" —
     * one full reversal per order, unique within its order_id scope).
     */
    private const string REVERSE_ID_PREFIX = 'rec-';

    /**
     * Flitt error_code "Order not found" (status and reverse requests).
     */
    private const int ERROR_ORDER_NOT_FOUND = 1018;

    /**
     * @param Ledger $ledger
     * @param FlittClient $client
     * @param OrderSettler $orderSettler
     * @param PaidQuotePlacer $paidQuotePlacer
     * @param OrderLock $orderLock
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param Config $config
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly Ledger $ledger,
        private readonly FlittClient $client,
        private readonly OrderSettler $orderSettler,
        private readonly PaidQuotePlacer $paidQuotePlacer,
        private readonly OrderLock $orderLock,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Run both sweeps; every row is isolated — one failure never stops the run.
     *
     * @return void
     */
    public function execute(): void
    {
        $grace = $this->config->getReconcileGrace();
        $now = time();

        // Stale mints: rows must be at least grace old (SQL) AND past their
        // own per-row lifetime + grace (PHP — lifetime varies per row).
        // Declined rows are included: delayed=Y keeps a declined session
        // payable until its lifetime runs out, so only this sweep ends it.
        foreach ($this->ledger->dueRows(
            [Ledger::STATE_MINTED, Ledger::STATE_DECLINED],
            'created_at',
            gmdate('Y-m-d H:i:s', $now - $grace),
            self::BATCH_LIMIT
        ) as $row) {
            if (strtotime((string)$row['created_at']) + (int)$row['lifetime'] + $grace > $now) {
                continue;
            }

            $this->sweepRow($row, $this->handleStaleMint(...));
        }

        foreach ($this->ledger->dueRows(
            Ledger::STATE_APPROVED,
            'updated_at',
            gmdate('Y-m-d H:i:s', $now - $grace),
            self::BATCH_LIMIT
        ) as $row) {
            $this->sweepRow($row, $this->handleParkedHold(...));
        }
    }

    /**
     * Lock, dispatch, log — shared row harness.
     *
     * @param array<string, mixed> $row
     * @param callable $handler
     * @return void
     */
    private function sweepRow(array $row, callable $handler): void
    {
        $incrementId = (string)$row['increment_id'];

        if (!$this->orderLock->acquire($incrementId)) {
            return;
        }

        try {
            $handler($row);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'reconcile: flitt order %s failed: %s',
                (string)$row['flitt_order_id'],
                $e->getMessage()
            ));
        } finally {
            $this->orderLock->release($incrementId);
        }
    }

    /**
     * A mint whose session can no longer be paid (lifetime + grace passed).
     *
     * @param array<string, mixed> $row
     * @return void
     */
    private function handleStaleMint(array $row): void
    {
        $flittOrderId = (string)$row['flitt_order_id'];

        try {
            $status = $this->signedStatus($row);
        } catch (FlittApiException $e) {
            if ($e->getApiErrorCode() === self::ERROR_ORDER_NOT_FOUND) {
                $this->expireUnknownMint($row);
            }

            // Other failures were logged by the client; the row waits for
            // the next run.
            return;
        }

        if ($status === null) {
            return;
        }

        $orderStatus = (string)($status['order_status'] ?? '');
        $order = $this->boundOrder($row);

        if ($orderStatus === 'approved') {
            if ($order !== null && $order->getState() === Order::STATE_PENDING_PAYMENT) {
                $this->settle($order, $flittOrderId);

                return;
            }

            // Paid but nothing to settle onto — park for the hold sweep.
            $this->ledger->transition($flittOrderId, Ledger::STATE_APPROVED);
            $this->logger->info(sprintf('reconcile: stale mint %s is PAID — parked as a hold', $flittOrderId));

            return;
        }

        if (in_array($orderStatus, ['declined', 'expired', 'created', 'processing'], true)) {
            // Dead session (past lifetime, Flitt may still report the last
            // attempt's declined/created/processing under delayed=Y —
            // expired either way; the verbatim order_status column keeps
            // the last attempt's outcome).
            $this->ledger->transition($flittOrderId, Ledger::STATE_EXPIRED);
            $this->cancelStranded($order, $flittOrderId);

            return;
        }

        if ($orderStatus === 'reversed') {
            $this->ledger->transition($flittOrderId, Ledger::STATE_REVERSED);
            $this->cancelStranded($order, $flittOrderId);
        }
    }

    /**
     * A stale mint Flitt answers "Order not found" for. Flitt never created
     * the session under the configured merchant — typically because the
     * store's merchant account was changed (test → live) while sessions of
     * the old account were open — so no money can arrive on it and it would
     * otherwise be polled on every run, forever. Only reached past the
     * session's lifetime + grace, and only for minted/declined rows (never
     * for money the ledger already saw approved).
     *
     * @param array<string, mixed> $row
     * @return void
     */
    private function expireUnknownMint(array $row): void
    {
        $flittOrderId = (string)$row['flitt_order_id'];

        if (!$this->ledger->transition($flittOrderId, Ledger::STATE_EXPIRED)) {
            return;
        }

        $this->logger->warning(sprintf(
            'reconcile: stale mint %s is unknown to Flitt (error %d) — marked expired',
            $flittOrderId,
            self::ERROR_ORDER_NOT_FOUND
        ));
        $this->cancelStranded($this->boundOrder($row), $flittOrderId);
    }

    /**
     * Money with no settled order: settle it home, place its order, or
     * reverse it.
     *
     * @param array<string, mixed> $row
     * @return void
     */
    private function handleParkedHold(array $row): void
    {
        $flittOrderId = (string)$row['flitt_order_id'];
        // By reserved increment id first; a retried placement can get a new
        // increment id, so also look for an order of the same cart that
        // carries this session.
        $order = $this->boundOrder($row) ?? $this->sessionOrderOfQuote($row);
        $payment = $order?->getPayment();
        $sameSession = $payment instanceof Payment
            && (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID) === $flittOrderId;

        if ($order !== null && $sameSession && $order->getState() === Order::STATE_PENDING_PAYMENT) {
            // The callback never landed (or kept failing) — finish its job.
            $this->settle($order, $flittOrderId);

            return;
        }

        if ($order !== null && $sameSession
            && in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE, 'closed'], true)
        ) {
            // Settled sale whose ledger mark was missed.
            $this->ledger->transition($flittOrderId, Ledger::STATE_SETTLED);

            return;
        }

        try {
            $status = $this->signedStatus($row);
        } catch (FlittApiException $e) {
            // Logged by the client; the row waits for the next run. An
            // approved row is money Flitt confirmed — never written off here.
            return;
        }

        if ($status === null) {
            return;
        }

        if ($order === null && $this->placeAbandonedOrder($row, $status)) {
            return;
        }

        // Orphan / double-method / superseded / cancelled-order money —
        // return it to the payer.
        $this->reverse($row, $status);
    }

    /**
     * A paid embed session whose browser died before placement — the
     * order never existed, but the QUOTE may still match the money exactly.
     * Create the order from it (the normal placement pipeline verifies and
     * captures inline) instead of writing the payment off.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $status The verified signed status envelope.
     * @return bool True when the row needs NO further action this run
     *     (placed, or deliberately deferred); false to fall through to the
     *     reversal path.
     */
    private function placeAbandonedOrder(array $row, array $status): bool
    {
        $flittOrderId = (string)$row['flitt_order_id'];

        if ((string)($status['order_status'] ?? '') !== 'approved') {
            // Not (or no longer) money — the reversal path does the
            // bookkeeping on the same envelope.
            return false;
        }

        try {
            $order = $this->paidQuotePlacer->place($row, $status);
        } catch (PaymentInFlightException $e) {
            $this->logger->warning(sprintf(
                'reconcile: parked %s deferred — %s',
                $flittOrderId,
                $e->getMessage()
            ));

            return true;
        } catch (CommandException $e) {
            // Verify/capture hiccup — placement is retry-safe; next run.
            $this->logger->warning(sprintf(
                'reconcile: placing order for parked %s failed transiently: %s',
                $flittOrderId,
                $e->getMessage()
            ));

            return true;
        } catch (LocalizedException $e) {
            // Deterministic submit failure (validation, stock): the quote
            // will never place — return the money.
            $this->logger->error(sprintf(
                'reconcile: quote for parked %s cannot be placed (%s) — reversing the hold',
                $flittOrderId,
                $e->getMessage()
            ));

            return false;
        }

        if ($order === null) {
            // No quote / superseded session / money mismatch — reversal owns it.
            return false;
        }

        // checkout_submit_all_after only fires in placeOrder(), not
        // submit() — mark the ledger here.
        $this->ledger->transition($flittOrderId, Ledger::STATE_SETTLED);
        $this->logger->info(sprintf(
            'reconcile: order %s created for abandoned paid session %s',
            (string)$order->getIncrementId(),
            $flittOrderId
        ));

        return true;
    }

    /**
     * Reverse a parked payment in full, on signed-status truth.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $status The verified signed status envelope.
     * @return void
     */
    private function reverse(array $row, array $status): void
    {
        $flittOrderId = (string)$row['flitt_order_id'];
        $orderStatus = (string)($status['order_status'] ?? '');
        $actualMinor = (int)($status['actual_amount'] ?? ($status['amount'] ?? 0));
        $reversedMinor = (int)($status['reversal_amount'] ?? 0);

        if ($orderStatus === 'reversed' || ($actualMinor > 0 && $reversedMinor >= $actualMinor)) {
            $this->ledger->transition($flittOrderId, Ledger::STATE_REVERSED);

            return;
        }

        if ($orderStatus !== 'approved' || $actualMinor <= 0) {
            $this->logger->warning(sprintf(
                'reconcile: parked row %s is "%s" at Flitt — left for the next run',
                $flittOrderId,
                $orderStatus
            ));

            return;
        }

        $response = $this->client->reverseOrder(
            $flittOrderId,
            $actualMinor,
            (string)($status['currency'] ?? ''),
            self::REVERSE_ID_PREFIX . (string)$row['increment_id'],
            (int)$row['store_id']
        );

        if ((string)($response['reverse_status'] ?? '') !== 'approved') {
            $this->logger->error(sprintf(
                'reconcile: reverse of parked %s not approved (reverse_status "%s") — will retry',
                $flittOrderId,
                (string)($response['reverse_status'] ?? '')
            ));

            return;
        }

        $this->ledger->transition($flittOrderId, Ledger::STATE_REVERSED);
        $this->logger->info(sprintf(
            'reconcile: parked payment %s reversed in full (%d %s)',
            $flittOrderId,
            $actualMinor,
            (string)($status['currency'] ?? '')
        ));
    }

    /**
     * Settle a pending order (missed/failed callback path) + mark the ledger.
     *
     * @param Order $order
     * @param string $flittOrderId
     * @return void
     */
    private function settle(Order $order, string $flittOrderId): void
    {
        $this->orderSettler->settle($order, $flittOrderId);
        $this->ledger->transition($flittOrderId, Ledger::STATE_SETTLED);
        $this->logger->info(sprintf(
            'reconcile: order %s settled from the sweep (flitt order %s)',
            (string)$order->getIncrementId(),
            $flittOrderId
        ));
    }

    /**
     * Cancel a stranded pending_payment order bound to a dead session.
     *
     * @param Order|null $order
     * @param string $flittOrderId
     * @return void
     */
    private function cancelStranded(?Order $order, string $flittOrderId): void
    {
        if ($order === null
            || $order->getState() !== Order::STATE_PENDING_PAYMENT
            || !$order->canCancel()
        ) {
            return;
        }

        $payment = $order->getPayment();

        if (!$payment instanceof Payment
            || (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID) !== $flittOrderId
        ) {
            // Bound to a different (possibly still-live) session — not ours to cancel.
            return;
        }

        $order->addCommentToStatusHistory(
            (string)__('Cancelled by the Flitt reconciler: the payment session expired unpaid.')
        );
        $order->cancel();
        $this->orderRepository->save($order);

        $this->logger->info(sprintf(
            'reconcile: stranded order %s cancelled (flitt order %s expired unpaid)',
            (string)$order->getIncrementId(),
            $flittOrderId
        ));
    }

    /**
     * The row's signed status envelope, null when it answers for another
     * order. API failures are already logged by the client; callers decide
     * what a failure means for their row.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     * @throws FlittApiException
     */
    private function signedStatus(array $row): ?array
    {
        $flittOrderId = (string)$row['flitt_order_id'];
        $status = $this->client->getOrderStatus($flittOrderId, (int)$row['store_id']);

        if ((string)($status['order_id'] ?? '') !== $flittOrderId) {
            $this->logger->error(sprintf(
                'reconcile: status envelope for %s answered for "%s" — skipping',
                $flittOrderId,
                (string)($status['order_id'] ?? '')
            ));

            return null;
        }

        return $status;
    }

    /**
     * The order of the row's cart that carries this Flitt session.
     *
     * Found whatever its increment id (a retry can get a new one).
     *
     * @param array<string, mixed> $row
     * @return Order|null
     */
    private function sessionOrderOfQuote(array $row): ?Order
    {
        $quoteId = (int)($row['quote_id'] ?? 0);

        if ($quoteId === 0) {
            return null;
        }

        $orders = $this->orderRepository->getList(
            $this->searchCriteriaBuilder->addFilter('quote_id', $quoteId)->create()
        )->getItems();

        foreach ($orders as $order) {
            $payment = $order->getPayment();

            if ($order instanceof Order
                && $payment instanceof Payment
                && $payment->getMethod() === ConfigProvider::CODE
                && (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID)
                    === (string)$row['flitt_order_id']
            ) {
                return $order;
            }
        }

        return null;
    }

    /**
     * The Magento order a ledger row points at, freshly loaded (under the
     * row's lock), or null. Non-Flitt orders are treated as unbound.
     *
     * @param array<string, mixed> $row
     * @return Order|null
     */
    private function boundOrder(array $row): ?Order
    {
        $incrementId = (string)$row['increment_id'];

        if ($incrementId === '') {
            return null;
        }

        $orders = $this->orderRepository->getList(
            $this->searchCriteriaBuilder
                ->addFilter('increment_id', $incrementId)
                ->setPageSize(1)
                ->create()
        )->getItems();
        $order = array_shift($orders);

        if (!$order instanceof Order) {
            return null;
        }

        $payment = $order->getPayment();

        return $payment instanceof Payment && $payment->getMethod() === ConfigProvider::CODE ? $order : null;
    }
}
