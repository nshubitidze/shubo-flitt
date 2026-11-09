<?php

declare(strict_types=1);

namespace Shubo\Flitt\Exception;

use Magento\Payment\Gateway\Command\CommandException;

/**
 * The delivered Flitt order id is not the payment session cached on the
 * order — the payment belongs to a superseded mint. Terminal for THIS
 * delivery: the cached binding never reverts, so retrying cannot heal it.
 * The callback matrix parks the hold (ledger 'approved') for the
 * reconciler's reversal sweep instead of asking Flitt to redeliver.
 */
class PaymentSessionMismatchException extends CommandException
{
}
