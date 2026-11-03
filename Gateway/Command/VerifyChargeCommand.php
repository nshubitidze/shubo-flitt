<?php

declare(strict_types=1);

namespace Shubo\Flitt\Gateway\Command;

use Magento\Payment\Gateway\Command\CommandException;
use Magento\Sales\Model\Order\Payment;
use Shubo\Flitt\Model\Checkout\HoldCapture;
use Shubo\Flitt\Model\Checkout\PaymentVerifier;
use Shubo\Flitt\Model\Checkout\TokenManager;
use Shubo\Flitt\Model\Vault\TokenPersister;

/**
 * Placement leg of pool key "capture" (payment_action = authorize_capture),
 * reached via CaptureStrategyCommand when no authorization transaction
 * exists yet: the embed only HELD the amount (preauth=Y token) — this
 * command verifies the hold and CAPTURES it, so money finally moves in the
 * same operation that creates the order. Magento then marks the invoice
 * paid. (Admin Invoice → Capture Online routes to CaptureCommand instead.)
 */
class VerifyChargeCommand extends AbstractVerifyCommand
{
    /**
     * @param PaymentVerifier $verifier
     * @param TokenPersister $tokenPersister
     * @param HoldCapture $holdCapture
     */
    public function __construct(
        PaymentVerifier $verifier,
        TokenPersister $tokenPersister,
        private readonly HoldCapture $holdCapture,
    ) {
        parent::__construct($verifier, $tokenPersister);
    }

    /**
     * Captured charge — the transaction is final.
     *
     * @return bool
     */
    protected function transactionClosed(): bool
    {
        return true;
    }

    /**
     * Capture the verified hold for exactly the verified amount.
     *
     * @param Payment $payment
     * @param array<string, mixed> $status
     * @return void
     * @throws CommandException
     */
    protected function settle(Payment $payment, array $status): void
    {
        $this->holdCapture->ensureCaptured(
            (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID),
            $status,
            (int)$payment->getOrder()->getStoreId()
        );
    }
}
