<?php

declare(strict_types=1);

namespace Shubo\Flitt\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * The order's cached Flitt mint is already approved (or settled) per the
 * ledger — real money is attached to it, so a new mint must NOT be created
 * over it. The redirect handoff surfaces this as "your payment is being
 * processed" instead of handing out a second payable page.
 */
class PaymentInFlightException extends LocalizedException
{
}
