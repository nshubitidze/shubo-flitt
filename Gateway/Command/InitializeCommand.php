<?php

declare(strict_types=1);

namespace Shubo\Flitt\Gateway\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;
use Shubo\Flitt\Model\Config\Source\CheckoutType;

/**
 * Create order with pending status before authorizing the payment.
 * At this point email is not sent to customer.
 */
class InitializeCommand implements CommandInterface
{
    /**
     * Checkout type [payment/shubo_flitt/checkout_type]
     */
    public const string INFO_CHECKOUT_MODE = 'flitt_checkout_mode';

    /**
     * @param array<string, mixed> $commandSubject
     * @return void
     * @throws LocalizedException
     */
    public function execute(array $commandSubject)
    {
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();
        $stateObject = SubjectReader::readStateObject($commandSubject);

        if ($payment instanceof Order\Payment) {
            $payment->getOrder()->setCanSendNewEmailFlag(false);
            $payment->setAdditionalInformation(self::INFO_CHECKOUT_MODE, CheckoutType::REDIRECT);
        }

        $stateObject->setData('state', Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('status', Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('is_notified', false);
    }
}
