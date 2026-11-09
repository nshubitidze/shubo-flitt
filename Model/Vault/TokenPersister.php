<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Vault;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\Data\OrderPaymentExtensionInterfaceFactory;
use Magento\Sales\Model\Order\Payment;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Magento\Vault\Model\Ui\VaultConfigProvider;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Gateway\Config;
use Shubo\Flitt\Model\Ui\ConfigProvider;

/**
 * Turns the rectoken facts of a settled payment into a Magento_Vault card
 * token — attached as the payment's vault_payment_token extension attribute,
 * which core's AfterPaymentSaveObserver persists (customer id, public hash,
 * dedup and visibility are all core's job; visibility additionally re-checks
 * the same consent flag).
 *
 * Runs at both settle sites (embed placement verify + callback/return
 * OrderSettler). Persisting a card is strictly best-effort: a vault problem
 * must never fail a payment that has already moved money, so attach() never
 * throws.
 */
class TokenPersister
{
    /**
     * Fallback vault-token lifetime when Flitt delivers no rectoken_lifetime
     * (Flitt's docs show it empty in some examples; the test merchant sends
     * an absolute date such as "01.01.2030 00:00:00"): expired vault entries silently
     * disappear from checkout, and a stale token that survives too long is
     * self-healing anyway — a charge attempt on it fails with 1035/1082 and
     * purges it (RecurringCharge).
     */
    private const string DEFAULT_LIFETIME = '+1 year';

    /**
     * Flitt card_type values mapped to Magento's cc type codes (icon +
     * label lookups); unmapped schemes are stored verbatim.
     */
    private const array CARD_TYPE_MAP = [
        'VISA' => 'VI',
        'MASTERCARD' => 'MC',
        'MAESTRO' => 'MI',
    ];

