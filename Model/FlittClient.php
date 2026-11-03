<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model;

use Laminas\Http\Response as HttpResponse;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Exception\FlittApiException;
use Shubo\Flitt\Gateway\Config;
use Shubo\Flitt\Gateway\Signature;

class FlittClient
{
    /**
     * "version" field sent in every request payload ("1.0" is deprecated by Flitt).
     */
    private const string PROTOCOL_VERSION = '1.0.1';

    /**
     * @param CurlFactory $curlFactory
     * @param Json $json
     * @param Signature $signature
     * @param Config $config
     * @param LogSanitizer $logSanitizer
     * @param LoggerInterface $logger Bound to ShuboFlittLogger (var/log/shubo_flitt.log) in di.xml.
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly Signature $signature,
        private readonly Config $config,
        private readonly LogSanitizer $logSanitizer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch the current state of an order from Flitt.
     *
     * @param string $orderId
     * @param int|null $storeId
     * @return array<string, mixed> Flitt order fields (order_status, amount, …)
     * @throws FlittApiException
     */
    public function getOrderStatus(string $orderId, ?int $storeId = null): array
    {
        return $this->post(Endpoint::OrderStatus, ['order_id' => $orderId], $storeId);
    }

    /**
     * Create a Flitt order and get the hosted checkout page URL (redirect mode).
     *
     * @param array<string, mixed> $orderParams Order fields (order_id, order_desc, amount, currency, …)
     * @param int|null $storeId
     * @return array<string, mixed> Response fields (checkout_url, payment_id, …)
     * @throws FlittApiException
     */
    public function createCheckoutUrl(array $orderParams, ?int $storeId = null): array
    {
        return $this->post(Endpoint::CheckoutUrl, $orderParams, $storeId);
    }

    /**
     * Create a Flitt order and get the payment token for the embedded JS SDK (embed mode).
     *
     * @param array<string, mixed> $orderParams Order fields (order_id, order_desc, amount, currency, …)
     * @param int|null $storeId
     * @return array<string, mixed> Response fields (token, …)
     * @throws FlittApiException
     */
    public function createCheckoutToken(array $orderParams, ?int $storeId = null): array
    {
        return $this->post(Endpoint::CheckoutToken, $orderParams, $storeId);
    }

    /**
     * Charge a previously held (preauth=Y) amount — full when $amount equals
     * the held actual_amount, partial otherwise (remainder auto-returns to
     * the payer; Flitt allows a SINGLE capture per hold).
     *
     * Retry-safe per the Flitt docs: repeating a capture with the same
     * amount returns the current capture_status instead of double-charging.
     * The response is ADVISORY — callers must confirm via the signed status
     * envelope (HoldCapture does).
     *
     * @param string $orderId Flitt-side order id the hold was created with.
     * @param int $amount Minor units (tetri/cents).
     * @param string $currency
     * @param int|null $storeId
     * @return array<string, mixed> Response fields (capture_status, …)
     * @throws FlittApiException
     */
    public function captureOrder(string $orderId, int $amount, string $currency, ?int $storeId = null): array
    {
        return $this->post(Endpoint::Capture, [
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
        ], $storeId);
    }

    /**
     * Return held or charged funds to the payer — full when $amount equals
     * the order's actual_amount, partial otherwise. Reversing an UNCAPTURED
     * hold releases it instantly and fee-free (full reverse only); a captured
     * payment allows MULTIPLE partial reverses up to actual_amount.
     *
     * $reverseId is Flitt's idempotency key for one logical reversal: retries
     * of the SAME reversal must reuse the same value, distinct reversals of
     * one order must use distinct values.
     *
     * The response IS signed (unlike capture) — the client verifies it, so
     * the returned reverse_status/reversal_amount are trustworthy.
     *
     * @param string $orderId Flitt-side order id.
     * @param int $amount Minor units (tetri/cents).
     * @param string $currency
     * @param string $reverseId Idempotency key, ≤50 chars.
     * @param int|null $storeId
     * @return array<string, mixed> Response fields (reverse_status, reversal_amount, …)
     * @throws FlittApiException
     */
    public function reverseOrder(
        string $orderId,
        int $amount,
        string $currency,
        string $reverseId,
        ?int $storeId = null,
    ): array {
        return $this->post(Endpoint::Reverse, [
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'reverse_id' => $reverseId,
        ], $storeId);
    }

    /**
     * Charge a saved card by its rectoken — the whole payment happens inside
     * this one host-to-host call (no redirect, no 3DS flow available on this
     * endpoint; SCA-challenged charges decline).
     *
     * The response is a full SIGNED order envelope and is AUTHORITATIVE for
     * the payment outcome (server-to-server command) — a callback may also
     * arrive later and must stay idempotent. Supports preauth=Y, so the
     * hold-first money model carries over unchanged.
     *
     * @param array<string, mixed> $orderParams Order fields (order_id, order_desc,
     *     amount, currency, rectoken, preauth, server_callback_url, …)
     * @param int|null $storeId
     * @return array<string, mixed> Signed order envelope (order_status, payment_id, …)
     * @throws FlittApiException
     */
    public function createRecurringOrder(array $orderParams, ?int $storeId = null): array
    {
        return $this->post(Endpoint::Recurring, $orderParams, $storeId);
    }

