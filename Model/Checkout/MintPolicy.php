<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Checkout;

use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Model\Order\Payment;

/**
 * Shared mint bookkeeping rules for Flitt checkout sessions — the freshness
 * window and the "{prefix}_{increment}_{unixtime}" order-id arithmetic used
 * by both the embed token mint (TokenManager) and the redirect URL mint
 * (RedirectUrlManager). One implementation, deliberately: per-flow copies of
 * exactly this logic drift apart.
 */
class MintPolicy
{
    /**
     * Reuse a cached mint only while it is comfortably inside the lifetime
     * it was MINTED with (config may have changed since) — a customer must
     * never be handed an expiring payment session.
     */
    private const float LIFETIME_REUSE_RATIO = 0.9;

    /**
     * Absolute floor on remaining validity: with short configured lifetimes
     * the 10% ratio margin alone could hand out a session with seconds left
     * while the customer is still typing card details.
     */
    private const int MIN_REMAINING_SECONDS = 120;

    /**
     * Whether a cached mint is still comfortably inside the lifetime it was
     * minted with: within the reuse ratio AND with an absolute floor of
     * remaining validity.
     *
     * @param int $mintedAt
     * @param int $mintedLifetime
     * @return bool
     */
    public function isFresh(int $mintedAt, int $mintedLifetime): bool
    {
        $now = time();

        return $now < $mintedAt + (int)($mintedLifetime * self::LIFETIME_REUSE_RATIO)
            && $now < $mintedAt + $mintedLifetime - self::MIN_REMAINING_SECONDS;
    }

    /**
     * Everything before the timestamp tail: "{prefix}_{increment}".
     *
     * Detects a changed increment id or prefix while a mint is cached.
     *
     * @param string $orderId
     * @return string
     */
    public function orderIdHead(string $orderId): string
    {
        return substr($orderId, 0, (int)strrpos($orderId, '_'));
    }

    /**
     * The unixtime tail of a "{prefix}_{increment}_{unixtime}" order id.
     *
     * @param string $orderId
     * @return int
     */
    public function orderIdTimestamp(string $orderId): int
    {
        return (int)substr($orderId, (int)strrpos($orderId, '_') + 1);
    }

    /**
     * Bump the order id's timestamp tail above the previous mint if needed.
     *
     * Re-mints must never reuse a previous Flitt order_id: within one second
     * the timestamp tail would collide (Flitt rejects duplicate ids with
     * error 1013), so keep it strictly greater than the last minted one.
     *
     * @param string $orderId
     * @param int $lastMintedAt
     * @return string
     */
    public function monotonicOrderId(string $orderId, int $lastMintedAt): string
    {
        if ($this->orderIdTimestamp($orderId) > $lastMintedAt) {
            return $orderId;
        }

        return $this->orderIdHead($orderId) . '_' . ($lastMintedAt + 1);
    }

    /**
     * Whether a cached mint still binds to this freshly built payload —
     * fresh, same money, same preauth mode, same callback URL (Flitt keeps
     * the one the mint was made with, and callbacks do not follow
     * redirects), same order-id head. The caller separately checks that its
     * cached ARTIFACT (embed token / checkout URL) exists.
     *
     * @param Payment|QuotePayment $payment The payment carrying the cached INFO_* facts.
     * @param array<string, string|int> $payload Fresh builder output incl. server_callback_url.
     * @return bool
     */
    public function reusable(Payment|QuotePayment $payment, array $payload): bool
    {
        $cachedOrderId = (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID);

        return $cachedOrderId !== ''
            && $this->isFresh(
                (int)$payment->getAdditionalInformation(TokenManager::INFO_MINTED_AT),
                (int)$payment->getAdditionalInformation(TokenManager::INFO_LIFETIME)
            )
            && (int)$payment->getAdditionalInformation(TokenManager::INFO_AMOUNT) === $payload['amount']
            && $payment->getAdditionalInformation(TokenManager::INFO_CURRENCY) === $payload['currency']
            && $payment->getAdditionalInformation(TokenManager::INFO_PREAUTH) === $payload['preauth']
            && $payment->getAdditionalInformation(TokenManager::INFO_CALLBACK_URL) === $payload['server_callback_url']
            && (!array_key_exists('response_url', $payload)
                || $payment->getAdditionalInformation(TokenManager::INFO_RETURN_URL) === $payload['response_url'])
            // Vault flips and login transitions change whether a rectoken is
            // requested — Flitt keeps the flag the mint was made with.
            && (string)($payment->getAdditionalInformation(TokenManager::INFO_REQUIRED_RECTOKEN) ?: 'N')
                === (string)($payload['required_rectoken'] ?? 'N')
            && $this->orderIdHead($cachedOrderId) === $this->orderIdHead((string)$payload['order_id']);
    }

    /**
     * The INFO_* facts every mint caches on its payment — the callback
     * settle path binds on INFO_ORDER_ID and the verifier on INFO_PREAUTH/
     * INFO_AMOUNT, so both flows must write the identical set. The caller
     * adds its artifact key (embed token / checkout URL) on top.
     *
     * @param array<string, string|int> $payload The minted payload (post monotonic bump).
     * @return array<string, string|int>
     */
    public function mintFacts(array $payload): array
    {
        $facts = [
            TokenManager::INFO_ORDER_ID => $payload['order_id'],
            TokenManager::INFO_AMOUNT => $payload['amount'],
            TokenManager::INFO_CURRENCY => $payload['currency'],
            TokenManager::INFO_PREAUTH => $payload['preauth'],
            TokenManager::INFO_LIFETIME => $payload['lifetime'],
            TokenManager::INFO_MINTED_AT => $this->orderIdTimestamp((string)$payload['order_id']),
            TokenManager::INFO_CALLBACK_URL => $payload['server_callback_url'],
            TokenManager::INFO_REQUIRED_RECTOKEN => $payload['required_rectoken'] ?? 'N',
        ];

        // Redirect payloads only — the embed never leaves the checkout page.
        if (array_key_exists('response_url', $payload)) {
            $facts[TokenManager::INFO_RETURN_URL] = $payload['response_url'];
        }

        return $facts;
    }
}
