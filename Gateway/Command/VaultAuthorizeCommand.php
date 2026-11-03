<?php

declare(strict_types=1);

namespace Shubo\Flitt\Gateway\Command;

use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;
use Shubo\Flitt\Model\Vault\RecurringCharge;

/**
 * Pool key "vault_authorize" (payment_action = authorize): hold the order
 * total on the saved card via /api/recurring preauth=Y and record an OPEN
 * authorization — the merchant captures later via Invoice → Capture Online
 * (Vault resets the payment method to the provider, so the provider's
 * CaptureStrategyCommand → CaptureCommand handles it natively).
 */
class VaultAuthorizeCommand implements CommandInterface
{
    /**
     * @param RecurringCharge $recurringCharge
     */
    public function __construct(
        private readonly RecurringCharge $recurringCharge,
    ) {
    }

    /**
     * @param array<string, mixed> $commandSubject
     * @return null
     * @throws CommandException
     */
    public function execute(array $commandSubject)
    {
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();

        if (!$payment instanceof Payment) {
            throw new CommandException(__('Flitt saved-card payment requires an order payment.'));
        }

        $this->recurringCharge->charge($payment, (float)SubjectReader::readAmount($commandSubject), false);

        return null;
    }
}