    /**
     * Sign params and POST them to a Flitt endpoint; unwrap and validate the response.
     *
     * Response signature policy is per-endpoint (Endpoint::requiresSignedResponse):
     * endpoints whose success envelope is always signed (order status) must
     * verify — a missing signature there is a failure; endpoints whose success
     * response carries no signature (checkout/url) rely on TLS, but are still
     * verified whenever a signature is present. Inbound server callbacks must
     * NOT reuse any of this leniency — the callback controller requires a
     * valid signature outright.
     *
     * @param Endpoint $endpoint
     * @param array<string, mixed> $params
     * @param int|null $storeId
     * @return array<string, mixed>
     * @throws FlittApiException
     */
    private function post(Endpoint $endpoint, array $params, ?int $storeId): array
    {
        $path = $endpoint->value;
        $secretKey = $this->config->getSecretKey($storeId, strict: true);
        $merchantId = $this->config->getMerchantId($storeId, strict: true);

        try {
            $request = $this->signature->sign(
                $params + [
                    'version' => self::PROTOCOL_VERSION,
                    'merchant_id' => $merchantId,
                ],
                $secretKey
            );
        } catch (\InvalidArgumentException $e) {
            throw new FlittApiException(__('Flitt API request to %1 rejected: %2', $path, $e->getMessage()), $e);
        }

        $orderId = $this->logSanitizer->line((string)($request['order_id'] ?? '-'));

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug("$path request", ['request' => $this->logSanitizer->mask($request)]);
        }

        $curl = $this->curlFactory->create();
        $curl->addHeader('Content-Type', 'application/json');
        $curl->setTimeout($this->config->getApiTimeout($storeId));

        $startedAt = microtime(true);

        try {
            $curl->post(
                $this->config->getApiUrl($storeId) . $path,
                $this->json->serialize(['request' => $request])
            );
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                '%s transport failure after %s (order_id: %s): %s',
                $path,
                $this->elapsed($startedAt),
                $orderId,
                $e->getMessage()
            ));

            throw new FlittApiException(__('Flitt API request to %1 failed: %2', $path, $e->getMessage()), $e);
        }

        $elapsed = $this->elapsed($startedAt);
        $status = $curl->getStatus();
        $body = $curl->getBody();

        if ($status !== HttpResponse::STATUS_CODE_200) {
            $this->logger->error(sprintf(
                '%s returned HTTP %d after %s (order_id: %s), body: %s',
                $path,
                $status,
                $elapsed,
                $orderId,
                $this->logSanitizer->excerpt($body)
            ));

            throw new FlittApiException(__('Flitt API returned HTTP %1 for %2.', $status, $path));
        }

        try {
            $decoded = $this->json->unserialize($body);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error(sprintf(
                '%s returned a non-JSON body after %s (order_id: %s), body: %s',
                $path,
                $elapsed,
                $orderId,
                $this->logSanitizer->excerpt($body)
            ));

            throw new FlittApiException(__('Flitt API returned a non-JSON body for %1.', $path), $e);
        }

        $response = is_array($decoded) ? ($decoded['response'] ?? null) : null;

        if (!is_array($response)) {
            $this->logger->error(sprintf(
                '%s response envelope is missing or malformed (order_id: %s), body: %s',
                $path,
                $orderId,
                $this->logSanitizer->excerpt($body)
            ));

            throw new FlittApiException(
                __('Flitt API response for %1 has a missing or malformed "response" envelope.', $path)
            );
        }

        if (($response['response_status'] ?? null) !== 'success') {
            $errorCode = is_scalar($response['error_code'] ?? null) ? $response['error_code'] : '?';
            $errorMessage = is_scalar($response['error_message'] ?? null)
                ? (string)$response['error_message']
                : 'unknown error';
            $requestId = is_scalar($response['request_id'] ?? null) ? (string)$response['request_id'] : '-';

            $this->logger->error(sprintf(
                '%s failed after %s (order_id: %s): error %s "%s", request_id: %s',
                $path,
                $elapsed,
                $orderId,
                $errorCode,
                $this->logSanitizer->line($errorMessage),
                $this->logSanitizer->line($requestId)
            ));

            throw new FlittApiException(
                __(
                    'Flitt API error %1 for %2: %3 (request_id: %4)',
                    $errorCode,
                    $path,
                    $errorMessage,
                    $requestId
                ),
                apiErrorCode: is_numeric($errorCode) ? (int)$errorCode : null
            );
        }

        if (($endpoint->requiresSignedResponse() || isset($response['signature']))
            && !$this->signature->verify($response, $secretKey)
        ) {
            $this->logger->error(sprintf(
                '%s response signature verification FAILED (order_id: %s), body: %s',
                $path,
                $orderId,
                $this->logSanitizer->excerpt($body)
            ));

            throw new FlittApiException(__('Flitt API response signature verification failed for %1.', $path));
        }

        $orderStatus = $response['order_status'] ?? null;

        $this->logger->info(sprintf(
            '%s succeeded in %s (order_id: %s%s)',
            $path,
            $elapsed,
            $orderId,
            is_scalar($orderStatus) ? ', order_status: ' . $this->logSanitizer->line((string)$orderStatus) : ''
        ));

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug("$path response", ['response' => $this->logSanitizer->mask($response)]);
        }

        return $response;
    }

    /**
     * Milliseconds since $startedAt, as a human-readable "123ms" string.
     *
     * @param float $startedAt
     * @return string
     */
    private function elapsed(float $startedAt): string
    {
        return (int)round((microtime(true) - $startedAt) * 1000) . 'ms';
    }
}
