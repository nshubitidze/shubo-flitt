<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Checkout;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Exception\FlittApiException;
use Shubo\Flitt\Exception\PaymentSessionMismatchException;
use Shubo\Flitt\Gateway\Config;
use Shubo\Flitt\Gateway\Signature;
use Shubo\Flitt\Model\Callback\OrderSettler;
use Shubo\Flitt\Model\FlittClient;
use Shubo\Flitt\Model\Ledger;
use Shubo\Flitt\Model\OrderLock;
use Shubo\Flitt\Model\Ui\ConfigProvider;

/**
 * Resolves a customer's browser return from the Flitt hosted page —
 * the money brain behind Controller/Redirect/Complete.
 *
 * Identification, in trust order (the SameSite=Lax session
 * cookie is absent on a cross-site POST, so the session is a FALLBACK,
 * never a requirement):
 *  1. a SIGNED return payload (Flitt posts the same envelope as the server
 *     callback to response_url) — order_id → ledger row → store secret →
 *     signature, the callback Processor's exact trust chain. The signature
 *     doubles as the bearer credential: only the payer's browser carried it,
 *     so it may seed a fresh session (Payflow's stored-hash pattern);
 *  2. the checkout session's own last real order (cookie survived);
 *  3. neither → Unknown, and nothing is disclosed.
 *
 * The verdict never trusts the browser's CLAIM of the outcome: the order's
 * fresh state (under OrderLock) and the SIGNED /status envelope decide.
 * An approved-but-unsettled return settles via the same OrderSettler the
 * callback uses. A not-paid return frees the CART but leaves the order
 * PENDING: with delayed=Y the Flitt session stays payable after
 * the customer walks away (cancel click, back button, "unpaid order"
 * email), so the order must stay settleable for exactly as long — the
 * reconciler cancels it once the session's lifetime truly ends. Only a
 * terminally dead session (expired / reversed / never minted) cancels
 * here. A payment landing on an already-CANCELED order (the residual
 * race: core's sales_clean_orders cron or the reconciler got there first)
 * is reported honestly as LatePaid — the money parks via the callback
 * matrix and the reconciler reverses it.
 */
class ReturnHandler
{
    /**
     * Flitt statuses meaning "no completed payment right now, but the
     * session (delayed=Y) can still be paid": a failed attempt stays
     * `processing`/`declined` until lifetime expiry. Free the cart, keep
     * the order pending.
     */
    private const array RETRYABLE_STATUSES = ['created', 'processing', 'declined'];

    /**
     * @param Ledger $ledger
     * @param Config $config
     * @param Signature $signature
     * @param FlittClient $client
     * @param OrderSettler $orderSettler
     * @param OrderLock $orderLock
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly Ledger $ledger,
        private readonly Config $config,
        private readonly Signature $signature,
        private readonly FlittClient $client,
        private readonly OrderSettler $orderSettler,
        private readonly OrderLock $orderLock,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Resolve a browser return into a disposition + order facts.
     *
     * @param array<string, mixed> $params All request params (GET or form POST).
     * @param string $sessionIncrementId The session's own Flitt order ('' when none).
     * @return ReturnResult
     */
    public function handle(array $params, string $sessionIncrementId): ReturnResult
    {
        [$authenticatedFlittId, $row] = $this->authenticate($params);

        $incrementId = $row !== null ? (string)$row['increment_id'] : $sessionIncrementId;

        if ($incrementId === '') {
            $this->logger->info('return: unidentifiable browser return (no signed payload, no session order)');

            return new ReturnResult(ReturnDisposition::Unknown);
        }

        if (!$this->orderLock->acquire($incrementId)) {
            // A callback settle (or another return hit) owns the order right
            // now — whatever it decides, the customer can check in a moment.
            return new ReturnResult(ReturnDisposition::Processing, $incrementId);
        }

        try {
            return $this->resolve($incrementId, $authenticatedFlittId);
        } finally {
            $this->orderLock->release($incrementId);
        }
    }

