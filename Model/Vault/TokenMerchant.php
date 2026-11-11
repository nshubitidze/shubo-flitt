<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Vault;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Shubo\Flitt\Gateway\Config;
use Shubo\Flitt\Model\Ui\ConfigProvider;

/**
 * Binds a saved card to the Flitt merchant that issued it. A rectoken is
 * only valid at that merchant: after the store switches merchants (test →
 * live), or on a website with another merchant while customer accounts are
 * shared, the card would be offered and then fail. TokenPersister records the
 * merchant in the token details; checkout, My Account and the charge offer
 * or use a card only for the store's own merchant. A card without a recorded
 * merchant cannot be checked and is treated as foreign.
 */
class TokenMerchant
{
    /**
     * Token-details key holding the issuing Flitt merchant id.
     */
    public const string DETAIL_KEY = 'merchantId';

    /**
     * @param Config $config
     * @param Json $json
     */
    public function __construct(
        private readonly Config $config,
        private readonly Json $json,
    ) {
    }

    /**
     * Whether a token may be shown or charged in this store.
     *
     * Tokens of other payment methods are not this class's business and always pass.
     *
     * @param PaymentTokenInterface $token
     * @param int|null $storeId
     * @return bool
     */
    public function belongsToStore(PaymentTokenInterface $token, ?int $storeId): bool
    {
        if ($token->getPaymentMethodCode() !== ConfigProvider::CODE) {
            return true;
        }

        $merchantId = $this->config->getMerchantId($storeId);

        return $merchantId > 0 && $this->issuingMerchant($token) === $merchantId;
    }

    /**
     * The merchant id recorded on the token, 0 when none.
     *
     * @param PaymentTokenInterface $token
     * @return int
     */
    public function issuingMerchant(PaymentTokenInterface $token): int
    {
        try {
            $details = $this->json->unserialize((string)$token->getTokenDetails());
        } catch (\InvalidArgumentException) {
            return 0;
        }

        return is_array($details) ? (int)($details[self::DETAIL_KEY] ?? 0) : 0;
    }
}
