<?php

declare(strict_types=1);

namespace Shubo\Flitt\Plugin\Vault;

use Magento\Store\Model\StoreManagerInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Model\CustomerTokenManagement;
use Shubo\Flitt\Model\Vault\TokenMerchant;

/**
 * Checkout lists the customer's saved cards through
 * CustomerTokenManagement::getCustomerSessionTokens(); Flitt cards issued by
 * another merchant than this store's are left out (TokenMerchant).
 */
class HideForeignMerchantTokens
{
    /**
     * @param TokenMerchant $tokenMerchant
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly TokenMerchant $tokenMerchant,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * Leave out Flitt cards issued by another merchant.
     *
     * @param CustomerTokenManagement $subject
     * @param PaymentTokenInterface[] $result
     * @return PaymentTokenInterface[]
     */
    public function afterGetCustomerSessionTokens(CustomerTokenManagement $subject, array $result): array
    {
        $storeId = (int)$this->storeManager->getStore()->getId();

        return array_values(array_filter(
            $result,
            fn (PaymentTokenInterface $token): bool => $this->tokenMerchant->belongsToStore($token, $storeId)
        ));
    }
}
