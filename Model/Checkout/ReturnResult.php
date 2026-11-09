<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Checkout;

/**
 * ReturnHandler's verdict plus the order facts the controller needs for
 * session seeding (success) or quote restore (failure).
 */
class ReturnResult
{
    /**
     * @param ReturnDisposition $disposition
     * @param string $incrementId
     * @param int|null $orderId
     * @param int|null $quoteId
     */
    public function __construct(
        public readonly ReturnDisposition $disposition,
        public readonly string $incrementId = '',
        public readonly ?int $orderId = null,
        public readonly ?int $quoteId = null,
    ) {
    }
}
