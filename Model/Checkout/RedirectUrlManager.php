<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Checkout;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Shubo\Flitt\Exception\FlittApiException;
use Shubo\Flitt\Exception\PaymentInFlightException;
use Shubo\Flitt\Model\Callback\CallbackUrl;
use Shubo\Flitt\Model\CheckoutPayloadBuilder;
use Shubo\Flitt\Model\FlittClient;
use Shubo\Flitt\Model\Ledger;

class RedirectUrlManager
{
    public const string INFO_CHECKOUT_URL = 'flitt_checkout_url';

    /**
     * @param CheckoutPayloadBuilder $payloadBuilder
     * @param FlittClient $client
     * @param OrderPaymentRepositoryInterface $paymentRepository
     * @param CallbackUrl $callbackUrl
     * @param Ledger $ledger
     * @param MintPolicy $mintPolicy
     */
    public function __construct(
        private readonly CheckoutPayloadBuilder $payloadBuilder,
        private readonly FlittClient $client,
        private readonly OrderPaymentRepositoryInterface $paymentRepository,
        private readonly CallbackUrl $callbackUrl,
        private readonly Ledger $ledger,
        private readonly MintPolicy $mintPolicy,
    ) {
    }

    /**
     * Send the new order data to Flitt, and return the URL of Flitt payment page which is attached to the order.
     *
     * @param Order $order
     * @return string
     * @throws FlittApiException
     * @throws LocalizedException
     * @throws PaymentInFlightException
     * @throws NoSuchEntityException
     */
    public function getUrlForOrder(Order $order): string
    {
        $storeId = (int)$order->getStoreId();
        $payment = $order->getPayment();

        if (!$payment instanceof Payment) {
            throw new \InvalidArgumentException(sprintf(
                'Order "%s" has no payment to cache the Flitt session on.',
                (string)$order->getIncrementId()
            ));
        }

        $payload = $this->payloadBuilder->build($order);
        $payload['server_callback_url'] = $this->callbackUrl->forStore($storeId ?: null);
        $payload['response_url'] = $this->callbackUrl->returnForStore($storeId ?: null);
        $payload['cancel_url'] = $payload['response_url'];

        $cachedUrl = (string)$payment->getAdditionalInformation(self::INFO_CHECKOUT_URL);
        $cachedOrderId = (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID);

        if ($cachedUrl !== '' && $this->mintPolicy->reusable($payment, $payload)) {
            return $cachedUrl;
        }

        if ($cachedOrderId !== '') {
            $row = $this->ledger->find($cachedOrderId);
            $state = (string)($row['state'] ?? '');

            if ($state === Ledger::STATE_APPROVED || $state === Ledger::STATE_SETTLED) {
                throw new PaymentInFlightException(
                    __('The payment for this order is already being processed.')
                );
            }
        }

        $payload['order_id'] = $this->mintPolicy->monotonicOrderId(
            (string)$payload['order_id'],
            (int)$payment->getAdditionalInformation(TokenManager::INFO_MINTED_AT)
        );

        $response = $this->client->createCheckoutUrl($payload, $storeId ?: null);
        $checkoutUrl = (string)($response['checkout_url'] ?? '');

        if ($checkoutUrl === '') {
            throw new FlittApiException(__('Flitt did not return a checkout URL.'));
        }

        $this->ledger->recordMint(
            (string)$payload['order_id'],
            $storeId,
            (int)$order->getQuoteId() ?: null,
            (string)$order->getIncrementId(),
            (int)$payload['amount'],
            (string)$payload['currency'],
            (int)$payload['lifetime'],
        );

        foreach ($this->mintPolicy->mintFacts($payload) as $key => $value) {
            $payment->setAdditionalInformation($key, $value);
        }

        $payment->setAdditionalInformation(self::INFO_CHECKOUT_URL, $checkoutUrl);
        $this->paymentRepository->save($payment);

        return $checkoutUrl;
    }
}
