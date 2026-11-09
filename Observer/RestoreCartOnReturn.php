<?php

declare(strict_types=1);

namespace Shubo\Flitt\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Gateway\Command\InitializeCommand;
use Shubo\Flitt\Model\Config\Source\CheckoutType;
use Shubo\Flitt\Model\Ui\ConfigProvider;

/**
 * The browser Back button from the Flitt hosted page returns to
 * cart/checkout WITHOUT hitting our return controller — the quote is
 * inactive (order placed), so the customer faces an empty cart while their
 * order sits pending. When they land on either page in that state, give the
 * cart back. The order deliberately STAYS pending: its Flitt session is
 * still payable (delayed=Y), and the reconciler cancels it once the session
 * truly dies. restoreQuote() clears lastRealOrderId, so this fires at most
 * once per order; a customer-emptied cart is never refilled.
 */
class RestoreCartOnReturn implements ObserverInterface
{
    /**
     * @param CheckoutSession $checkoutSession
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
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
            $order = $this->checkoutSession->getLastRealOrder();

            if (!$order->getId() || $order->getState() !== Order::STATE_PENDING_PAYMENT) {
                return;
            }

            $payment = $order->getPayment();

            if (!$payment instanceof Payment
                || $payment->getMethod() !== ConfigProvider::CODE
                || (string)$payment->getAdditionalInformation(InitializeCommand::INFO_CHECKOUT_MODE)
                    !== CheckoutType::REDIRECT
            ) {
                return;
            }

            if ((int)$this->checkoutSession->getQuote()->getItemsCount() > 0) {
                // The customer is already shopping on a live cart — never
                // clobber it with the abandoned order's quote.
                return;
            }

            if ($this->checkoutSession->restoreQuote()) {
                $this->logger->info(sprintf(
                    'return: cart restored for order %s after an off-page return (back button);'
                    . ' order left pending',
                    (string)$order->getIncrementId()
                ));
            }
        } catch (\Throwable $e) {
            // Never break a storefront page over cart housekeeping.
            $this->logger->error('return: cart restore on revisit failed — ' . $e->getMessage());
        }
    }
}
