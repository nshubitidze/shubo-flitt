<?php

declare(strict_types=1);

namespace Shubo\Flitt\Gateway\Command;

use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Exception\FlittApiException;
use Shubo\Flitt\Model\Checkout\TokenManager;
use Shubo\Flitt\Model\FlittClient;
use Shubo\Flitt\Model\Ledger;
use Shubo\Flitt\Model\Money;
use Shubo\Flitt\Model\OrderLock;

/**
 * Pool key "refund": online credit memo → Flitt reverse. Flitt allows
 * multiple partial reverses up to the charged amount; each credit memo is
 * one logical reversal, keyed by a deterministic reverse_id derived from the
 * PRE-refund state ("cm{amount}-r{already_reversed}") — the credit memo has
 * no increment id yet while this command runs (it is assigned at save).
 *
 * Live-verified 1126 semantics: Flitt REJECTS a reverse_id that already
 * succeeded ("Reverse with the same reverse_id already exists"), so a retry
 * of a refund whose gateway leg succeeded but whose credit memo failed to
 * save reuses the same key and is safely refused instead of double-refunded
 * (the reversal-total guard below catches the same case earlier).
 *
 * The reversal-total guard compares Flitt's cumulative reversal_amount to
 * the module's OWN counter (INFO_REVERSED_MINOR) — NOT to Magento's
 * amount_refunded, which offline credit memos inflate without any money
 * moving at Flitt.
 *
 * Amounts move in ORDER currency (what Flitt charged); Magento hands the
 * command the BASE amount, which is only sanity-checked.
 */
class RefundCommand implements CommandInterface
{
    /**
     * Cumulative minor units this module has reversed at Flitt for the
     * payment (order currency). Written here and by VoidCommand.
     */
    public const string INFO_REVERSED_MINOR = 'flitt_reversed_minor';

    /**
     * @param FlittClient $client
     * @param Ledger $ledger
     * @param OrderLock $orderLock
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly FlittClient $client,
        private readonly Ledger $ledger,
        private readonly OrderLock $orderLock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Reverse the credit memo's amount at Flitt.
     *
     * @param array<string, mixed> $commandSubject
     * @return null
     * @throws CommandException
     */
    public function execute(array $commandSubject)
    {
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();
        $baseAmount = (float)SubjectReader::readAmount($commandSubject);

        if (!$payment instanceof Payment) {
            throw new CommandException(__('Flitt refund requires an order payment.'));
        }

        $order = $payment->getOrder();
        $creditmemo = $payment->getCreditmemo();

        if ($creditmemo === null) {
            throw new CommandException(__('Flitt refund requires a credit memo.'));
        }

        $flittOrderId = (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID);

        if ($flittOrderId === '') {
            throw new CommandException(
                __('This payment has no Flitt session to refund — create an offline credit memo instead.')
            );
        }

        if (abs($baseAmount - (float)$creditmemo->getBaseGrandTotal()) > 0.004) {
            // The framework always refunds the credit memo's base grand
            // total; anything else is a flow this command was not built for.
            throw new CommandException(__('The refund amount does not match the credit memo.'));
        }

        $amountMinor = Money::toMinorUnits($creditmemo->getGrandTotal());

        if ($amountMinor <= 0) {
            throw new CommandException(__('The refund amount must be greater than zero.'));
        }

        $storeId = (int)$order->getStoreId();
        $currency = (string)$order->getOrderCurrencyCode();
        $incrementId = (string)$order->getIncrementId();

        if (!$this->orderLock->acquire($incrementId)) {
            throw new CommandException(
                __('This order is being processed by another payment operation. Please try again.')
            );
        }

        try {
            [$reverseId, $preReversedMinor] = $this->reverse($payment, $flittOrderId, $amountMinor, $currency, $storeId);
        } finally {
            $this->orderLock->release($incrementId);
        }

        // Live-verified: the reverse response's reversal_amount is only THIS
        // reversal (the cumulative total lives in the status envelope), so
        // the new total is pre-state + this refund — both already validated.
        $totalReversedMinor = $preReversedMinor + $amountMinor;
        $chargedMinor = Money::toMinorUnits($order->getGrandTotal());

        $payment->setAdditionalInformation(self::INFO_REVERSED_MINOR, $totalReversedMinor);

        if ($totalReversedMinor >= $chargedMinor) {
            // Fully returned — best-effort; Flitt's own "reversed" callback
            // confirms it in production either way.
            $this->ledger->transition($flittOrderId, Ledger::STATE_REVERSED);
        }

        $paymentId = (string)$payment->getAdditionalInformation(AbstractVerifyCommand::INFO_PAYMENT_ID);
        $payment->setTransactionId(
            ($paymentId !== '' ? $paymentId : $flittOrderId) . '-refund-' . $reverseId
        );
        $payment->setIsTransactionClosed(true);

        $this->logger->info(sprintf(
            'refund: order %s reversed %d %s via flitt order %s (reverse_id %s, total reversed %d)',
            $incrementId,
            $amountMinor,
            $currency,
            $flittOrderId,
            $reverseId,
            $totalReversedMinor
        ));

        return null;
    }

