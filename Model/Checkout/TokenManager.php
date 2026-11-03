<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Checkout;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Shubo\Flitt\Exception\FlittApiException;
use Shubo\Flitt\Model\Callback\CallbackUrl;
use Shubo\Flitt\Model\CheckoutPayloadBuilder;
use Shubo\Flitt\Model\FlittClient;
use Shubo\Flitt\Model\Ledger;

/**
 * Mints and reuses Flitt embed payment tokens for an active quote — the
 * server side of the pay-first flow.
 *
 * A token is bound to the exact payload it was minted with, so it is cached
 * on the quote payment and reused only while that payload still matches —
 * amount, currency, preauth mode, callback URL, and the order-id head
 * (prefix + reserved increment id) — and enough of the MINTED lifetime
 * remains. Any mismatch re-mints against a fresh Flitt order. The cached
 * flitt_order_id is the server-side truth the placement-time verification
 * resolves the payment by; every mint is also recorded in the Ledger,
 * which is what the callback and the reconciler
 * resolve it by after the quote is gone.
 */
class TokenManager
{
    public const string INFO_ORDER_ID = 'flitt_order_id';
    public const string INFO_TOKEN = 'flitt_token';
    public const string INFO_AMOUNT = 'flitt_token_amount';
    public const string INFO_CURRENCY = 'flitt_token_currency';
    public const string INFO_PREAUTH = 'flitt_token_preauth';
    public const string INFO_LIFETIME = 'flitt_token_lifetime';
    public const string INFO_MINTED_AT = 'flitt_token_minted_at';
    public const string INFO_CALLBACK_URL = 'flitt_token_callback_url';
    public const string INFO_RETURN_URL = 'flitt_token_return_url';
    public const string INFO_REQUIRED_RECTOKEN = 'flitt_token_required_rectoken';

    /**
     * @param CheckoutPayloadBuilder $payloadBuilder
     * @param FlittClient $client
     * @param CartRepositoryInterface $cartRepository
     * @param CallbackUrl $callbackUrl
     * @param Ledger $ledger
     * @param MintPolicy $mintPolicy
     */
    public function __construct(
        private readonly CheckoutPayloadBuilder $payloadBuilder,
        private readonly FlittClient $client,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CallbackUrl $callbackUrl,
        private readonly Ledger $ledger,
        private readonly MintPolicy $mintPolicy,
    ) {
    }

    /**
     * Get an embed token for the quote.
     *
     * Cached while amount+currency still match and the token is fresh;
     * otherwise a new Flitt order is minted and cached in its place.
     *
     * @param Quote $quote
     * @return array{token: string, order_id: string}
     * @throws FlittApiException
     * @throws \InvalidArgumentException When the quote cannot be paid (no positive total,
     *     currency not enabled).
     */
    public function getTokenForQuote(Quote $quote): array
    {
        $storeId = (int)$quote->getStoreId();

        if (!$quote->getReservedOrderId()) {
            $quote->reserveOrderId();
            $this->cartRepository->save($quote);
        }

        $payload = $this->payloadBuilder->buildFromQuote($quote);
        // Appended here, not in the builder: the callback route is ours, not
        // part of the order mapping (see the builder's class docblock).
        $payload['server_callback_url'] = $this->callbackUrl->forStore($storeId ?: null);
        $payment = $quote->getPayment();

        $cachedToken = (string)$payment->getAdditionalInformation(self::INFO_TOKEN);

        if ($cachedToken !== '' && $this->mintPolicy->reusable($payment, $payload)) {
            return [
                'token' => $cachedToken,
                'order_id' => (string)$payment->getAdditionalInformation(self::INFO_ORDER_ID),
            ];
        }

        // Re-mints must never reuse a previous flitt order_id (e.g. an
        // instant coupon apply within the same second).
        $payload['order_id'] = $this->mintPolicy->monotonicOrderId(
            (string)$payload['order_id'],
            (int)$payment->getAdditionalInformation(self::INFO_MINTED_AT)
        );

        $response = $this->client->createCheckoutToken($payload, $storeId ?: null);
        $token = (string)($response['token'] ?? '');

        if ($token === '') {
            throw new FlittApiException(__('Flitt did not return a payment token.'));
        }

        // Ledger write failures propagate: a token whose order the ledger
        // cannot resolve must never reach the widget (its callback could
        // never be settled). No hold exists yet — the Flitt order just
        // expires unpaid, and the customer's retry mints a fresh one.
        $this->ledger->recordMint(
            (string)$payload['order_id'],
            $storeId,
            (int)$quote->getId() ?: null,
            (string)$quote->getReservedOrderId(),
            (int)$payload['amount'],
            (string)$payload['currency'],
            (int)$payload['lifetime'],
        );

        foreach ($this->mintPolicy->mintFacts($payload) as $key => $value) {
            $payment->setAdditionalInformation($key, $value);
        }
        $payment->setAdditionalInformation(self::INFO_TOKEN, $token);
        $this->cartRepository->save($quote);

        return ['token' => $token, 'order_id' => $payload['order_id']];
    }
}
