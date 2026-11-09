<?php

declare(strict_types=1);

namespace Shubo\Flitt\Gateway\Command;

use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Exception\FlittApiException;
use Shubo\Flitt\Model\Checkout\HoldCapture;
use Shubo\Flitt\Model\Checkout\TokenManager;
use Shubo\Flitt\Model\FlittClient;
use Shubo\Flitt\Model\Ledger;
use Shubo\Flitt\Model\OrderLock;

/**
 * Pool keys "void" and "cancel": release an open Flitt hold (full reverse —
 * instant and fee-free while uncaptured). Magento routes here only when the
 * payment has an OPEN authorization transaction (canVoid), i.e. Authorize
 * Only orders before capture.
 *
 * A hold that is already dead at Flitt (expired, reversed, never approved)
 * is a graceful no-op — the admin must still be able to void/cancel the
 * Magento order. A payment that turns out CAPTURED at Flitt (e.g. via the
 * portal) is refused: that money needs a credit memo, not a void.
 */
class VoidCommand implements CommandInterface
{
    /**
     * reverse_id for the one full void reversal an order can ever have.
     */
    private const string REVERSE_ID_PREFIX = 'void-';

    /**
     * @param FlittClient $client
     * @param Ledger $ledger
     * @param OrderLock $orderLock
     * @param HoldCapture $holdCapture Shared capture-state parser only.
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly FlittClient $client,
        private readonly Ledger $ledger,
        private readonly OrderLock $orderLock,
        private readonly HoldCapture $holdCapture,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Release the payment's hold at Flitt, tolerating already-dead holds.
     *
     * @param array<string, mixed> $commandSubject
     * @return null
     * @throws CommandException
     */
    public function execute(array $commandSubject)
    {
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();

        if (!$payment instanceof Payment) {
            throw new CommandException(__('Flitt void requires an order payment.'));
        }

        $order = $payment->getOrder();
        $incrementId = (string)$order->getIncrementId();
        $flittOrderId = (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID);

        if ($flittOrderId === '') {
            // An auth transaction without a cached Flitt session should not
            // exist; there is nothing to release, so don't block the admin.
            $this->logger->warning(sprintf(
                'void: order %s has an authorization but no cached flitt_order_id — nothing released',
                $incrementId
            ));

            return null;
        }

        if (!$this->orderLock->acquire($incrementId)) {
            throw new CommandException(
                __('This order is being processed by another payment operation. Please try again.')
            );
        }

        try {
            $reversedNow = $this->release($flittOrderId, $incrementId, (int)$order->getStoreId());
        } finally {
            $this->orderLock->release($incrementId);
        }

        if ($reversedNow > 0) {
            $payment->setAdditionalInformation(
                RefundCommand::INFO_REVERSED_MINOR,
                (int)$payment->getAdditionalInformation(RefundCommand::INFO_REVERSED_MINOR) + $reversedNow
            );
        }

        $paymentId = (string)$payment->getAdditionalInformation(AbstractVerifyCommand::INFO_PAYMENT_ID);
        $payment->setTransactionId(($paymentId !== '' ? $paymentId : $flittOrderId) . '-void');
        $payment->setIsTransactionClosed(true);
        $payment->setShouldCloseParentTransaction(true);

        return null;
    }

    /**
     * Reverse the live hold, or accept a hold that is already dead.
     *
     * @param string $flittOrderId
     * @param string $incrementId
     * @param int $storeId
     * @return int Minor units reversed by THIS call (0 for the no-op cases).
     * @throws CommandException
     */
    private function release(string $flittOrderId, string $incrementId, int $storeId): int
    {
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

        $orderStatus = (string)($status['order_status'] ?? '');
        $actualMinor = (int)($status['actual_amount'] ?? ($status['amount'] ?? 0));
        $reversedMinor = (int)($status['reversal_amount'] ?? 0);

        if ($orderStatus === 'reversed' || ($actualMinor > 0 && $reversedMinor >= $actualMinor)) {
            $this->logger->info(sprintf(
                'void: flitt order %s already fully reversed — nothing to release (order %s)',
                $flittOrderId,
                $incrementId
            ));
            $this->ledger->transition($flittOrderId, Ledger::STATE_REVERSED);

            return 0;
        }

        if (in_array($orderStatus, ['expired', 'declined', 'created', 'processing'], true)) {
            // The hold lapsed or never materialized — no live money at Flitt.
            $this->logger->info(sprintf(
                'void: flitt order %s is "%s" — no live hold to release (order %s)',
                $flittOrderId,
                $orderStatus,
                $incrementId
            ));

            return 0;
        }

        if ($orderStatus !== 'approved') {
            throw new CommandException(__(
                'The Flitt payment is in an unexpected state ("%1"). Check it in the Flitt portal.',
                $orderStatus
            ));
        }

        if ($this->holdCapture->capturedAmount($status) > 0) {
            throw new CommandException(
                __('This payment was already captured at Flitt — refund it with a credit memo instead of voiding.')
            );
        }

        if ($this->holdCapture->isOneStageCharge($status)) {
            // A one-stage wallet charge — this "void" refunds taken money
            // rather than releasing a hold. Same net effect for the
            // customer; worth an explicit trail for reconciliation.
            $this->logger->info(sprintf(
                'void: flitt order %s was a one-stage "%s" charge — reversing means a full REFUND (order %s)',
                $flittOrderId,
                $this->holdCapture->chargeMethod($status),
                $incrementId
            ));
        }

        try {
            $response = $this->client->reverseOrder(
                $flittOrderId,
                $actualMinor,
                (string)($status['currency'] ?? ''),
                self::REVERSE_ID_PREFIX . $incrementId,
                $storeId
            );
        } catch (FlittApiException $e) {
            // FlittClient already logged the detail (error code, request_id).
            throw new CommandException(__('Flitt did not accept the void: %1', $e->getMessage()));
        }

        $reverseStatus = (string)($response['reverse_status'] ?? '');

        if ($reverseStatus !== 'approved') {
            $this->logger->error(sprintf(
                'void: flitt order %s reverse not approved (reverse_status "%s")',
                $flittOrderId,
                $reverseStatus
            ));

            throw new CommandException(__(
                'Flitt did not approve the void (status "%1"). Check the payment in the Flitt portal.',
                $reverseStatus
            ));
        }

        $this->ledger->transition($flittOrderId, Ledger::STATE_REVERSED);

        $this->logger->info(sprintf(
            'void: order %s released its hold of %d %s via flitt order %s',
            $incrementId,
            $actualMinor,
            (string)($status['currency'] ?? ''),
            $flittOrderId
        ));

        return $actualMinor;
    }
}
