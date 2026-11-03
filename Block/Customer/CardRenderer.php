<?php

declare(strict_types=1);

namespace Shubo\Flitt\Block\Customer;

use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Block\AbstractCardRenderer;
use Shubo\Flitt\Model\Ui\ConfigProvider;

/**
 * Renders a stored Flitt card on My Account → Stored Payment Methods
 * (vault/cards/listaction). Checkout and this page use DIFFERENT plumbing:
 * checkout goes through TokenUiComponentProvider, the account page asks its
 * child CardRenderer blocks — a token no renderer claims is silently
 * skipped, so without this class saved cards are invisible there. Facts
 * come from the token details TokenPersister wrote (type / maskedCC /
 * expirationDate — the TOKEN's expiry, Flitt never discloses the card's).
 */
class CardRenderer extends AbstractCardRenderer
{
    /**
     * Tokens persist with the PROVIDER method code (Vault resets the order
     * payment's method before the save).
     *
     * @param PaymentTokenInterface $token
     * @return bool
     */
    public function canRender(PaymentTokenInterface $token): bool
    {
        return $token->getPaymentMethodCode() === ConfigProvider::CODE;
    }

    /**
     * @return string
     */
    public function getNumberLast4Digits(): string
    {
        return (string)($this->getTokenDetails()['maskedCC'] ?? '');
    }

    /**
     * @return string
     */
    public function getExpDate(): string
    {
        return (string)($this->getTokenDetails()['expirationDate'] ?? '');
    }

    /**
     * @return string
     */
    public function getIconUrl(): string
    {
        return (string)$this->getIconForType($this->getCardType())['url'];
    }

    /**
     * @return int
     */
    public function getIconHeight(): int
    {
        return (int)$this->getIconForType($this->getCardType())['height'];
    }

    /**
     * @return int
     */
    public function getIconWidth(): int
    {
        return (int)$this->getIconForType($this->getCardType())['width'];
    }

    /**
     * @return string
     */
    private function getCardType(): string
    {
        return (string)($this->getTokenDetails()['type'] ?? '');
    }
}
