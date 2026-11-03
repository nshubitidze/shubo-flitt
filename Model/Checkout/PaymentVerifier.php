<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Checkout;

use Magento\Payment\Gateway\Command\CommandException;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Exception\FlittApiException;
use Shubo\Flitt\Gateway\Config;
use Shubo\Flitt\Model\FlittClient;
use Shubo\Flitt\Model\Money;

/**
 * Server-side truth for the pay-first flow: an order may only be placed as
 * paid when Flitt's SIGNED status envelope proves the payment session cached
 * on this payment is approved, intact, and for exactly this order.
 *
 * Nothing the browser sends is consulted — the flitt_order_id comes from the
 * quote-payment cache written by TokenManager and copied to the order payment
 * by Magento's quote-to-order conversion.
 */
class PaymentVerifier
{
    /**
     * @param FlittClient $client
     * @param Config $config
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly FlittClient $client,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Verify the payment's cached Flitt session against the live signed status.
     *
     * $expectedPreauth guards the hold-vs-charge gap the status API cannot
     * express: approved means both "charged" and "held", so the mode the
     * token was MINTED with (cached by TokenManager) must match the mode the
     * running command assumes — otherwise a Payment Action flip between
     * paying and placing would record a hold as paid (or a charge as a hold).
     *
     * @param Payment $payment
     * @param string $expectedPreauth 'N' when placement records a charge, 'Y' for a hold.
     * @return array<string, mixed> The verified Flitt status fields.
     * @throws CommandException When verification fails for any reason.
     */
    public function verify(Payment $payment, string $expectedPreauth): array
    {
        $order = $payment->getOrder();
        $storeId = (int)$order->getStoreId();
        $flittOrderId = (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID);

        if ($flittOrderId === '') {
            $this->logger->error(sprintf(
                'verify: order %s has no cached flitt_order_id — placement rejected',
                $order->getIncrementId()
            ));

            throw new CommandException(__('The payment session was not found. Please try paying again.'));
        }

        $prefix = $this->config->getOrderIdPrefix($storeId);
        $expectedHead = ($prefix !== '' ? $prefix . '_' : '') . $order->getIncrementId();

        if ($this->orderIdHead($flittOrderId) !== $expectedHead) {
            $this->logger->error(sprintf(
                'verify: flitt_order_id "%s" does not belong to order %s (expected head "%s")',
                $flittOrderId,
                $order->getIncrementId(),
                $expectedHead
            ));

            throw new CommandException(__('The payment session does not match this order. Please try paying again.'));
        }

        $mintedPreauth = (string)$payment->getAdditionalInformation(TokenManager::INFO_PREAUTH);

        if ($mintedPreauth !== $expectedPreauth) {
            $this->logger->error(sprintf(
                'verify: order %s payment-mode mismatch — token minted with preauth "%s" but the current'
                . ' Payment Action expects "%s". Money may have moved under the OLD mode; needs review.',
                $order->getIncrementId(),
                $mintedPreauth,
                $expectedPreauth
            ));

            throw new CommandException(
                __('The payment mode changed while you were paying and the payment will be reviewed.'
                    . ' Please contact us before paying again.')
            );
        }

        try {
            $status = $this->client->getOrderStatus($flittOrderId, $storeId);
        } catch (FlittApiException $e) {
            // FlittClient already logged the failure in detail.
            throw new CommandException(
                __('Your payment could not be verified right now. Please try again in a moment.')
            );
        }

        if ((string)($status['order_id'] ?? '') !== $flittOrderId) {
            $this->logger->error(sprintf(
                'verify: status envelope is for flitt order "%s" but "%s" was requested (order %s)',
                (string)($status['order_id'] ?? ''),
                $flittOrderId,
                $order->getIncrementId()
            ));

            throw new CommandException(
                __('Your payment could not be verified right now. Please try again in a moment.')
            );
        }

        $orderStatus = (string)($status['order_status'] ?? '');

        if ($orderStatus === 'created' || $orderStatus === 'processing') {
            throw new CommandException(
                __('Your payment is still being processed. Please wait a moment and try again.')
            );
        }

        if ($orderStatus !== 'approved') {
            $this->logger->info(sprintf(
                'verify: flitt order %s is "%s" — placement rejected for order %s',
                $flittOrderId,
                $orderStatus,
                $order->getIncrementId()
            ));

            throw new CommandException(__('The payment was not approved. Please try again.'));
        }

        $expectedAmount = Money::toMinorUnits($order->getGrandTotal());
        $paidAmount = (int)($status['amount'] ?? -1);
        $paidCurrency = (string)($status['currency'] ?? '');
        $reversed = (int)($status['reversal_amount'] ?? 0);
        $merchantId = (int)($status['merchant_id'] ?? 0);

        if ($paidAmount !== $expectedAmount
            || $paidCurrency !== (string)$order->getOrderCurrencyCode()
            || $reversed !== 0
            || $merchantId !== $this->config->getMerchantId($storeId)
        ) {
            $this->logger->error(sprintf(
                'verify: APPROVED payment %s does NOT match order %s — paid %d %s (reversed %d, merchant %d),'
                . ' expected %d %s. Money may have moved; needs review/reversal.',
                $flittOrderId,
                $order->getIncrementId(),
                $paidAmount,
                $paidCurrency,
                $reversed,
                $merchantId,
                $expectedAmount,
                (string)$order->getOrderCurrencyCode()
            ));

            throw new CommandException(
                __('The payment does not match this order and will be reviewed. Please contact us before paying again.')
            );
        }

        $this->logger->info(sprintf(
            'verify: order %s confirmed paid via flitt order %s (payment_id %s)',
            $order->getIncrementId(),
            $flittOrderId,
            (string)($status['payment_id'] ?? '-')
        ));

        return $status;
    }

    /**
     * Everything before the timestamp tail: "{prefix}_{increment}".
     *
     * @param string $orderId
     * @return string
     */
    private function orderIdHead(string $orderId): string
    {
        return substr($orderId, 0, (int)strrpos($orderId, '_'));
    }
}
