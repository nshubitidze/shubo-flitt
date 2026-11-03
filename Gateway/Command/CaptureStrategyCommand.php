<?php

declare(strict_types=1);

namespace Shubo\Flitt\Gateway\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;

class CaptureStrategyCommand implements CommandInterface
{
    /**
     * @param VerifyChargeCommand $verifyCharge
     * @param CaptureCommand $capture
     */
    public function __construct(
        private readonly VerifyChargeCommand $verifyCharge,
        private readonly CaptureCommand $capture,
    ) {
    }

    /**
     * @param array<string, mixed> $commandSubject
     * @return null
     * @throws CommandException
     * @throws LocalizedException
     */
    public function execute(array $commandSubject)
    {
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();

        if ($payment instanceof Payment && $payment->getAuthorizationTransaction()) {
            return $this->capture->execute($commandSubject);
        }

        if ($payment instanceof Payment && $payment->getOrder()->getId()) {
            throw new CommandException(
                __('This order is settled by the Flitt payment confirmation and cannot be captured manually.')
            );
        }

        return $this->verifyCharge->execute($commandSubject);
    }
}
