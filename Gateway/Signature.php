<?php

declare(strict_types=1);

namespace Shubo\Flitt\Gateway;

use Flitt\Helper\ApiHelper;

/**
 * Flitt signature service — the module's single point of contact with the
 * Flitt SDK. The hash itself is delegated to ApiHelper::generateSignature
 * (protocol 1.0: drop empty/null values, ksort, "secret|v1|v2|…", sha1,
 * lowercase) so we stay byte-compatible with Flitt's servers; key handling
 * and verification around it are ours.
 */
class Signature
{
    /**
     * Flitt signature scheme: '1.0' = pipe-joined sha1 (used by all standard
     * requests and callbacks). NOT the request payload's "version" field
     * (1.0.1) — the '2.0' scheme exists only for the recurring-payment
     * base64 envelope, which this module does not use.
     */
    private const string SIGNATURE_VERSION = '1.0';

    /**
     * Protocol keys that must never take part in a signature computation.
     */
    private const array EXCLUDED_KEYS = ['signature', 'response_signature_string'];

    /**
     * Compute the signature for a request or callback payload.
     *
     * @param array<string, mixed> $params
     * @param string $secretKey
     * @return string
     * @throws \InvalidArgumentException When a param value is non-scalar — the
     *     SDK hash would silently coerce it to the literal "Array"/fatal.
     */
    public function generate(array $params, string $secretKey): string
    {
        foreach (self::EXCLUDED_KEYS as $key) {
            unset($params[$key]);
        }

        foreach ($params as $key => $value) {
            if ($value !== null && !is_scalar($value)) {
                throw new \InvalidArgumentException(
                    sprintf('Flitt signature params must be scalar; "%s" is %s.', $key, get_debug_type($value))
                );
            }
        }

        return ApiHelper::generateSignature($params, $secretKey, self::SIGNATURE_VERSION);
    }

    /**
     * Return the payload with its signature field set.
     *
     * @param array<string, mixed> $params
     * @param string $secretKey
     * @return array<string, mixed>
     * @throws \InvalidArgumentException When a param value is non-scalar.
     */
    public function sign(array $params, string $secretKey): array
    {
        $params['signature'] = $this->generate($params, $secretKey);

        return $params;
    }

    /**
     * Verify a signed payload (API response or server callback) in constant time.
     *
     * @param array<string, mixed> $params
     * @param string $secretKey
     * @return bool
     */
    public function verify(array $params, string $secretKey): bool
    {
        $received = $params['signature'] ?? null;

        if (!is_string($received) || $received === '') {
            return false;
        }

        try {
            return hash_equals($this->generate($params, $secretKey), $received);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
