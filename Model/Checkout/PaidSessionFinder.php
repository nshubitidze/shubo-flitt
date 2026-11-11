<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Checkout;

use Magento\Quote\Model\Quote;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Exception\FlittApiException;
use Shubo\Flitt\Model\FlittClient;
use Shubo\Flitt\Model\Ledger;

/**
 * Finds a payment session of the quote that Flitt has approved and that is not fully reversed, among ALL the
 * sessions minted for it — not only the one cached on the quote.
 *
 * A quote collects several sessions: a re-mint after a totals change, a second tab that still shows an older
 * form, a login that changes the minted fields. Paying an older session leaves real money on a session the quote
 * no longer points at, and the callback that would mark it in the ledger can arrive later than the next request
 * (or never, on a store Flitt cannot reach). So the ledger only lists the candidates; Flitt's signed status
 * decides. A newly seen approval is written to the ledger, where the reconciler picks up an orphaned hold.
 */
class PaidSessionFinder
{
    /** Ledger states that may still be (or already are) an approved payment. */
    private const array CANDIDATE_STATES = [
        Ledger::STATE_MINTED,
        Ledger::STATE_DECLINED,
        Ledger::STATE_APPROVED,
    ];

    private const string FLITT_APPROVED = 'approved';

    /**
     * @param Ledger $ledger
     * @param FlittClient $client
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     * @param int $maxSessions Sessions checked per quote, newest first.
     */
    public function __construct(
        private readonly Ledger $ledger,
        private readonly FlittClient $client,
        private readonly LoggerInterface $logger,
        private readonly int $maxSessions = 10,
    ) {
    }

    /**
     * The Flitt order id of an approved, not fully reversed session of the quote, or null.
     *
     * @param Quote $quote
     * @param string $exceptOrderId A session the caller has already classified.
     * @return string|null
     * @throws FlittApiException When Flitt's status cannot be read — the caller must not assume "unpaid".
     */
    public function findApproved(Quote $quote, string $exceptOrderId = ''): ?string
    {
        $quoteId = (int)$quote->getId();

        if ($quoteId === 0) {
            return null;
        }

        $storeId = (int)$quote->getStoreId() ?: null;

        foreach ($this->ledger->forQuote($quoteId, self::CANDIDATE_STATES, $this->maxSessions) as $row) {
            $flittOrderId = (string)$row['flitt_order_id'];

            if ($flittOrderId === $exceptOrderId) {
                continue;
            }

            $status = $this->client->getOrderStatus($flittOrderId, $storeId);

            if ((string)($status['order_status'] ?? '') !== self::FLITT_APPROVED
                || (int)($status['reversal_amount'] ?? 0) >= (int)($status['amount'] ?? 0)
            ) {
                continue;
            }

            if ((string)$row['state'] !== Ledger::STATE_APPROVED) {
                $this->ledger->transition($flittOrderId, Ledger::STATE_APPROVED);
            }

            $this->logger->warning(sprintf(
                'quote %d: session %s is approved (%d %s) — no new payment session is offered for this quote',
                $quoteId,
                $flittOrderId,
                (int)($status['amount'] ?? 0),
                (string)($status['currency'] ?? '')
            ));

            return $flittOrderId;
        }

        return null;
    }
}
