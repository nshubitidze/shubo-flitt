<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\MethodInterface;
use Shubo\Flitt\Gateway\Config;

/**
 * Single source of truth for the payment method code, and the method's
 * window.checkoutConfig payload (registered via etc/frontend/di.xml).
 */
class ConfigProvider implements ConfigProviderInterface
{
    public const string CODE = 'shubo_flitt';

    /**
     * The saved-cards method (Magento\Vault\Model\Method\Vault facade over
     * this provider). After placement Vault resets the order payment's
     * method back to CODE, so only quote-time code ever sees this value.
     */
    public const string VAULT_CODE = 'shubo_flitt_vault';

    /**
     * @var MethodInterface The ShuboFlittFacade adapter, resolved by code.
     */
    private readonly MethodInterface $method;

    /**
     * @param Config $config
     * @param UrlInterface $urlBuilder
     * @param PaymentHelper $paymentHelper
     * @throws LocalizedException
     */
    public function __construct(
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder,
        PaymentHelper $paymentHelper,
    ) {
        $this->method = $paymentHelper->getMethodInstance(self::CODE);
    }

    /**
     * Checkout config for the payment method — window.checkoutConfig.payment.shubo_flitt.
     *
     * Empty when the method is unavailable, so no Flitt config leaks into
     * pages that will never render the method.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        if (!$this->method->isAvailable()) {
            return [];
        }

        return [
            'payment' => [
                self::CODE => [
                    'title' => $this->config->getTitle(),
                    'checkoutType' => $this->config->getCheckoutType(),
                    'tokenUrl' => $this->urlBuilder->getUrl('flitt/checkout/token'),
                    'stateUrl' => $this->urlBuilder->getUrl('flitt/checkout/state'),
                    'redirectUrl' => $this->urlBuilder->getUrl('flitt/redirect/start'),
                    'googlePay' => $this->config->isGooglePayEnabled(),
                    'applePay' => $this->config->isApplePayEnabled(),
                    'vaultCode' => self::VAULT_CODE,
                ],
            ],
        ];
    }
}
