<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;

/**
 * Checkout type options: embedded card form vs redirect to the Flitt payment page.
 */
class CheckoutType implements OptionSourceInterface
{
    public const string EMBED = 'embed';
    public const string REDIRECT = 'redirect';

    /**
     * Options for the checkout_type admin config field.
     *
     * @return array<int, array{value: string, label: Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::EMBED, 'label' => __('Embedded Card Form')],
            ['value' => self::REDIRECT, 'label' => __('Redirect to Payment Page')],
        ];
    }
}
