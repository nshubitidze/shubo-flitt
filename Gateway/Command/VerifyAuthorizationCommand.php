<?php

declare(strict_types=1);

namespace Shubo\Flitt\Gateway\Command;

use Magento\Sales\Model\Order\Payment;

/**
 * Pool key "authorize" (payment_action = authorize): the embed placed a HOLD
 * (preauth=Y token), so placement verifies and records an OPEN authorization
 * transaction — the merchant captures later via Invoice → Capture Online
 * (CaptureStrategyCommand → CaptureCommand).
 */
class VerifyAuthorizationCommand extends AbstractVerifyCommand
{
    /**
     * Preauth hold — the transaction stays open for the merchant's capture.
     *
     * @return bool
     */
    protected function transactionClosed(): bool
    {
        return false;
    }

    /**
     * Authorize-only mode moves no money at placement — the hold stays open.
     *
     * @param Payment $payment
     * @param array<string, mixed> $status
     * @return void
     */
    protected function settle(Payment $payment, array $status): void
    {
    }
}
