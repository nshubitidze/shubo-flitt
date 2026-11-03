<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model;

/**
 * Sanitizes Flitt payloads before they reach a log: masks charge credentials
 * and raw card data, truncates raw bodies. Every code path that logs payloads
 * (API client, payment callbacks) must go through this class so the mask list
 * lives in exactly one place.
 *
 * More keys and another body limit can be set through di.xml; the built-in
 * secrets are always masked and cannot be removed that way.
 */
class LogSanitizer
{
    /**
     * Payload keys whose values must never appear in a log, lower-cased
     * (matching is case-insensitive).
     */
    private const array MASKED_KEYS = ['rectoken', 'card_number', 'cvv2'];

    /**
     * Max characters of a raw body quoted in a log entry, unless di.xml sets another.
     */
    private const int DEFAULT_BODY_LIMIT = 1000;

    /**
     * @param string[] $maskedKeys Keys to mask in addition to the built-in ones, any case (di.xml).
     * @param int $bodyLimit Max characters of a raw body quoted in a log entry (di.xml).
     */
    public function __construct(
        private readonly array $maskedKeys = [],
        private readonly int $bodyLimit = self::DEFAULT_BODY_LIMIT,
    ) {
    }

    /**
     * Mask sensitive keys in a payload array, and flatten control characters
     * in every string value: debug dumps render through Monolog's line
     * formatter with allowInlineLineBreaks, so an embedded newline in an
     * attacker-influenced value (e.g. a forged order_id) would otherwise
     * start a fake log line — the same forgery line() prevents for inline
     * interpolation.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isMaskedKey($key) && $value !== '' && $value !== null) {
                $data[$key] = '***';
            } elseif (is_string($value)) {
                $data[$key] = $this->line($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->mask($value);
            }
        }

        return $data;
    }

    /**
     * Flatten control characters (newlines above all) so a value that an
     * attacker may influence cannot forge extra log lines when embedded in
     * a log message.
     *
     * @param string $value
     * @return string
     */
    public function line(string $value): string
    {
        return (string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value);
    }

    /**
     * Truncated, masked, line-safe raw-body excerpt for a log entry.
     *
     * @param string $body
     * @return string
     */
    public function excerpt(string $body): string
    {
        $keys = implode('|', array_map(static fn (string $key): string => preg_quote($key, '/'), $this->keys()));
        $masked = (string)preg_replace('/"(' . $keys . ')"\s*:\s*"[^"]*"/i', '"$1":"***"', $body);

        return mb_substr($this->line($masked), 0, $this->bodyLimit);
    }

    /**
     * Whether a payload key carries a secret.
     *
     * @param string $key
     * @return bool
     */
    private function isMaskedKey(string $key): bool
    {
        return in_array(strtolower($key), $this->keys(), true);
    }

    /**
     * The built-in secrets plus the keys added in di.xml, lower-cased.
     *
     * @return string[]
     */
    private function keys(): array
    {
        return array_map('strtolower', [...self::MASKED_KEYS, ...$this->maskedKeys]);
    }
}
