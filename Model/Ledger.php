<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Persistent per-mint ledger of Flitt-side orders (shubo_flitt_order).
 *
 * The callback resolves a POSTed order_id to its store — and so
 * to the store-scoped secret — through this table BEFORE any signature can
 * be verified; the reconciler sweeps it for holds whose order
 * never materialized. Rows deliberately outlive the quote: the quote
 * cleaner may delete an abandoned quote long before the hold is dealt
 * with, and quote.reserved_order_id is unindexed anyway.
 *
 * The `state` column is OUR lifecycle summary (see the STATE_* rank order),
 * not Flitt's raw order_status — that is recorded verbatim in its own
 * column by recordCallback().
 */
class Ledger
{
    public const string TABLE = 'shubo_flitt_order';

    /** Minted at Flitt, no payment outcome recorded yet. */
    public const string STATE_MINTED = 'minted';

    /** Last payment attempt declined (same token stays retryable, delayed=Y). */
    public const string STATE_DECLINED = 'declined';

    /** Never paid within its lifetime. */
    public const string STATE_EXPIRED = 'expired';

    /** Hold approved, but not reflected on a Magento order (yet) — the
     * paid-but-not-placed resume state; the reconciler decides when it is
     * an orphan to reverse. */
    public const string STATE_APPROVED = 'approved';

    /** Reflected on a placed Magento order (captured or open auth). */
    public const string STATE_SETTLED = 'settled';

    /** Hold/charge reversed at Flitt. */
    public const string STATE_REVERSED = 'reversed';

    /**
     * Forward-only lifecycle rank: out-of-order or replayed callbacks must
     * never downgrade a state (e.g. a retried "declined" delivery arriving
     * after the later attempt's "approved" already landed). `expired` ranks
     * ABOVE `declined`: with delayed=Y a declined attempt keeps the session
     * payable, so `declined` is an attempt outcome the reconciler must still
     * expire once the lifetime runs out.
     */
    private const array STATE_RANK = [
        self::STATE_MINTED => 0,
        self::STATE_DECLINED => 1,
        self::STATE_EXPIRED => 2,
        self::STATE_APPROVED => 3,
        self::STATE_SETTLED => 4,
        self::STATE_REVERSED => 5,
    ];

