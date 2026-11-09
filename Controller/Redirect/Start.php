<?php

declare(strict_types=1);

namespace Shubo\Flitt\Controller\Redirect;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Exception\FlittApiException;
use Shubo\Flitt\Exception\PaymentInFlightException;
use Shubo\Flitt\Model\Checkout\RedirectUrlManager;
use Shubo\Flitt\Model\Checkout\TokenManager;
use Shubo\Flitt\Model\OrderLock;
use Shubo\Flitt\Model\Ui\ConfigProvider;

/**
 * Get the customer order, Create checkout session in Flitt for it and redirect customer to Flitt payment page.
 */
class Start implements HttpGetActionInterface
{
    /**
     * @param CheckoutSession $checkoutSession
     * @param RedirectUrlManager $urlManager
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderLock $orderLock
     * @param RedirectFactory $redirectFactory
     * @param MessageManager $messageManager
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectUrlManager $urlManager,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderLock $orderLock,
        private readonly RedirectFactory $redirectFactory,
        private readonly MessageManager $messageManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Send the session's just-placed order to the Flitt hosted page.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $result = $this->redirectFactory->create();
        $sessionOrder = $this->checkoutSession->getLastRealOrder();

        if (!$sessionOrder->getId()
            || !$sessionOrder->getPayment()
            || $sessionOrder->getPayment()->getMethod() !== ConfigProvider::CODE
        ) {
            return $result->setPath('checkout/cart');
        }

        $incrementId = (string)$sessionOrder->getIncrementId();

        // In theory this condition is never hit. (order is not locked because we have just created it)
        if (!$this->orderLock->acquire($incrementId)) {
            $this->messageManager->addNoticeMessage(
                (string)__('Your payment is being processed. Please check your order status in a moment.')
            );

            return $result->setPath('checkout/cart');
        }

        try {
            $order = $this->orderRepository->get((int)$sessionOrder->getId());

            if (!$order instanceof Order) {
                return $result->setPath('checkout/cart');
            }

            if ($order->getState() !== Order::STATE_PENDING_PAYMENT || $order->hasInvoices()) {
                return $result->setPath(
                    in_array($order->getState(), [Order::STATE_PROCESSING, Order::STATE_COMPLETE], true)
                        ? 'checkout/onepage/success'
                        : 'checkout/cart'
                );
            }

            try {
                $checkoutUrl = $this->urlManager->getUrlForOrder($order);
            } catch (PaymentInFlightException) {
                $this->logger->warning(sprintf(
                    'redirect/start: order %s has an in-flight paid session — handoff refused',
                    $incrementId
                ));
                $this->messageManager->addNoticeMessage(
                    (string)__('The payment for this order is already being processed.'
                        . ' You will receive a confirmation shortly.')
                );

                return $result->setPath('checkout/cart');
            } catch (\Throwable $e) {
                if (!$e instanceof FlittApiException) {
                    $this->logger->error('redirect/start failure: ' . $e->getMessage());
                }

                return $this->abortHandoff($result, $order);
            }

            $this->logger->info(sprintf(
                'redirect/start: order %s handed off to the hosted page',
                $incrementId
            ));

            return $result->setUrl($checkoutUrl);
        } finally {
            $this->orderLock->release($incrementId);
        }
    }

    /**
     * In case we could not redirect customer to the Flitt payment page, handle the order in two different ways:
     * If the payment has the Flitt order_id value, keep the order in pending and let the cron job to handle it.
     * Otherwise, customer was never charged, so cancel the order and instead restore the quote.
     * @param Redirect $result
     * @param Order $order
     * @return Redirect
     */
    private function abortHandoff(Redirect $result, Order $order): Redirect
    {
        $payment = $order->getPayment();
        $everMinted = $payment instanceof Payment
            && (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID) !== '';

        try {
            if (!$everMinted && $order->canCancel()) {
                $order->addCommentToStatusHistory(
                    (string)__('Flitt hosted-page handoff failed before any payment session existed;'
                        . ' order cancelled and the cart restored.')
                );
                $order->cancel();
                $this->orderRepository->save($order);
                $this->checkoutSession->restoreQuote();

                $this->logger->info(sprintf(
                    'redirect/start: order %s cancelled after a failed first mint, quote restored',
                    $order->getIncrementId()
                ));
            } else {
                $order->addCommentToStatusHistory(
                    (string)__('Flitt hosted-page handoff failed; the payment session may still be'
                        . ' payable, so the order is left pending for reconciliation.')
                );
                $this->orderRepository->save($order);
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'redirect/start: recovery for order %s failed: %s',
                $order->getIncrementId(),
                $e->getMessage()
            ));
        }

        $this->messageManager->addErrorMessage(
            (string)__('The payment could not be started. Please try again.')
        );

        return $result->setPath('checkout/cart');
    }
}
