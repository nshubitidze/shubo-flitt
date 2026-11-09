<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Checkout;

use Magento\Customer\Api\Data\GroupInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Exception\PaymentInFlightException;
use Shubo\Flitt\Gateway\Config;
use Shubo\Flitt\Model\Money;
use Shubo\Flitt\Model\Ui\ConfigProvider;

/**
 * Places the Magento order for a PAID embed session whose browser died
 * before placement: the reconciler's "settle by creating the order"
 * step, tried before a parked hold is written off and reversed.
 *
 * The quote is submitted EXACTLY as the customer left it (no totals
 * re-collect — the paid amount belongs to the cart as it was paid for), and
 * only when every binding still holds: same payment session, same reserved
 * increment id, and the signed status envelope matching the live quote's
 * money to the tetri. Submission runs the normal embed placement pipeline
 * (CaptureStrategyCommand → verify + capture), so the order that comes out
 * is verified and settled by the same code path a live customer uses.
 */
class PaidQuotePlacer
{
    /**
     * @param CartRepositoryInterface $cartRepository
     * @param QuoteManagement $quoteManagement
     * @param Config $config
     * @param OrderSender $orderSender
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly CartRepositoryInterface $cartRepository,
        private readonly QuoteManagement $quoteManagement,
        private readonly Config $config,
        private readonly OrderSender $orderSender,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Try to place the order a paid-but-abandoned session was minted for.
     *
     * @param array<string, mixed> $row Ledger row (state approved).
     * @param array<string, mixed> $status The verified signed status envelope.
     * @return Order|null The placed order, or null when the money can no
     *     longer be matched to a placeable quote (caller reverses the hold).
     * @throws PaymentInFlightException When the quote looks mid-placement —
     *     the caller must leave the row alone and re-check next run.
     * @throws \Magento\Framework\Exception\LocalizedException When submission
     *     itself fails (validation, stock, gateway verify) — see the caller's
     *     transient-vs-terminal handling.
     */
    public function place(array $row, array $status): ?Order
    {
        $flittOrderId = (string)$row['flitt_order_id'];
        $quoteId = (int)($row['quote_id'] ?? 0);

        if ($quoteId === 0) {
            return null;
        }

        try {
            $quote = $this->cartRepository->get($quoteId);
        } catch (NoSuchEntityException) {
            return null;
        }

        if (!$quote instanceof Quote) {
            return null;
        }

        if (!$quote->getIsActive()) {
            // Submitted-but-orderless is either a placement in flight RIGHT
            // NOW or a crashed submit — never reverse on that evidence.
            throw new PaymentInFlightException(
                __('Quote %1 is inactive but its order was not found — possible placement in flight.', $quoteId)
            );
        }

        $payment = $quote->getPayment();

        if ($payment->getMethod() !== ConfigProvider::CODE
            || (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID) !== $flittOrderId
            || (string)$quote->getReservedOrderId() !== (string)$row['increment_id']
        ) {
            // The quote moved on (re-mint, method change, restored cart) —
            // this money no longer belongs to it.
            return null;
        }

        // The paid money must match the LIVE quote exactly — mirror of
        // PaymentStateResolver's PAID gate; anything else is not ours to
        // place.
        $storeId = (int)$quote->getStoreId();
        $expectedAmount = Money::toMinorUnits($quote->getGrandTotal());

        if ((string)($status['order_status'] ?? '') !== 'approved'
            || (int)($status['amount'] ?? -1) !== $expectedAmount
            || (string)($status['currency'] ?? '') !== (string)$quote->getQuoteCurrencyCode()
            || (int)($status['reversal_amount'] ?? 0) !== 0
            || (int)($status['merchant_id'] ?? 0) !== $this->config->getMerchantId($storeId)
            || (string)$payment->getAdditionalInformation(TokenManager::INFO_PREAUTH) !== 'Y'
        ) {
            $this->logger->warning(sprintf(
                'place-paid-quote: flitt order %s no longer matches quote %d (paid %d %s, expected %d %s)'
                . ' — not placeable',
                $flittOrderId,
                $quoteId,
                (int)($status['amount'] ?? -1),
                (string)($status['currency'] ?? ''),
                $expectedAmount,
                (string)$quote->getQuoteCurrencyCode()
            ));

            return null;
        }

        $this->prepareGuest($quote);

        // Runs the standard embed placement pipeline: quote → order,
        // CaptureStrategyCommand verifies the session against the signed
        // status and captures the hold inline. NOT placeOrder(): that
        // variant re-collects totals and writes to the checkout session,
        // neither of which belongs in a cron.
        $order = $this->quoteManagement->submit($quote);

        if (!$order instanceof Order) {
            return null;
        }

        $this->logger->info(sprintf(
            'place-paid-quote: order %s placed from abandoned paid session %s (quote %d)',
            (string)$order->getIncrementId(),
            $flittOrderId,
            $quoteId
        ));

        // Core sends the confirmation from an observer registered only for
        // the storefront and web API areas, so an order placed by cron would
        // reach the customer silently.
        if (!$order->getEmailSent()) {
            try {
                $this->orderSender->send($order);
            } catch (\Throwable $e) {
                $this->logger->error(sprintf(
                    'place-paid-quote: order %s confirmation email failed: %s',
                    (string)$order->getIncrementId(),
                    $e->getMessage()
                ));
            }
        }

        return $order;
    }

    /**
     * Give a guest quote the customer fields core's placeOrder() sets just
     * before it submits. A guest's e-mail reaches the quote only with the
     * final place-order request — exactly the request a dead browser never
     * sent — so without this submit() fails address validation ("Email has
     * a wrong format") and the paid order is never created. The e-mail is
     * already on the billing address, saved with the shipping step.
     *
     * @param Quote $quote
     * @return void
     */
    private function prepareGuest(Quote $quote): void
    {
        if ($quote->getCheckoutMethod() !== CartManagementInterface::METHOD_GUEST && $quote->getCustomerId()) {
            return;
        }

        $billingAddress = $quote->getBillingAddress();
        $quote->setCustomerId(null);

        if (!$quote->getCustomerEmail()) {
            $quote->setCustomerEmail($billingAddress->getEmail());
        }

        if ($quote->getCustomerFirstname() === null && $quote->getCustomerLastname() === null) {
            $quote->setCustomerFirstname($billingAddress->getFirstname());
            $quote->setCustomerLastname($billingAddress->getLastname());

            if ($billingAddress->getMiddlename() !== null) {
                $quote->setCustomerMiddlename($billingAddress->getMiddlename());
            }
        }

        $quote->setCustomerIsGuest(true);
        $quote->setCustomerGroupId(GroupInterface::NOT_LOGGED_IN_ID);
    }
}
