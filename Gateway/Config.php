<?php

declare(strict_types=1);

namespace Shubo\Flitt\Gateway;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Payment\Gateway\Config\Config as GatewayConfig;
use Magento\Store\Model\ScopeInterface;
use Shubo\Flitt\Exception\FlittApiException;
use Shubo\Flitt\Model\Ui\ConfigProvider;

/**
 * Typed access to payment/shubo_flitt/* configuration — the single place that
 * knows field names, casts, and decryption of the secret key.
 */
class Config extends GatewayConfig
{
    private const string FIELD_TITLE = 'title';
    private const string FIELD_CHECKOUT_TYPE = 'checkout_type';
    private const string FIELD_MERCHANT_ID = 'merchant_id';
    private const string FIELD_SECRET_KEY = 'secret_key';
    private const string FIELD_API_URL = 'api_url';
    private const string FIELD_API_TIMEOUT = 'api_timeout';
    private const string FIELD_DEBUG = 'debug';
    private const string FIELD_PAYMENT_ACTION = 'payment_action';
    private const string FIELD_ORDER_ID_PREFIX = 'order_id_prefix';
    private const string FIELD_LIFETIME = 'lifetime';
    private const string FIELD_ALLOWED_CURRENCIES = 'allowed_currencies';
    private const string FIELD_RECONCILE_GRACE = 'reconcile_grace';
    private const string FIELD_WALLET_GOOGLE_PAY = 'wallet_google_pay';
    private const string FIELD_WALLET_APPLE_PAY = 'wallet_apple_pay';

    /**
     * The vault method's own config node — read directly (this Config
     * instance is scoped to payment/shubo_flitt/*).
     */
    private const string XML_PATH_VAULT_ACTIVE = 'payment/shubo_flitt_vault/active';

    /**
     * Guard against a missing/empty api_timeout: curl treats 0 as "no
     * timeout", which would let a checkout request hang forever.
     */
    private const int DEFAULT_TIMEOUT_SECONDS = 30;

    /**
     * Flitt's own default order lifetime (10h); guards against a
     * missing/zero lifetime value, which would create instantly-expiring
     * (or Flitt-rejected) orders.
     */
    private const int DEFAULT_LIFETIME_SECONDS = 36000;

    /**
     * Guard against a missing/zero reconcile grace: the reconciler must
     * never act on a session the instant it stops being fresh — customers
     * resume paid-but-not-placed sessions, and callbacks arrive late.
     */
    private const int DEFAULT_RECONCILE_GRACE_SECONDS = 3600;

    /**
     * Payment-page languages Flitt supports (docs: order-parameters "lang").
     */
    private const array SUPPORTED_LANGS = [
        'az', 'cs', 'da', 'de', 'en', 'es', 'fi', 'fr', 'hu', 'it',
        'ka', 'ko', 'lv', 'nl', 'pl', 'ro', 'ru', 'sk', 'uk', 'zh',
    ];

    private const string DEFAULT_LANG = 'ka';

