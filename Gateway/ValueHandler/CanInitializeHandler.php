<?php

declare(strict_types=1);

namespace Shubo\Flitt\Gateway\ValueHandler;

use Magento\Payment\Gateway\Config\ValueHandlerInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Shubo\Flitt\Gateway\Config;
use Shubo\Flitt\Model\Config\Source\CheckoutType;
use Shubo\Flitt\Model\Ui\ConfigProvider;

/**
 * can_initialize - Computed based on checkout type [payment/shubo_flitt/checkout_type].
 * Tells Magento if it should create an order before payment is authorized/approved.
 *
 * Never for a saved-card payment: core's Vault method asks its provider (this
 * gateway) whether to initialize but cannot initialize itself — it throws
 * "Not implemented". A saved card is charged server-side during placement in
 * either checkout type, so it always takes the vault_authorize/vault_sale path.
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
     * Whether the order is created before the payment.
     *
     * @param array<string, mixed> $subject
     * @param int|null $storeId
     * @return bool
     */
    public function handle(array $subject, $storeId = null): bool
    {
        $paymentDO = $subject['payment'] ?? null;
        $payment = $paymentDO instanceof PaymentDataObjectInterface ? $paymentDO->getPayment() : null;

        if ($payment instanceof OrderPaymentInterface && $payment->getMethod() === ConfigProvider::VAULT_CODE) {
            return false;
        }

        return $this->config->getCheckoutType($storeId !== null ? (int)$storeId : null)
            === CheckoutType::REDIRECT;
    }
}
