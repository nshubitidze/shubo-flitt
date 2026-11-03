<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model;

/**
 * Flitt takes and returns amounts as integer minor units (tetri, cents);
 * Magento keeps totals as decimal major units. The one place that converts
 * between them.
 *
 * Every currency the module accepts (GEL, USD, EUR) has two decimal places.
 */
class Money
{
    private const int MINOR_UNITS_PER_MAJOR = 100;

    /**
     * Major units (a Magento total, or a decimal Flitt field such as
     * additional_info.capture_amount) → integer minor units, e.g. 15.5 → 1550.
     * A missing total (null) counts as 0.
     *
     * @param int|float|string|null $amount
     * @return int
     */
    public static function toMinorUnits(int|float|string|null $amount): int
    {
        return (int)round((float)$amount * self::MINOR_UNITS_PER_MAJOR);
    }
}
