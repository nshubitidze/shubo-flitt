<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Checkout;

use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Exception\FlittApiException;
use Shubo\Flitt\Gateway\Config;
use Shubo\Flitt\Model\FlittClient;
use Shubo\Flitt\Model\Money;

/**
 * Classifies the quote's Flitt payment session for the checkout JS — the
 * server-side answer to "is it safe to (re-)pay?" after a placement failure,
 * a page refresh, or a mount against an already-paid token.
 *
 * Mirrors PaymentVerifier's checks but against the live QUOTE (no order
 * exists yet) and folds the outcome into a coarse advice state instead of
 * exceptions. Placement itself always re-verifies through PaymentVerifier —
 * this resolver only drives UI decisions and can afford to be coarse.
 *
 * When the cached session is not paid, the quote's OTHER sessions are checked
 * too (PaidSessionFinder): an approved older session — paid in a second tab,
 * or before a totals change — makes the quote BLOCKED, never "safe to re-pay".
 */
class PaymentStateResolver
{
    /** No Flitt payment session exists on the quote. */
    public const string STATE_NONE = 'none';

    /** Not approved (created/processing/declined/expired) — safe to (re-)pay. */
    public const string STATE_UNPAID = 'unpaid';

    /** Approved and matches the current quote — finish placement, do NOT re-pay. */
    public const string STATE_PAID = 'paid';

    /** Approved but does NOT match the quote (amount/currency/mode/reversal) — needs review, never re-pay. */
    public const string STATE_BLOCKED = 'blocked';

    /** Status API unreachable — decide nothing. */
    public const string STATE_UNKNOWN = 'unknown';

    /**
     * @param FlittClient $client
     * @param Config $config
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     * @param PaidSessionFinder $paidSessionFinder
     */
    public function __construct(
        private readonly FlittClient $client,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        private readonly PaidSessionFinder $paidSessionFinder,
    ) {
    }

    /**
     * Resolve the advice state for the quote: its cached session, then any other approved session.
     *
     * @param Quote $quote
     * @return string One of the STATE_* constants.
     */
    public function resolve(Quote $quote): string
    {
        $state = $this->resolveCached($quote);

        if ($state !== self::STATE_NONE && $state !== self::STATE_UNPAID) {
            return $state;
        }

        $cachedOrderId = (string)$quote->getPayment()->getAdditionalInformation(TokenManager::INFO_ORDER_ID);

        try {
            $other = $this->paidSessionFinder->findApproved($quote, $cachedOrderId);
        } catch (FlittApiException $e) {
            // FlittClient already logged the failure in detail.
            return self::STATE_UNKNOWN;
        }

        if ($other === null) {
            return $state;
        }

        $this->logger->error(sprintf(
            'state: quote %d has an approved session %s that is not its current one (%s) — advising BLOCKED.'
            . ' The reconciler reverses the hold if the quote is not placed with it.',
            (int)$quote->getId(),
            $other,
            $cachedOrderId !== '' ? $cachedOrderId : 'none'
        ));

        return self::STATE_BLOCKED;
    }

    /**
     * Classify the quote's cached payment session only.
     *
     * @param Quote $quote
     * @return string One of the STATE_* constants.
     */
    private function resolveCached(Quote $quote): string
    {
        $payment = $quote->getPayment();
        $flittOrderId = (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID);

        if ($flittOrderId === '') {
            return self::STATE_NONE;
        }

        $storeId = (int)$quote->getStoreId();

        // A session minted for another reserved id / prefix is not this
        // quote's payment — a fresh mount re-mints, so re-paying is safe.
        $prefix = $this->config->getOrderIdPrefix($storeId);
        $expectedHead = ($prefix !== '' ? $prefix . '_' : '') . $quote->getReservedOrderId();

        if ($this->orderIdHead($flittOrderId) !== $expectedHead) {
            return self::STATE_UNPAID;
        }

        try {
            $status = $this->client->getOrderStatus($flittOrderId, $storeId);
        } catch (FlittApiException $e) {
            // FlittClient already logged the failure in detail.
            return self::STATE_UNKNOWN;
        }

        if ((string)($status['order_id'] ?? '') !== $flittOrderId) {
            return self::STATE_UNKNOWN;
        }

        if ((string)($status['order_status'] ?? '') !== 'approved') {
            return self::STATE_UNPAID;
        }

        // Approved: the held money must match the CURRENT quote exactly and
        // must be a hold (authorize-first mints preauth=Y always) — anything
        // else is the do-not-re-pay class. Keep in sync with
        // PaymentVerifier::verify().
        $expectedAmount = Money::toMinorUnits($quote->getGrandTotal());
        $mintedPreauth = (string)$payment->getAdditionalInformation(TokenManager::INFO_PREAUTH);

        $matches = (int)($status['amount'] ?? -1) === $expectedAmount
            && (string)($status['currency'] ?? '') === (string)$quote->getQuoteCurrencyCode()
            && (int)($status['reversal_amount'] ?? 0) === 0
            && (int)($status['merchant_id'] ?? 0) === $this->config->getMerchantId($storeId)
            && $mintedPreauth === 'Y';

        if (!$matches) {
            $this->logger->error(sprintf(
                'state: APPROVED flitt order %s does not match quote %d (paid %d %s, reversed %d,'
                . ' preauth %s) — advising BLOCKED. Money may need review/reversal.',
                $flittOrderId,
                (int)$quote->getId(),
                (int)($status['amount'] ?? -1),
                (string)($status['currency'] ?? ''),
                (int)($status['reversal_amount'] ?? 0),
                $mintedPreauth
            ));

            return self::STATE_BLOCKED;
        }

        return self::STATE_PAID;
    }

    /**
     * Everything before the timestamp tail: "{prefix}_{increment}".
     *
     * @param string $orderId
     * @return string
     */
    private function orderIdHead(string $orderId): string
    {
        return substr($orderId, 0, (int)strrpos($orderId, '_'));
    }
}
