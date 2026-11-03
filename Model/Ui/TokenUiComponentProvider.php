<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Ui;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Model\Ui\TokenUiComponentInterface;
use Magento\Vault\Model\Ui\TokenUiComponentInterfaceFactory;
use Magento\Vault\Model\Ui\TokenUiComponentProviderInterface;

/**
 * Checkout UI component for a stored Flitt card (registered under the
 * provider code in Magento_Vault's TokensConfigProvider). The renderer is a
 * thin extension of the core vault component (the core base alone renders
 * blank and posts an empty public_hash); the charge itself is entirely
 * server-side (VaultSale/VaultAuthorize commands).
 */
class TokenUiComponentProvider implements TokenUiComponentProviderInterface
{
    /**
     * @param TokenUiComponentInterfaceFactory $componentFactory
     * @param Json $json
     */
    public function __construct(
        private readonly TokenUiComponentInterfaceFactory $componentFactory,
        private readonly Json $json,
    ) {
    }

    /**
     * @param PaymentTokenInterface $paymentToken
     * @return TokenUiComponentInterface
     */
    public function getComponentForToken(PaymentTokenInterface $paymentToken): TokenUiComponentInterface
    {
        $details = $this->json->unserialize($paymentToken->getTokenDetails() ?: '{}');

        return $this->componentFactory->create([
            'config' => [
                'code' => ConfigProvider::VAULT_CODE,
                TokenUiComponentProviderInterface::COMPONENT_DETAILS => is_array($details) ? $details : [],
                TokenUiComponentProviderInterface::COMPONENT_PUBLIC_HASH => $paymentToken->getPublicHash(),
            ],
            'name' => 'Shubo_Flitt/js/view/payment/method-renderer/vault',
        ]);
    }
}
