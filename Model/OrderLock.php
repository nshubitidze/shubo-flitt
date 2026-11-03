<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model;

use Magento\Framework\Lock\LockManagerInterface;

/**
 * THE serialization point for all money-adjacent work on one Magento order:
 * callback settling (Processor), redirect-mode minting (Controller/Redirect/
 * Start) and the reconciler all contend on the SAME name, so a
 * mint can never interleave with a settle of the same order, and two mints
 * can never race each other. Keyed by increment id — every Flitt-side order
 * id of one Magento order maps to one lock, which a flitt-order-id key would
 * not give (re-mints create new ids).
 */
class OrderLock
{
    /**
     * Hashed — increment ids are short today, but the lock backend's 64-char
     * name limit must never become a correctness concern.
     */
    private const string LOCK_PREFIX = 'FLITT_ORD_';
    private const int LOCK_TIMEOUT_SECONDS = 10;

    /**
     * @param LockManagerInterface $lockManager
     */
    public function __construct(
        private readonly LockManagerInterface $lockManager,
    ) {
    }

    /**
     * Acquire the order's lock, waiting up to the shared timeout.
     *
     * @param string $incrementId
     * @return bool False when the lock could not be acquired in time.
     */
    public function acquire(string $incrementId): bool
    {
        return $this->lockManager->lock(self::LOCK_PREFIX . sha1($incrementId), self::LOCK_TIMEOUT_SECONDS);
    }

    /**
     * Release the order's lock.
     *
     * @param string $incrementId
     * @return void
     */
    public function release(string $incrementId): void
    {
        $this->lockManager->unlock(self::LOCK_PREFIX . sha1($incrementId));
    }
}
