<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;

/**
 * Currencies Flitt can process for Georgian merchants (Flitt docs: currencies).
 */
class Currency implements OptionSourceInterface
{
    /**
     * Options for the allowed_currencies admin multiselect.
     *
     * @return array<int, array{value: string, label: Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'GEL', 'label' => __('Georgian Lari (GEL)')],
            ['value' => 'USD', 'label' => __('US Dollar (USD)')],
            ['value' => 'EUR', 'label' => __('Euro (EUR)')],
        ];
    }
}
