<?php

declare(strict_types=1);

namespace Shubo\Flitt\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * A Flitt API call failed: transport error, malformed envelope, "failure"
 * response status, or response signature mismatch.
 */
class FlittApiException extends LocalizedException
{
    /**
     * @param Phrase $phrase
     * @param \Exception|null $cause
     * @param int $code
     * @param int|null $apiErrorCode Flitt's error_code when the failure is a
     *     DEFINITIVE "failure" envelope (Flitt docs: response codes); null for
     *     transport/HTTP/signature failures, where the request's real
     *     outcome at Flitt is unknown.
     */
    public function __construct(
        Phrase $phrase,
        ?\Exception $cause = null,
        int $code = 0,
        private readonly ?int $apiErrorCode = null,
    ) {
        parent::__construct($phrase, $cause, $code);
    }

    /**
     * Flitt's error_code for definitive failure envelopes, null otherwise.
     *
     * @return int|null
     */
    public function getApiErrorCode(): ?int
    {
        return $this->apiErrorCode;
    }
}
