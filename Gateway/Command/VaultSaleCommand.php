<?php

declare(strict_types=1);

namespace Shubo\Flitt\Gateway\Command;

use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;
use Shubo\Flitt\Model\Vault\RecurringCharge;

/**
 * Pool key "vault_sale" (payment_action = authorize_capture): hold the order
 * total on the saved card via /api/recurring preauth=Y and capture it in the
 * same operation (signed-status confirmed) — Magento then marks the invoice
 * paid. The same hold-then-capture model as a card checkout, in one placement.
 */
class VaultSaleCommand implements CommandInterface
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

        $this->recurringCharge->charge($payment, (float)SubjectReader::readAmount($commandSubject), true);

        return null;
    }
}
