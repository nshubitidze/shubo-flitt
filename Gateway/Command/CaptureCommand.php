<?php

declare(strict_types=1);

namespace Shubo\Flitt\Gateway\Command;

use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Model\Checkout\HoldCapture;
use Shubo\Flitt\Model\Checkout\PaymentVerifier;
use Shubo\Flitt\Model\Checkout\TokenManager;
use Shubo\Flitt\Model\OrderLock;

/**
 * Merchant-driven capture of an open Flitt hold: admin Invoice → Capture
 * Online on an Authorize Only order lands here via CaptureStrategyCommand.
 *
 * Same trust chain as placement: the hold is re-proven against the SIGNED
 * status envelope (PaymentVerifier) and the capture is confirmed via the
 * signed envelope too (HoldCapture) — the synchronous capture response
 * stays advisory.
 */
class CaptureCommand implements CommandInterface
{
    /**
     * @param PaymentVerifier $verifier
     * @param HoldCapture $holdCapture
     * @param OrderLock $orderLock
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly PaymentVerifier $verifier,
        private readonly HoldCapture $holdCapture,
        private readonly OrderLock $orderLock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Capture the full held amount for the invoice being paid.
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
            throw new CommandException(__('Flitt capture requires an order payment.'));
        }

        $order = $payment->getOrder();

        // Full capture only: Flitt permits a SINGLE capture per hold and a
        // partial capture auto-returns the remainder, while Magento would
        // keep showing the remainder as capturable — irreconcilable, so a
        // partial invoice must be captured offline or not at all.
        if (abs($baseAmount - (float)$order->getBaseGrandTotal()) > 0.004) {
            throw new CommandException(
                __('Flitt supports a single full capture — please invoice the full order amount.')
            );
        }

        $incrementId = (string)$order->getIncrementId();

        if (!$this->orderLock->acquire($incrementId)) {
            throw new CommandException(
                __('This order is being processed by another payment operation. Please try again.')
            );
        }

        try {
            $status = $this->verifier->verify($payment, 'Y');

            // Admin-leg race fence: a hold
            // that is ALREADY captured means a concurrent/duplicate Capture
            // Online won (its capture committed under this same lock before
            // release) or someone captured out-of-band — recording a second
            // invoice would double-book the order either way. The check is
            // Flitt-side state under the lock, so it is immune to Magento's
            // post-command save timing. (Placement retries keep their
            // idempotent already-captured path in HoldCapture — that leg
            // never runs twice for one order.)
            if ($this->holdCapture->capturedAmount($status) > 0) {
                throw new CommandException(__(
                    'This payment is already captured at Flitt. If the invoice is missing in Magento,'
                    . ' reconcile it manually instead of capturing again.'
                ));
            }

            $this->holdCapture->ensureCaptured(
                (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID),
                $status,
                (int)$order->getStoreId()
            );
        } finally {
            $this->orderLock->release($incrementId);
        }

        $paymentId = (string)($status['payment_id'] ?? '');

        if ($paymentId === '') {
            $paymentId = (string)$payment->getAdditionalInformation(AbstractVerifyCommand::INFO_PAYMENT_ID);
        }

        $payment->setTransactionId($paymentId . '-capture');
        $payment->setIsTransactionClosed(true);
        // The hold is fully consumed by its single capture.
        $payment->setShouldCloseParentTransaction(true);

        $this->logger->info(sprintf(
            'admin capture: order %s captured its hold via flitt order %s (payment_id %s)',
            $incrementId,
            (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID),
            $paymentId
        ));

        return null;
    }
}
