<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Checkout;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Gateway\Command\CommandException;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Exception\FlittApiException;
use Shubo\Flitt\Model\FlittClient;
use Shubo\Flitt\Model\Money;

/**
 * Captures a verified Flitt hold at placement time — the authorize-first
 * flow's actual money movement, run in the same breath as order creation.
 *
 * Trust rules match the rest of the pay-first design: the synchronous
 * capture response is advisory only; success is confirmed exclusively via
 * the SIGNED status envelope's additional_info (capture_status /
 * capture_amount). Capture is retry-safe per the Flitt docs, so a repeated
 * placement attempt after a transient failure re-issues it harmlessly.
 */
class HoldCapture
{
    /** additional_info.capture_status: the amount is held, not yet charged. */
    public const string CAPTURE_STATUS_HOLD = 'hold';

    /** additional_info.capture_status: the hold was captured (charged). */
    public const string CAPTURE_STATUS_CAPTURED = 'captured';

    /** additional_info.payment_method of a plain card payment. */
    public const string METHOD_CARD = 'card';

    /**
     * @param FlittClient $client
     * @param Json $json
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly FlittClient $client,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Ensure the verified hold is captured for exactly the verified amount.
     *
     * @param string $flittOrderId
     * @param array<string, mixed> $status The verified signed status envelope.
     * @param int $storeId
     * @return void
     * @throws CommandException When the capture cannot be confirmed.
     */
    public function ensureCaptured(string $flittOrderId, array $status, int $storeId): void
    {
        $expectedAmount = (int)($status['amount'] ?? 0);
        $currency = (string)($status['currency'] ?? '');

        // The signed capture_status decides, not the payment method: a Google
        // Pay payment made with preauth=Y came back as a hold that had to be
        // captured, although the payment_method was "googlepay" and Flitt's
        // docs limit preauth/capture to "the card payment method".
        if ($this->isOneStageCharge($status)) {
            $this->logger->info(sprintf(
                'capture: flitt order %s paid via "%s" with no capture status — one-stage charge,'
                . ' nothing to capture',
                $flittOrderId,
                $this->chargeMethod($status)
            ));

            return;
        }

        $alreadyCaptured = $this->capturedAmount($status);

        if ($alreadyCaptured === $expectedAmount) {
            // Already captured for the exact amount (retry after a transient
            // placement failure, or a concurrent settle path won the race).
            return;
        }

        if ($alreadyCaptured > 0) {
            // Captured for a DIFFERENT amount (e.g. a partial capture made in
            // the Flitt portal): re-issuing the capture is a guaranteed 1109
            // ("already captured with different amount") — fail fast as a
            // reconciliation case instead of advising a doomed retry.
            $this->logger->error(sprintf(
                'capture: flitt order %s already captured for %d, expected %d %s — needs reconciliation.',
                $flittOrderId,
                $alreadyCaptured,
                $expectedAmount,
                $currency
            ));

            throw new CommandException(
                __('The payment state at Flitt does not match this order and needs review. Please contact support.')
            );
        }

        try {
            $response = $this->client->captureOrder($flittOrderId, $expectedAmount, $currency, $storeId);
        } catch (FlittApiException $e) {
            // FlittClient already logged the failure in detail. The hold is
            // intact and capture is retry-safe — placement may be retried.
            throw new CommandException(
                __('Your payment could not be completed right now. Please try again in a moment.')
            );
        }

        $captureStatus = (string)($response['capture_status'] ?? '');

        if ($captureStatus === self::CAPTURE_STATUS_HOLD) {
            // Capture accepted but not final yet — the customer may retry
            // placement in a moment; the status probe below would race it.
            throw new CommandException(
                __('Your payment is still being processed. Please wait a moment and try again.')
            );
        }

        // The sync response is advisory — confirm via the signed envelope.
        try {
            $confirmed = $this->client->getOrderStatus($flittOrderId, $storeId);
        } catch (FlittApiException $e) {
            throw new CommandException(
                __('Your payment could not be verified right now. Please try again in a moment.')
            );
        }

        if ($this->capturedAmount($confirmed) !== $expectedAmount) {
            $this->logger->error(sprintf(
                'capture: flitt order %s capture NOT confirmed (advisory capture_status "%s",'
                . ' signed capture_amount %d, expected %d %s) — placement rejected.',
                $flittOrderId,
                $captureStatus,
                $this->capturedAmount($confirmed),
                $expectedAmount,
                $currency
            ));

            throw new CommandException(
                __('Your payment could not be completed right now. Please try again in a moment.')
            );
        }

        $this->logger->info(sprintf(
            'capture: flitt order %s captured %d %s (confirmed via signed status)',
            $flittOrderId,
            $expectedAmount,
            $currency
        ));
    }

    /**
     * Captured amount in MINOR units from a signed status envelope, 0 when
     * nothing is captured yet. Public: the shared parser for every caller
     * that must branch on capture state (CaptureCommand, VoidCommand).
     *
     * additional_info is an embedded JSON string whose capture_amount is in
     * MAJOR units (e.g. 1.0 == 100 minor) — unlike every top-level amount.
     *
     * @param array<string, mixed> $status
     * @return int
     */
    public function capturedAmount(array $status): int
    {
        $info = $this->additionalInfo($status);

        if ($this->captureStatus($status) !== self::CAPTURE_STATUS_CAPTURED) {
            return 0;
        }

        return Money::toMinorUnits($info['capture_amount'] ?? 0);
    }

    /**
     * Whether the payment was charged in one stage, so there is no hold to
     * capture or release: a non-card rail (Apple/Google Pay) whose signed
     * status reports no capture_status at all. A wallet payment that reports
     * "hold" is a hold like a card's and is captured. Public: shared with
     * VoidCommand's charged-vs-held logging.
     *
     * @param array<string, mixed> $status
     * @return bool
     */
    public function isOneStageCharge(array $status): bool
    {
        $method = $this->chargeMethod($status);

        return $this->captureStatus($status) === ''
            && $method !== ''
            && $method !== self::METHOD_CARD;
    }

    /**
     * The signed envelope's additional_info.capture_status ("hold",
     * "captured"), '' when absent.
     *
     * @param array<string, mixed> $status
     * @return string
     */
    public function captureStatus(array $status): string
    {
        $captureStatus = $this->additionalInfo($status)['capture_status'] ?? '';

        return is_string($captureStatus) ? strtolower($captureStatus) : '';
    }

    /**
     * The rail the payment was made over, from the signed envelope's
     * additional_info.payment_method: "card", "apple", "googlepay" — '' when
     * the field is absent (callers must then assume card/two-stage, the
     * conservative default: a capture on an already-charged rail fails
     * loudly, the reverse would double-charge silently). Public: shared with
     * VoidCommand's charged-vs-held logging.
     *
     * @param array<string, mixed> $status
     * @return string
     */
    public function chargeMethod(array $status): string
    {
        $method = $this->additionalInfo($status)['payment_method'] ?? '';

        return is_string($method) ? strtolower($method) : '';
    }

    /**
     * The envelope's embedded additional_info JSON, [] when absent/malformed.
     *
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function additionalInfo(array $status): array
    {
        $raw = $status['additional_info'] ?? '';

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $info = $this->json->unserialize($raw);
        } catch (\InvalidArgumentException $e) {
            return [];
        }

        return is_array($info) ? $info : [];
    }
}
