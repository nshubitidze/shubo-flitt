<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Checkout;

/**
 * What the browser return resolved to — the Complete controller maps each
 * case onto STANDARD Magento pages (success page / cart), never custom ones.
 */
enum ReturnDisposition: string
{
    /** The order is (now) paid and settled — show the standard success page. */
    case Success = 'success';

    /** Money may be in flight or parked — keep the order, neutral notice. */
    case Processing = 'processing';

    /** Nothing was paid — restore the cart and let them retry. The order is
     * cancelled only when its session is terminally dead; a still-payable
     * session leaves it pending for the callback/reconciler. */
    case Failed = 'failed';

    /** Paid AFTER the order was already cancelled — restore the cart,
     * tell the customer the money comes back; the reconciler reverses it. */
    case LatePaid = 'late_paid';

    /** No trustworthy way to tell which payment this is. */
    case Unknown = 'unknown';
}