    /**
     * Guard against unrecorded prior reverses, then reverse at Flitt.
     *
     * @param Payment $payment
     * @param string $flittOrderId
     * @param int $amountMinor
     * @param string $currency
     * @param int $storeId
     * @return array{0: string, 1: int} The reverse_id used + Flitt's pre-refund reversed total (minor units).
     * @throws CommandException
     */
    private function reverse(
        Payment $payment,
        string $flittOrderId,
        int $amountMinor,
        string $currency,
        int $storeId,
    ): array {
        try {
            $status = $this->client->getOrderStatus($flittOrderId, $storeId);
        } catch (FlittApiException $e) {
            throw new CommandException(
                __('The payment state could not be verified with Flitt right now. Please try again.')
            );
        }

        if ((string)($status['order_id'] ?? '') !== $flittOrderId) {
            throw new CommandException(__('The Flitt payment session does not match this order.'));
        }

        if ((string)($status['currency'] ?? '') !== $currency) {
            throw new CommandException(__('The Flitt payment currency does not match this order.'));
        }

        // What Flitt has already returned must equal what THIS MODULE has
        // recorded (offline credit memos inflate amount_refunded without
        // touching Flitt, so Magento's totals are unusable here) — a
        // mismatch means an unrecorded reverse, e.g. a refund whose credit
        // memo failed to save. A human must reconcile before more money moves.
        $alreadyReversedMinor = (int)($status['reversal_amount'] ?? 0);
        $recordedMinor = (int)$payment->getAdditionalInformation(self::INFO_REVERSED_MINOR);

        if ($alreadyReversedMinor !== $recordedMinor) {
            $this->logger->error(sprintf(
                'refund: flitt order %s reversal ledger mismatch — Flitt reports %d %s reversed,'
                . ' this module recorded %d. Refund blocked for manual reconciliation.',
                $flittOrderId,
                $alreadyReversedMinor,
                $currency,
                $recordedMinor
            ));

            throw new CommandException(__(
                'Flitt reports a different refunded total than this store recorded (%1 vs %2 minor units).'
                . ' Reconcile the payment in the Flitt portal before refunding more.',
                $alreadyReversedMinor,
                $recordedMinor
            ));
        }

        // Deterministic per logical reversal: the pre-refund reversed total
        // disambiguates repeat refunds of the same amount, while a retry of
        // an UNRECORDED refund reproduces the same key (see class doc).
        $reverseId = sprintf('cm%d-r%d', $amountMinor, $alreadyReversedMinor);

        try {
            $response = $this->client->reverseOrder(
                $flittOrderId,
                $amountMinor,
                $currency,
                $reverseId,
                $storeId
            );
        } catch (FlittApiException $e) {
            // FlittClient already logged the detail (error code, request_id).
            throw new CommandException(
                __('Flitt did not accept the refund: %1', $e->getMessage())
            );
        }

        $reverseStatus = (string)($response['reverse_status'] ?? '');

        if ($reverseStatus !== 'approved') {
            $this->logger->error(sprintf(
                'refund: flitt order %s reverse not approved (reverse_status "%s")',
                $flittOrderId,
                $reverseStatus
            ));

            throw new CommandException(__(
                'Flitt did not approve the refund (status "%1"). Check the payment in the Flitt portal.',
                $reverseStatus
            ));
        }

        return [$reverseId, $alreadyReversedMinor];
    }
}