    /**
     * Verify a signed return payload; [flitt_order_id, ledger row] or [null, null].
     *
     * Never an error path — an unverifiable payload just demotes the return
     * to session-based identification.
     *
     * @param array<string, mixed> $params
     * @return array{0: ?string, 1: ?array<string, mixed>}
     */
    private function authenticate(array $params): array
    {
        $flittOrderId = isset($params['order_id']) && is_scalar($params['order_id'])
            ? (string)$params['order_id']
            : '';

        if ($flittOrderId === '') {
            return [null, null];
        }

        $row = $this->ledger->find($flittOrderId);

        if ($row === null) {
            $this->logger->info(sprintf('return: payload for unknown flitt order %s ignored', $flittOrderId));

            return [null, null];
        }

        $secretKey = $this->config->getSecretKey((int)$row['store_id']);

        if ($secretKey === '' || !$this->signature->verify($params, $secretKey)) {
            $this->logger->warning(sprintf(
                'return: payload signature for flitt order %s did NOT verify — payload ignored',
                $flittOrderId
            ));

            return [null, null];
        }

        return [$flittOrderId, $row];
    }

    /**
     * Decide under the order lock, on fresh state + the signed status API.
     *
     * @param string $incrementId
     * @param string|null $authenticatedFlittId Signature-verified session id, if any.
     * @return ReturnResult
     */
    private function resolve(string $incrementId, ?string $authenticatedFlittId): ReturnResult
    {
        $order = $this->findOrder($incrementId);
        $payment = $order?->getPayment();

        if ($order === null || !$payment instanceof Payment || $payment->getMethod() !== ConfigProvider::CODE) {
            return new ReturnResult(ReturnDisposition::Unknown, $incrementId);
        }

        $result = new ReturnResult(
            ReturnDisposition::Processing,
            $incrementId,
            (int)$order->getId(),
            (int)$order->getQuoteId() ?: null
        );
        $boundFlittId = (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID);

        if ($authenticatedFlittId !== null && $authenticatedFlittId !== $boundFlittId) {
            // The payer's session was superseded (re-mint, double method):
            // their money is parked for the reconciler — never show success
            // for an order another session settled.
            $this->logger->warning(sprintf(
                'return: order %s is bound to %s but the return is for %s — parked money, neutral outcome',
                $incrementId,
                $boundFlittId,
                $authenticatedFlittId
            ));

            return $result;
        }

        $state = (string)$order->getState();

        if (in_array($state, [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true)) {
            return $this->success($result);
        }

        if ($state === Order::STATE_CANCELED) {
            // Usually a failed handoff or an expiry-cancelled order — give
            // the cart back. But if the session was PAID after the cancel
            // (Flitt's "unpaid order" email outlives our cancel),
            // say so honestly — the reconciler auto-reverses the money.
            if ($this->sessionWasPaid($boundFlittId, (int)$order->getStoreId())) {
                return new ReturnResult(
                    ReturnDisposition::LatePaid,
                    $result->incrementId,
                    $result->orderId,
                    $result->quoteId
                );
            }

            return $this->failed($result);
        }

        if ($state !== Order::STATE_PENDING_PAYMENT) {
            return $result;
        }

        return $this->resolvePending($order, $boundFlittId, $result);
    }

    /**
     * A pending order: the signed /status envelope is the only truth.
     *
     * @param Order $order
     * @param string $flittOrderId
     * @param ReturnResult $facts Processing-flavored result carrying the order facts.
     * @return ReturnResult
     */
    private function resolvePending(Order $order, string $flittOrderId, ReturnResult $facts): ReturnResult
    {
        $incrementId = $facts->incrementId;

        if ($flittOrderId === '') {
            // Pending order with no payment session at all — nothing was
            // ever payable; free the cart.
            $this->cancelUnpaid($order, 'no payment session');

            return $this->failed($facts);
        }

        try {
            $status = $this->client->getOrderStatus($flittOrderId, (int)$order->getStoreId());
        } catch (FlittApiException $e) {
            // Can't decide — leave the order pending; the callback or the
            // reconciler settles it.
            return $facts;
        }

        if ((string)($status['order_id'] ?? '') !== $flittOrderId) {
            $this->logger->error(sprintf(
                'return: status envelope for %s answered for "%s" — refusing to decide (order %s)',
                $flittOrderId,
                (string)($status['order_id'] ?? ''),
                $incrementId
            ));

            return $facts;
        }

        $orderStatus = (string)($status['order_status'] ?? '');

        if ($orderStatus === 'approved') {
            try {
                $this->orderSettler->settle($order, $flittOrderId);
            } catch (PaymentSessionMismatchException $e) {
                $this->ledger->transition($flittOrderId, Ledger::STATE_APPROVED);

                return $facts;
            } catch (CommandException | FlittApiException $e) {
                // Transient — settle is idempotent; the callback/reconciler
                // finishes the job.
                return $facts;
            }

            $this->ledger->transition($flittOrderId, Ledger::STATE_SETTLED);
            $this->logger->info(sprintf(
                'return: order %s settled on the customer\'s return (flitt order %s)',
                $incrementId,
                $flittOrderId
            ));

            return $this->success($facts);
        }

        if (in_array($orderStatus, self::RETRYABLE_STATUSES, true)) {
            // The session can still be paid (delayed=Y — Flitt's "unpaid
            // order" email points at it): free the cart, keep the order
            // pending and settleable. The reconciler cancels it at lifetime
            // expiry if the payment never comes.
            $this->logger->info(sprintf(
                'return: order %s returned unpaid (Flitt status "%s") — cart freed, order left pending',
                $incrementId,
                $orderStatus
            ));

            return $this->failed($facts);
        }

        if ($orderStatus === 'expired') {
            // Terminally dead session — nothing can pay this order anymore.
            $this->ledger->transition($flittOrderId, Ledger::STATE_EXPIRED);
            $this->cancelUnpaid($order, 'the payment session expired unpaid');

            return $this->failed($facts);
        }

        if ($orderStatus === 'reversed') {
            $this->ledger->transition($flittOrderId, Ledger::STATE_REVERSED);
            $this->cancelUnpaid($order, 'payment was reversed');

            return $this->failed($facts);
        }

        return $facts;
    }

    /**
     * Whether a canceled order's payment session was actually PAID — the
     * signed /status envelope first, the ledger's parked state as the
     * offline fallback.
     *
     * @param string $flittOrderId '' when the order never had a session.
     * @param int $storeId
     * @return bool
     */
    private function sessionWasPaid(string $flittOrderId, int $storeId): bool
    {
        if ($flittOrderId === '') {
            return false;
        }

        try {
            $status = $this->client->getOrderStatus($flittOrderId, $storeId);

            if ((string)($status['order_id'] ?? '') === $flittOrderId) {
                if ((string)($status['order_status'] ?? '') !== 'approved') {
                    return false;
                }

                // Park it now — the reconciler's hold sweep reverses it
                // without waiting for the callback to land first.
                $this->ledger->transition($flittOrderId, Ledger::STATE_APPROVED);

                return true;
            }
        } catch (FlittApiException) {
            // Fall through to the ledger's last known word.
        }

        $row = $this->ledger->find($flittOrderId);

        return $row !== null && (string)$row['state'] === Ledger::STATE_APPROVED;
    }

    /**
     * Cancel an unpaid pending order (best-effort, offline — no auth txn
     * exists, so the gateway is never called) so the cart can be restored.
     * A LATE payment on its session parks via the callback matrix and the
     * reconciler reverses the hold.
     *
     * @param Order $order
     * @param string $reason
     * @return void
     */
    private function cancelUnpaid(Order $order, string $reason): void
    {
        try {
            if (!$order->canCancel()) {
                return;
            }

            $order->addCommentToStatusHistory(
                (string)__('Cancelled on the customer\'s return from the Flitt payment page (%1).', $reason)
            );
            $order->cancel();
            $this->orderRepository->save($order);

            $this->logger->info(sprintf(
                'return: order %s cancelled (%s)',
                (string)$order->getIncrementId(),
                $reason
            ));
        } catch (\Throwable $e) {
            // Best-effort: a failed cancel leaves a pending order for the
            // reconciler; never break the customer's return over it.
            $this->logger->error(sprintf(
                'return: cancelling order %s failed: %s',
                (string)$order->getIncrementId(),
                $e->getMessage()
            ));
        }
    }

    /**
     * @param ReturnResult $facts
     * @return ReturnResult
     */
    private function success(ReturnResult $facts): ReturnResult
    {
        return new ReturnResult(ReturnDisposition::Success, $facts->incrementId, $facts->orderId, $facts->quoteId);
    }

    /**
     * @param ReturnResult $facts
     * @return ReturnResult
     */
    private function failed(ReturnResult $facts): ReturnResult
    {
        return new ReturnResult(ReturnDisposition::Failed, $facts->incrementId, $facts->orderId, $facts->quoteId);
    }

    /**
     * Fresh order load by increment id (called under the order's lock).
     *
     * @param string $incrementId
     * @return Order|null
     */
    private function findOrder(string $incrementId): ?Order
    {
        $orders = $this->orderRepository->getList(
            $this->searchCriteriaBuilder
                ->addFilter('increment_id', $incrementId)
                ->setPageSize(1)
                ->create()
        )->getItems();

        $order = array_shift($orders);

        return $order instanceof Order ? $order : null;
    }
}
