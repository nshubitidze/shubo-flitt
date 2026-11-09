<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Phrase;
use Magento\Payment\Model\MethodInterface;

/**
 * Payment action options. Values are Magento's MethodInterface::ACTION_* so
 * Adapter::getConfigPaymentAction() drives order placement natively.
 */
class PaymentAction implements OptionSourceInterface
{
    /**
     * Options for the payment_action admin config field.
     *
     * @return array<int, array{value: string, label: Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => MethodInterface::ACTION_AUTHORIZE_CAPTURE, 'label' => __('Authorize & Capture')],
            ['value' => MethodInterface::ACTION_AUTHORIZE, 'label' => __('Authorize Only')],
        ];
    }
}
