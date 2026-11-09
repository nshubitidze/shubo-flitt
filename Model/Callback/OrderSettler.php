<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Callback;

use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Model\MethodInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Exception\PaymentSessionMismatchException;
use Shubo\Flitt\Gateway\Config;
use Shubo\Flitt\Model\Checkout\HoldCapture;
use Shubo\Flitt\Model\Checkout\PaymentVerifier;
use Shubo\Flitt\Model\Checkout\TokenManager;
use Shubo\Flitt\Model\Ui\ConfigProvider;
use Shubo\Flitt\Model\Vault\TokenPersister;

class OrderSettler
{
    /**
     * @param PaymentVerifier $verifier
     * @param HoldCapture $holdCapture
     * @param Config $config
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderSender $orderSender
     * @param TokenPersister $tokenPersister
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly PaymentVerifier $verifier,
        private readonly HoldCapture $holdCapture,
        private readonly Config $config,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderSender $orderSender,
        private readonly TokenPersister $tokenPersister,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Verify and record the payment on a pending order (no-op when already
     * settled).
     *
     * @param OrderInterface $order
     * @param string $flittOrderId The ledger's Flitt-side order id — must
     *     match the payment's own cached session (a mismatch means the
     *     payment belongs to another attempt; refuse rather than guess).
     * @return void
     * @throws CommandException When the payment cannot be verified/settled;
     *     the order is left untouched and the call may be retried.
     */
    public function settle(OrderInterface $order, string $flittOrderId): void
    {
        $payment = $order->getPayment();

        if (!$order instanceof Order || !$payment instanceof Payment) {
            throw new CommandException(__('Unsupported order implementation.'));
        }

        if ($payment->getMethod() !== ConfigProvider::CODE) {
            throw new CommandException(__('Order %1 is not a Flitt order.', $order->getIncrementId()));
        }

        // Idempotency by money facts, not state: an invoice or recorded
        // transaction means the money is already reflected, whatever state
        // the order is in.
        if ($order->hasInvoices() || (string)$payment->getLastTransId() !== '') {
            $this->logger->info(sprintf(
                'settle: order %s already carries money state — no-op',
                $order->getIncrementId()
            ));

            return;
        }

        if ($order->getState() !== Order::STATE_PENDING_PAYMENT) {
            $this->logger->info(sprintf(
                'settle: order %s is "%s", not pending_payment — no-op',
                $order->getIncrementId(),
                (string)$order->getState()
            ));

            return;
        }

        $cachedFlittOrderId = (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID);

        if ($cachedFlittOrderId !== $flittOrderId) {
            $this->logger->error(sprintf(
                'settle: order %s payment session is "%s" but "%s" was delivered — refused',
                $order->getIncrementId(),
                $cachedFlittOrderId !== '' ? $cachedFlittOrderId : '(none)',
                $flittOrderId
            ));

            throw new PaymentSessionMismatchException(__('The payment session does not match this order.'));
        }

        $status = $this->verifier->verify($payment, 'Y');

        $storeId = (int)$order->getStoreId();
        $paymentId = (string)($status['payment_id'] ?? '');
        $authorizeMode = $this->config->getPaymentAction($storeId) === MethodInterface::ACTION_AUTHORIZE;

        if (!$authorizeMode) {
            // Money moves here: capture the verified hold before recording.
            $this->holdCapture->ensureCaptured($flittOrderId, $status, $storeId);
        }

        $fresh = $this->orderRepository->get((int)$order->getId());
        $freshPayment = $fresh->getPayment();

        if (!$fresh instanceof Order || !$freshPayment instanceof Payment) {
            throw new CommandException(__('Unsupported order implementation.'));
        }

        if ($fresh->getState() !== Order::STATE_PENDING_PAYMENT
            || $fresh->hasInvoices()
            || (string)$freshPayment->getLastTransId() !== ''
        ) {
            $this->logger->error(sprintf(
                'settle: order %s changed while settling (now "%s") — aborted before recording;'
                . ' the redelivery will reconcile',
                $fresh->getIncrementId(),
                (string)$fresh->getState()
            ));

            throw new CommandException(__('The order changed while settling.'));
        }

        if ((string)$freshPayment->getAdditionalInformation(TokenManager::INFO_ORDER_ID) !== $flittOrderId) {
            $this->logger->error(sprintf(
                'settle: order %s payment session changed while settling — refused',
                $fresh->getIncrementId()
            ));

            throw new PaymentSessionMismatchException(__('The payment session does not match this order.'));
        }

        $baseAmount = (float)$fresh->getBaseGrandTotal();
        $freshPayment->setTransactionId($paymentId);

        // Saved cards: persist the issued rectoken when the
        // customer consented — before the save below, so core's payment-save
        // observer picks the extension attribute up. Best-effort.
        $this->tokenPersister->attach($freshPayment, $status);

        if ($authorizeMode) {
            $freshPayment->setIsTransactionClosed(false);
            $freshPayment->registerAuthorizationNotification($baseAmount);
            $mode = 'authorized';
        } else {
            $freshPayment->setIsTransactionClosed(true);
            $freshPayment->registerCaptureNotification($baseAmount, true);
            $mode = 'captured (invoice created)';
        }

        $this->orderRepository->save($fresh);

        // Redirect-mode orders suppress the confirmation email at
        // placement (InitializeCommand) — the settle IS the moment the order
        // becomes a sale, so send it now.
        if (!$fresh->getEmailSent()) {
            try {
                $this->orderSender->send($fresh);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'settle: order %s confirmation email failed: %s',
                    $fresh->getIncrementId(),
                    $e->getMessage()
                ));
            }
        }

        $this->logger->info(sprintf(
            'settle: order %s %s via flitt order %s (payment_id %s)',
            $fresh->getIncrementId(),
            $mode,
            $flittOrderId,
            $paymentId !== '' ? $paymentId : '-'
        ));
    }
}
