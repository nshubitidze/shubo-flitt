<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Vault;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\Data\OrderPaymentExtensionInterfaceFactory;
use Magento\Sales\Model\Order\Payment;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Magento\Vault\Model\Ui\VaultConfigProvider;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Model\Checkout\TokenManager;
use Shubo\Flitt\Model\Ledger;

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
     * @param Ledger $ledger
     * @param Json $json
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly PaymentTokenFactoryInterface $tokenFactory,
        private readonly OrderPaymentExtensionInterfaceFactory $extensionFactory,
        private readonly Ledger $ledger,
        private readonly Json $json,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Attach a vault token to the payment when the customer asked for one.
     *
     * Facts come from the verified signed envelope, with the ledger row
     * (callback-borne facts) as fallback — whichever delivered the rectoken.
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

            $row = null;
            $rectoken = $this->fact($status, 'rectoken');

            if ($rectoken === '') {
                $flittOrderId = (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID);
                $row = $flittOrderId !== '' ? $this->ledger->find($flittOrderId) : null;
                $rectoken = (string)($row['rectoken'] ?? '');
            }

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

            if ($lifetime === '' && $row !== null) {
                $lifetime = (string)($row['rectoken_lifetime'] ?? '');
            }

            $token = $this->tokenFactory->create(PaymentTokenFactoryInterface::TOKEN_TYPE_CREDIT_CARD);
            $token->setGatewayToken($rectoken);
            $token->setExpiresAt($this->expiresAt($lifetime));
            $token->setTokenDetails($this->json->serialize($this->details($status, $lifetime)));

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
     * absent/unparseable. Magento hides tokens past expires_at.
     *
     * @param string $lifetime
     * @return string UTC 'Y-m-d H:i:s'.
     */
    private function expiresAt(string $lifetime): string
    {
        if ($lifetime !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('d.m.Y H:i:s', $lifetime, new \DateTimeZone('UTC'));

            if ($parsed !== false && $parsed->getTimestamp() > time()) {
                return $parsed->format('Y-m-d H:i:s');
            }
        }

        return (new \DateTimeImmutable(self::DEFAULT_LIFETIME, new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /**
     * Display details for the stored card (core vault template contract:
     * type drives the scheme icon, maskedCC the "ending NNNN" line;
     * expirationDate is the TOKEN's expiry — Flitt never discloses the
     * card's own — and is omitted when unknown).
     *
     * @param array<string, mixed> $status
     * @param string $lifetime
     * @return array<string, string>
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
            ? \DateTimeImmutable::createFromFormat('d.m.Y H:i:s', $lifetime, new \DateTimeZone('UTC'))
            : false;

        if ($parsed !== false) {
            $details['expirationDate'] = $parsed->format('m/Y');
        }

        return $details;
    }
}