    /**
     * @param PaymentTokenFactoryInterface $tokenFactory
     * @param OrderPaymentExtensionInterfaceFactory $extensionFactory
     * @param Json $json
     * @param Config $config
     * @param PaymentTokenManagementInterface $tokenManagement
     * @param PaymentTokenRepositoryInterface $tokenRepository
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly PaymentTokenFactoryInterface $tokenFactory,
        private readonly OrderPaymentExtensionInterfaceFactory $extensionFactory,
        private readonly Json $json,
        private readonly Config $config,
        private readonly PaymentTokenManagementInterface $tokenManagement,
        private readonly PaymentTokenRepositoryInterface $tokenRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Attach a vault token to the payment when the customer asked for one.
     *
     * Facts come from the verified signed envelope only (it carries rectoken
     * and rectoken_lifetime); the ledger never stores the rectoken.
     *
     * @param Payment $payment
     * @param array<string, mixed> $status The verified signed status/order envelope.
     * @return void
     */
    public function attach(Payment $payment, array $status): void
    {
        try {
            if (!$payment->getAdditionalInformation(VaultConfigProvider::IS_ACTIVE_CODE)) {
                return;
            }

            $order = $payment->getOrder();

            if ((int)$order->getCustomerId() <= 0) {
                return;
            }

            $rectoken = $this->fact($status, 'rectoken');

            if ($rectoken === '') {
                // Consent given but no token issued (merchant account flag
                // off, or the rail does not tokenize) — nothing to save.
                $this->logger->info(sprintf(
                    'vault: order %s consented to saving but no rectoken was delivered',
                    $order->getIncrementId()
                ));

                return;
            }

            $lifetime = $this->fact($status, 'rectoken_lifetime');

            $expiresAt = $this->expiresAt($lifetime);

            if ($expiresAt === null) {
                // A token Flitt dates in the past, or with a date we cannot
                // read, is not stored: it would be offered and then decline.
                $this->logger->warning(sprintf(
                    'vault: order %s card not saved — unusable rectoken_lifetime "%s"',
                    $order->getIncrementId(),
                    $lifetime
                ));

                return;
            }

            $details = $this->details($status, $lifetime);
            // The issuing merchant (signed in the status; the store's own as
            // fallback): the token is only usable there (TokenMerchant).
            $details[TokenMerchant::DETAIL_KEY] = (int)$this->fact($status, 'merchant_id')
                ?: $this->config->getMerchantId((int)$order->getStoreId());

            // The same card saved again (same rectoken) must reuse its vault
            // row: core detects duplicates by a public hash that includes the
            // token details, while the table is unique on method + customer +
            // gateway token — any change in the details (expiry, merchant)
            // made core insert a second row, the insert failed and the whole
            // order save rolled back after the payment was captured.
            $token = $this->tokenManagement->getByGatewayToken(
                $rectoken,
                ConfigProvider::CODE,
                (int)$order->getCustomerId()
            );

            if ($token !== null) {
                $token->setExpiresAt($expiresAt);
                $token->setTokenDetails($this->json->serialize($details));
                $token->setIsActive(true);
                $token->setIsVisible(true);
                $this->tokenRepository->save($token);
            } else {
                $token = $this->tokenFactory->create(PaymentTokenFactoryInterface::TOKEN_TYPE_CREDIT_CARD);
                $token->setGatewayToken($rectoken);
                $token->setExpiresAt($expiresAt);
                $token->setTokenDetails($this->json->serialize($details));
            }

            $extensionAttributes = $payment->getExtensionAttributes() ?? $this->extensionFactory->create();
            $extensionAttributes->setVaultPaymentToken($token);
            $payment->setExtensionAttributes($extensionAttributes);

            $this->logger->info(sprintf(
                'vault: order %s card token attached for persistence (expires %s)',
                $order->getIncrementId(),
                $token->getExpiresAt()
            ));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'vault: attaching the card token for order %s failed: %s',
                (string)$payment->getOrder()->getIncrementId(),
                $e->getMessage()
            ));
        }
    }

    /**
     * A scalar envelope field as a trimmed string, '' when absent.
     *
     * @param array<string, mixed> $status
     * @param string $key
     * @return string
     */
    private function fact(array $status, string $key): string
    {
        $value = $status[$key] ?? '';

        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * Vault expiry timestamp from Flitt's rectoken_lifetime ("dd.mm.yyyy
     * HH:MM:SS", same shape as order_time), or the default lifetime when
     * Flitt sends none. Magento hides tokens past expires_at.
     *
     * @param string $lifetime
     * @return string|null UTC 'Y-m-d H:i:s'; null when the lifetime is
     *     unreadable or already past (the token must not be stored).
     */
    private function expiresAt(string $lifetime): ?string
    {
        if ($lifetime === '') {
            return (new \DateTimeImmutable(self::DEFAULT_LIFETIME, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        }

        $utc = new \DateTimeZone('UTC');
        $parsed = \DateTimeImmutable::createFromFormat('!d.m.Y H:i:s', $lifetime, $utc)
            ?: \DateTimeImmutable::createFromFormat('!d.m.Y', $lifetime, $utc);

        if ($parsed === false || $parsed->getTimestamp() <= time()) {
            return null;
        }

        return $parsed->format('Y-m-d H:i:s');
    }

    /**
     * Display details for the stored card (core vault template contract:
     * type drives the scheme icon, maskedCC the "ending NNNN" line;
     * expirationDate is the TOKEN's expiry — Flitt never discloses the
     * card's own — and is omitted when unknown).
     *
     * @param array<string, mixed> $status
     * @param string $lifetime
     * @return array<string, string|int>
     */
    private function details(array $status, string $lifetime): array
    {
        $details = [];
        $cardType = strtoupper($this->fact($status, 'card_type'));

        if ($cardType !== '') {
            $details['type'] = self::CARD_TYPE_MAP[$cardType] ?? $cardType;
        }

        $maskedCard = $this->fact($status, 'masked_card');

        if ($maskedCard !== '') {
            $details['maskedCC'] = substr($maskedCard, -4);
        }

        $parsed = $lifetime !== ''
            ? (\DateTimeImmutable::createFromFormat('!d.m.Y H:i:s', $lifetime, new \DateTimeZone('UTC'))
                ?: \DateTimeImmutable::createFromFormat('!d.m.Y', $lifetime, new \DateTimeZone('UTC')))
            : false;

        if ($parsed !== false) {
            $details['expirationDate'] = $parsed->format('m/Y');
        }

        return $details;
    }
}
