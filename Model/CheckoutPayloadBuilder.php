<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model;

use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Shubo\Flitt\Gateway\Config;

/**
 * Maps a placed Magento order — or an active quote in the pay-first embed
 * flow — to the Flitt create-order request params (checkout/url and
 * checkout/token share the same payload shape).
 *
 * Deliberately separate from FlittClient: the client owns transport and
 * signing (adding version/merchant_id/signature), this class owns the order
 * mapping. response_url and server_callback_url are appended by the code that
 * owns those routes (Steps D/F), not here.
 */
class CheckoutPayloadBuilder
{
    /**
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * Build the create-order params for a placed order.
     *
     * @param OrderInterface $order
     * @return array<string, string|int>
     * @throws \InvalidArgumentException When the order has no increment id, no positive total,
     *     or a currency not enabled for Flitt.
     */
    public function build(OrderInterface $order): array
    {
        return $this->buildParams(
            (int)$order->getStoreId(),
            (string)$order->getIncrementId(),
            Money::toMinorUnits($order->getGrandTotal()),
            (string)$order->getOrderCurrencyCode(),
            (string)$order->getCustomerEmail(),
            (int)$order->getCustomerId()
        );
    }

    /**
     * Build the create-order params for an active quote (pay-first embed).
     *
     * The quote must already carry a reserved order id — that is the future
     * order's increment id, so both flows produce the same Flitt order_id
     * shape and the callback can resolve either.
     *
     * @param Quote $quote
     * @return array<string, string|int>
     * @throws \InvalidArgumentException When the quote has no reserved order id, no positive
     *     total, or a currency not enabled for Flitt.
     */
    public function buildFromQuote(Quote $quote): array
    {
        return $this->buildParams(
            (int)$quote->getStoreId(),
            (string)$quote->getReservedOrderId(),
            Money::toMinorUnits($quote->getGrandTotal()),
            (string)$quote->getQuoteCurrencyCode(),
            (string)$quote->getCustomerEmail(),
            (int)$quote->getCustomerId()
        );
    }

    /**
     * Shared param assembly for both entry points.
     *
     * @param int $storeId
     * @param string $incrementId
     * @param int $amount Minor units.
     * @param string $currency
     * @param string $email
     * @param int $customerId 0 for guests.
     * @return array<string, string|int>
     * @throws \InvalidArgumentException
     */
    private function buildParams(
        int $storeId,
        string $incrementId,
        int $amount,
        string $currency,
        string $email,
        int $customerId,
    ): array {
        if ($incrementId === '' || $amount <= 0) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot build a Flitt payload for order "%s": increment id and a positive total are required.',
                $incrementId
            ));
        }

        if (!$this->config->isCurrencyAllowed($currency, $storeId)) {
            throw new \InvalidArgumentException(sprintf(
                'Cannot build a Flitt payload for order "%s": currency "%s" is not enabled for Flitt.',
                $incrementId,
                $currency
            ));
        }

        $params = [
            'order_id' => $this->buildFlittOrderId($incrementId, $storeId),
            'order_desc' => (string)__('Order %1', $incrementId),
            'amount' => $amount,
            'currency' => $currency,
            'lang' => $this->config->getLanguage($storeId),
            'lifetime' => $this->config->getLifetime($storeId),
            // AUTHORIZE-FIRST: checkout only
            // ever HOLDS the amount, whatever the Payment Action — the charge
            // (capture) happens server-side at placement, so a payment whose
            // order never materializes leaves a hold, not taken money.
            // Reversing an uncaptured hold is instant and fee-free per the
            // Flitt docs. Card payments only (Apple/Google Pay over a card
            // hold too); other rails charge in one stage regardless.
            'preauth' => 'Y',
            'merchant_data' => sprintf('Magento order %s, store %d', $incrementId, $storeId),
        ];

        if ($email !== '') {
            $params['sender_email'] = $email;
        }

        // Saved cards: ask Flitt to issue a rectoken with the
        // payment whenever this CUSTOMER could save a card (vault on +
        // logged in). Token issuance costs nothing and changes no money
        // behavior; whether the token is PERSISTED is decided at settle by
        // the customer's consent checkbox — deciding here instead would
        // re-mint (and wipe) the embed form on every checkbox toggle.
        if ($customerId > 0 && $this->config->isVaultActive($storeId)) {
            $params['required_rectoken'] = 'Y';
        }

        return $params;
    }

    /**
     * Unique Flitt-side order id: "{prefix}_{increment}_{unixtime}" (prefix
     * part omitted when not configured). The prefix separates Magento
     * instances sharing one Flitt merchant account; the timestamp lets a new
     * Flitt order be created for the same Magento order after a
     * declined/expired attempt.
     *
     * @param string $incrementId
     * @param int $storeId
     * @return string
     */
    private function buildFlittOrderId(string $incrementId, int $storeId): string
    {
        $prefix = $this->config->getOrderIdPrefix($storeId);

        return ($prefix !== '' ? $prefix . '_' : '') . $incrementId . '_' . time();
    }
}