    /**
     * Store locale config path (Magento\Directory\Helper\Data::XML_PATH_DEFAULT_LOCALE;
     * duplicated to avoid depending on Magento_Directory for one constant).
     */
    private const string XML_PATH_LOCALE = 'general/locale/code';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param string $methodCode
     * @param string $pathPattern
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        string $methodCode = ConfigProvider::CODE,
        string $pathPattern = self::DEFAULT_PATH_PATTERN,
    ) {
        parent::__construct($scopeConfig, $methodCode, $pathPattern);
    }

    /**
     * Customer-facing payment method title.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getTitle(?int $storeId = null): string
    {
        return (string)$this->getValue(self::FIELD_TITLE, $storeId);
    }

    /**
     * Checkout presentation mode: CheckoutType::EMBED or CheckoutType::REDIRECT.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getCheckoutType(?int $storeId = null): string
    {
        return (string)$this->getValue(self::FIELD_CHECKOUT_TYPE, $storeId);
    }

    /**
     * Flitt merchant id.
     *
     * @param int|null $storeId
     * @param bool $strict Throw instead of returning an unconfigured (0) value.
     * @return int
     * @throws FlittApiException
     */
    public function getMerchantId(?int $storeId = null, bool $strict = false): int
    {
        $merchantId = (int)$this->getValue(self::FIELD_MERCHANT_ID, $storeId);

        if ($strict && $merchantId <= 0) {
            throw new FlittApiException(
                __('Flitt merchant id is not configured for store %1.', $storeId ?? 'default')
            );
        }

        return $merchantId;
    }

    /**
     * Payment secret key, decrypted.
     *
     * @param int|null $storeId
     * @param bool $strict Throw instead of returning an unconfigured (empty) value.
     * @return string
     * @throws FlittApiException
     */
    public function getSecretKey(?int $storeId = null, bool $strict = false): string
    {
        $secretKey = $this->encryptor->decrypt((string)$this->getValue(self::FIELD_SECRET_KEY, $storeId));

        if ($strict && $secretKey === '') {
            throw new FlittApiException(
                __('Flitt secret key is not configured for store %1.', $storeId ?? 'default')
            );
        }

        return $secretKey;
    }

    /**
     * Flitt API base URL, without a trailing slash.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiUrl(?int $storeId = null): string
    {
        return rtrim((string)$this->getValue(self::FIELD_API_URL, $storeId), '/');
    }

    /**
     * HTTP timeout for Flitt API calls, in seconds (never 0).
     *
     * @param int|null $storeId
     * @return int
     */
    public function getApiTimeout(?int $storeId = null): int
    {
        $timeout = (int)$this->getValue(self::FIELD_API_TIMEOUT, $storeId);

        return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT_SECONDS;
    }

    /**
     * Whether full payload dumps are logged (payment/shubo_flitt/debug).
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isDebugEnabled(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::FIELD_DEBUG, $storeId);
    }

    /**
     * The merchant's settle-timing toggle (Payment Action): capture the
     * hold when the payment is settled onto an order (authorize_capture)
     * or leave it open for a manual Invoice → Capture Online (authorize).
     *
     * NOT a preauth switch — checkout always mints holds (authorize-first);
     * compare against MethodInterface::ACTION_* constants.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getPaymentAction(?int $storeId = null): string
    {
        return (string)$this->getValue(self::FIELD_PAYMENT_ACTION, $storeId);
    }

    /**
     * Per-shop tag prepended to Flitt-side order ids (empty when unset).
     *
     * Distinguishes Magento instances that share one Flitt merchant account.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getOrderIdPrefix(?int $storeId = null): string
    {
        return trim((string)$this->getValue(self::FIELD_ORDER_ID_PREFIX, $storeId));
    }

    /**
     * Seconds an unpaid Flitt order stays payable before expiring.
     *
     * An unset/zero config value falls back to 36000 — Flitt's own default —
     * so 0 is never sent to the API (its meaning there is undocumented).
     *
     * @param int|null $storeId
     * @return int
     */
    public function getLifetime(?int $storeId = null): int
    {
        $lifetime = (int)$this->getValue(self::FIELD_LIFETIME, $storeId);

        return $lifetime > 0 ? $lifetime : self::DEFAULT_LIFETIME_SECONDS;
    }

    /**
     * Seconds of quiet the reconciler waits before touching a ledger row
     * (past a mint's lifetime, or since a parked hold last changed).
     *
     * @param int|null $storeId
     * @return int
     */
    public function getReconcileGrace(?int $storeId = null): int
    {
        $grace = (int)$this->getValue(self::FIELD_RECONCILE_GRACE, $storeId);

        return $grace > 0 ? $grace : self::DEFAULT_RECONCILE_GRACE_SECONDS;
    }

    /**
     * Whether the Google Pay button is offered in the embedded form.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isGooglePayEnabled(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::FIELD_WALLET_GOOGLE_PAY, $storeId);
    }

    /**
     * Whether the Apple Pay button is offered in the embedded form.
     *
     * Requires the domain to be verified with Flitt support first (the
     * apple-developer-merchantid-domain-association file) — the toggle only
     * renders the button.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isApplePayEnabled(?int $storeId = null): bool
    {
        return (bool)$this->getValue(self::FIELD_WALLET_APPLE_PAY, $storeId);
    }

    /**
     * Whether saved cards (the shubo_flitt_vault method) are enabled — the
     * gate for requesting rectokens at mint time.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isVaultActive(?int $storeId = null): bool
    {
        return (bool)$this->scopeConfig->getValue(
            self::XML_PATH_VAULT_ACTIVE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Currencies the merchant accepts through Flitt.
     *
     * @param int|null $storeId
     * @return string[] Upper-case ISO codes, e.g. ['GEL', 'USD']
     */
    public function getAllowedCurrencies(?int $storeId = null): array
    {
        $codes = explode(',', (string)$this->getValue(self::FIELD_ALLOWED_CURRENCIES, $storeId));

        return array_values(array_filter(
            array_map(static fn (string $code): string => strtoupper(trim($code)), $codes),
            static fn (string $code): bool => $code !== ''
        ));
    }

    /**
     * Whether the merchant accepts this order currency through Flitt.
     *
     * @param string $currencyCode
     * @param int|null $storeId
     * @return bool
     */
    public function isCurrencyAllowed(string $currencyCode, ?int $storeId = null): bool
    {
        return in_array(strtoupper($currencyCode), $this->getAllowedCurrencies($storeId), true);
    }

    /**
     * Flitt payment-page language, resolved from the store's locale.
     *
     * Falls back to Georgian for locales Flitt does not support.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getLanguage(?int $storeId = null): string
    {
        $locale = (string)$this->scopeConfig->getValue(
            self::XML_PATH_LOCALE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $lang = strtolower(substr($locale, 0, 2));

        return in_array($lang, self::SUPPORTED_LANGS, true) ? $lang : self::DEFAULT_LANG;
    }
}
