<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model;

/**
 * Flitt REST API endpoints, relative to the configured api_url base.
 * Cases are added as the module grows (capture, reverse, …).
 */
enum Endpoint: string
{
    case OrderStatus = '/status/order_id';
    case CheckoutUrl = '/checkout/url';
    case CheckoutToken = '/checkout/token';
    case Capture = '/capture/order_id';
    case Reverse = '/reverse/order_id';
    case Recurring = '/recurring';

    /**
     * Whether a success response from this endpoint must carry a valid
     * signature. Full order envelopes (status) are always signed per the
     * Flitt docs, and so are the reverse and recurring responses (their
     * parameter tables and examples both list `signature` — for recurring
     * the synchronous signed envelope is the AUTHORITATIVE payment outcome,
     * per the source-of-truth invariant for server-to-server commands);
     * checkout url/token interim envelopes are not (TLS only). Capture's
     * synchronous response is treated as ADVISORY regardless of signedness
     * (its documented response carries NO signature and the vendor SDK skips
     * verification for synchronous captures) — capture success is always
     * confirmed via the SIGNED status envelope's additional_info instead
     * (HoldCapture).
     *
     * @return bool
     */
    public function requiresSignedResponse(): bool
    {
        return match ($this) {
            self::OrderStatus, self::Reverse, self::Recurring => true,
            self::CheckoutUrl, self::CheckoutToken, self::Capture => false,
        };
    }
}
