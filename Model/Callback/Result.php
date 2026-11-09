<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Callback;

/**
 * Outcome of processing one callback delivery: the HTTP status Flitt's
 * retry engine acts on (200 = final, anything else = retry per schedule)
 * plus a short diagnostic body (Flitt ignores it; curl testing does not).
 */
class Result
{
    /**
     * @param int $httpCode
     * @param string $body
     */
    public function __construct(
        public readonly int $httpCode,
        public readonly string $body,
    ) {
    }
}
