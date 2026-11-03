<?php

declare(strict_types=1);

namespace Shubo\Flitt\Gateway\ValueHandler;

use Magento\Payment\Gateway\Config\ValueHandlerInterface;
use Shubo\Flitt\Gateway\Config;
use Shubo\Flitt\Model\Config\Source\CheckoutType;

/**
 * can_initialize - Computed based on checkout type [payment/shubo_flitt/checkout_type].
 * Tells Magento if it should create an order before payment authotized/approved.
 */
class CanInitializeHandler implements ValueHandlerInterface
{
    /**
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * @param array<string, mixed> $subject
     * @param int|null $storeId
     * @return bool
     */
    public function handle(array $subject, $storeId = null): bool
    {
        return $this->config->getCheckoutType($storeId !== null ? (int)$storeId : null)
            === CheckoutType::REDIRECT;
    }
}
