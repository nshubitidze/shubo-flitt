<?php

declare(strict_types=1);

namespace Shubo\Flitt\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Model\Checkout\TokenManager;
use Shubo\Flitt\Model\Ledger;
use Shubo\Flitt\Model\Ui\ConfigProvider;

class MarkLedgerSettled implements ObserverInterface
{
    /**
     * @param Ledger $ledger
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly Ledger $ledger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getData('order');

            if (!$order instanceof Order) {
                return;
            }

            $payment = $order->getPayment();

            if (!$payment instanceof Payment || $payment->getMethod() !== ConfigProvider::CODE) {
                return;
            }

            if ($order->getState() === Order::STATE_PENDING_PAYMENT) {
                return;
            }

            $flittOrderId = (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID);

            if ($flittOrderId === '') {
                return;
            }

            $this->ledger->transition($flittOrderId, Ledger::STATE_SETTLED);
        } catch (\Throwable $e) {
            $this->logger->error('ledger: settle mark after placement failed — ' . $e->getMessage());
        }
    }
}