    /**
     * Callback-borne facts recordCallback() may write — includes the vault
     * readiness set (rectoken/card metadata) captured from day one.
     */
    private const array CALLBACK_FACT_COLUMNS = [
        'payment_id',
        'order_status',
        'response_code',
        'masked_card',
        'card_type',
        'card_bin',
        'rectoken',
        'rectoken_lifetime',
    ];

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource,
    ) {
    }

    /**
     * Record a freshly minted Flitt order.
     *
     * Called for every mint; a failure propagates and aborts the mint — a
     * token whose order the ledger cannot resolve must never reach the
     * widget, because its callback could never be settled. Idempotent on
     * flitt_order_id (unique key): a repeat refreshes the mint facts but
     * never regresses a state a later step has advanced.
     *
     * @param string $flittOrderId
     * @param int $storeId
     * @param int|null $quoteId
     * @param string $incrementId
     * @param int $amount Minor units (tetri/cents).
     * @param string $currency
     * @param int $lifetime Seconds the Flitt order stays payable.
     * @return void
     */
    public function recordMint(
        string $flittOrderId,
        int $storeId,
        ?int $quoteId,
        string $incrementId,
        int $amount,
        string $currency,
        int $lifetime,
    ): void {
        $this->resource->getConnection()->insertOnDuplicate(
            $this->resource->getTableName(self::TABLE),
            [
                'flitt_order_id' => $flittOrderId,
                'store_id' => $storeId,
                'quote_id' => $quoteId,
                'increment_id' => $incrementId,
                'amount' => $amount,
                'currency' => $currency,
                'lifetime' => $lifetime,
                'state' => self::STATE_MINTED,
            ],
            ['store_id', 'quote_id', 'increment_id', 'amount', 'currency', 'lifetime']
        );
    }

    /**
     * The ledger row for a Flitt-side order id, or null when unknown.
     *
     * @param string $flittOrderId
     * @return array<string, mixed>|null
     */
    public function find(string $flittOrderId): ?array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->where('flitt_order_id = ?', $flittOrderId);

        $row = $connection->fetchRow($select);

        return is_array($row) ? $row : null;
    }

    /**
     * The quote's sessions in one of $states, newest first (served by the quote_id index).
     *
     * @param int $quoteId
     * @param string[] $states STATE_* constants.
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function forQuote(int $quoteId, array $states, int $limit): array
    {
        $connection = $this->resource->getConnection();

        return $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE))
                ->where('quote_id = ?', $quoteId)
                ->where('state IN (?)', $states)
                ->order('entity_id DESC')
                ->limit($limit)
        );
    }

    /**
     * Record the facts a (signature-verified) callback delivered.
     *
     * Null facts are skipped, never overwriting a previously recorded value
     * with nothing (e.g. a retried early callback without a rectoken must
     * not erase the token a later one carried). Bumps callback_count and
     * last_callback_at regardless.
     *
     * @param string $flittOrderId
     * @param array<string, mixed> $facts Subset of CALLBACK_FACT_COLUMNS.
     * @return void
     */
    public function recordCallback(string $flittOrderId, array $facts): void
    {
        $bind = array_filter(
            array_intersect_key($facts, array_flip(self::CALLBACK_FACT_COLUMNS)),
            static fn ($value): bool => $value !== null
        );
        $bind['callback_count'] = new \Zend_Db_Expr('callback_count + 1');
        $bind['last_callback_at'] = new \Zend_Db_Expr('CURRENT_TIMESTAMP');

        $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            $bind,
            ['flitt_order_id = ?' => $flittOrderId]
        );
    }

    /**
     * Rows sitting in one of $states whose $column timestamp is at or before
     * $cutoff — the reconciler's work queue (served by the state,created_at
     * index; oldest first so a backlog drains in arrival order).
     *
     * @param string|array<int, string> $states STATE_* constant(s).
     * @param string $column 'created_at' (mint age) or 'updated_at' (state age).
     * @param string $cutoff UTC 'Y-m-d H:i:s'.
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public function dueRows(string|array $states, string $column, string $cutoff, int $limit): array
    {
        if (!in_array($column, ['created_at', 'updated_at'], true)) {
            throw new \InvalidArgumentException("Unsupported due-row column \"$column\".");
        }

        $connection = $this->resource->getConnection();

        return $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE))
                ->where('state IN (?)', (array)$states)
                ->where("$column <= ?", $cutoff)
                ->order('created_at ASC')
                ->limit($limit)
        );
    }

    /**
     * Advance the lifecycle state; refuses downgrades (see STATE_RANK).
     *
     * ATOMIC: the rank guard lives in the UPDATE's WHERE clause, so callers
     * need no external serialization — a lock-free "settled" can never
     * overwrite a concurrent "reversed" (MarkLedgerSettled and the
     * refund/void commands transition without holding the callback lock).
     *
     * @param string $flittOrderId
     * @param string $newState One of the STATE_* constants.
     * @return bool Whether the state was applied (false = refused downgrade
     *     or unknown row; same-state repeats return true).
     */
    public function transition(string $flittOrderId, string $newState): bool
    {
        $newRank = self::STATE_RANK[$newState] ?? -1;

        if ($newRank < 0) {
            return false;
        }

        $fromStates = array_keys(array_filter(
            self::STATE_RANK,
            static fn (int $rank): bool => $rank < $newRank
        ));

        $updated = $fromStates === [] ? 0 : $this->resource->getConnection()->update(
            $this->resource->getTableName(self::TABLE),
            ['state' => $newState],
            ['flitt_order_id = ?' => $flittOrderId, 'state IN (?)' => $fromStates]
        );

        if ($updated > 0) {
            return true;
        }

        // No row advanced: same-state repeat (true), downgrade or unknown
        // row (false) — one read distinguishes them.
        $row = $this->find($flittOrderId);

        return $row !== null && (string)$row['state'] === $newState;
    }
}
