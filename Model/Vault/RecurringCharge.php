<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Vault;

use Magento\Payment\Gateway\Command\CommandException;
use Magento\Sales\Model\Order\Payment;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenRepositoryInterface;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Exception\FlittApiException;
use Shubo\Flitt\Gateway\Command\AbstractVerifyCommand;
use Shubo\Flitt\Gateway\Config;
use Shubo\Flitt\Model\Callback\CallbackUrl;
use Shubo\Flitt\Model\Checkout\HoldCapture;
use Shubo\Flitt\Model\Checkout\MintPolicy;
use Shubo\Flitt\Model\Checkout\TokenManager;
use Shubo\Flitt\Model\CheckoutPayloadBuilder;
use Shubo\Flitt\Model\FlittClient;
use Shubo\Flitt\Model\Ledger;

/**
 * Charges a saved card (Flitt rectoken) for a placing order — the server
 * side of the vault_authorize / vault_sale commands.
 *
 * The money model is the card checkout's, unchanged: /api/recurring supports
 * preauth=Y, so
 * the charge first HOLDS, and the sale leg captures the hold in the same
 * breath (confirmed via the signed status envelope). The mint is recorded in
 * the ledger and the payment carries the same INFO_* session facts as every
 * other flow, so a placement that dies after the hold leaves an orphan the
 * reconciler reverses, a late callback resolves idempotently, and admin
 * capture/refund/void ride the existing gateway commands (Vault resets the
 * order payment's method to the provider code after placement).
 *
 * Flitt token errors purge the stored card: 1035 token not found/expired,
 * 1082 token expired, 1078 token deactivated by the bank. No 3DS exists on
 * /api/recurring — SCA-challenged charges decline and the customer must pay
 * through the normal card form.
 */
class RecurringCharge
{
    /**
     * Flitt error codes that mean THIS stored token is dead — retrying can
     * never help, so the vault entry is deactivated.
     */
    private const array TOKEN_DEAD_ERROR_CODES = [1035, 1078, 1082];

    /**
     * The /api/recurring request surface (Flitt docs: recurring order
     * parameters) —
     * builder output is filtered to this; notably lang and required_rectoken
     * are NOT part of this endpoint's contract.
     */
    private const array PAYLOAD_KEYS = [
        'order_id',
        'order_desc',
        'amount',
        'currency',
        'lifetime',
        'sender_email',
        'merchant_data',
        'preauth',
    ];

    /**
     * @param CheckoutPayloadBuilder $payloadBuilder
     * @param FlittClient $client
     * @param CallbackUrl $callbackUrl
     * @param Ledger $ledger
     * @param MintPolicy $mintPolicy
     * @param HoldCapture $holdCapture
     * @param Config $config
     * @param PaymentTokenRepositoryInterface $tokenRepository
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly CheckoutPayloadBuilder $payloadBuilder,
        private readonly FlittClient $client,
        private readonly CallbackUrl $callbackUrl,
        private readonly Ledger $ledger,
        private readonly MintPolicy $mintPolicy,
        private readonly HoldCapture $holdCapture,
        private readonly Config $config,
        private readonly PaymentTokenRepositoryInterface $tokenRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Hold (and for the sale leg capture) the order total on the saved card.
     *
     * @param Payment $payment
     * @param float $baseAmount The command subject amount.
     * @param bool $capture True for vault_sale (charge lands now), false for
     *     vault_authorize (hold stays open for Invoice → Capture Online).
     * @return void
     * @throws CommandException
     */
    public function charge(Payment $payment, float $baseAmount, bool $capture): void
    {
        $order = $payment->getOrder();
        $storeId = (int)$order->getStoreId();
        $incrementId = (string)$order->getIncrementId();

        // Full placement only — mirrors the single-full-capture rule.
        if (abs($baseAmount - (float)$order->getBaseGrandTotal()) > 0.004) {
            throw new CommandException(__('Saved-card payments always charge the full order amount.'));
        }

        $rectoken = $this->gatewayToken($payment);
        $payload = array_intersect_key(
            $this->payloadBuilder->build($order),
            array_flip(self::PAYLOAD_KEYS)
        );
        $payload['server_callback_url'] = $this->callbackUrl->forStore($storeId ?: null);
        $payload['order_id'] = $this->mintPolicy->monotonicOrderId(
            (string)$payload['order_id'],
            (int)$payment->getAdditionalInformation(TokenManager::INFO_MINTED_AT)
        );
        $flittOrderId = (string)$payload['order_id'];

        $this->ledger->recordMint(
            $flittOrderId,
            $storeId,
            (int)$order->getQuoteId() ?: null,
            $incrementId,
            (int)$payload['amount'],
            (string)$payload['currency'],
            (int)$payload['lifetime'],
        );

        foreach ($this->mintPolicy->mintFacts($payload) as $key => $value) {
            $payment->setAdditionalInformation($key, $value);
        }

        $response = $this->execute($payment, $payload + ['rectoken' => $rectoken], $flittOrderId, $storeId);
        $this->assertApproved($response, $payload, $flittOrderId, $incrementId, $storeId);
        $this->ledger->transition($flittOrderId, Ledger::STATE_APPROVED);

        if ($capture) {
            // Same trust rules as placement: the synchronous capture
            // response is advisory, the signed status envelope confirms.
            $this->holdCapture->ensureCaptured($flittOrderId, $response, $storeId);
        }

        $this->record($payment, $response, $capture);

        $this->logger->info(sprintf(
            'vault charge: order %s %s %d %s on the saved card via flitt order %s',
            $incrementId,
            $capture ? 'charged' : 'authorized',
            (int)$payload['amount'],
            (string)$payload['currency'],
            $flittOrderId
        ));
    }

    /**
     * The stored Flitt rectoken from the payment's vault token (attached by
     * Vault::authorize()/capture() from the customer's public_hash).
     *
     * @param Payment $payment
     * @return string
     * @throws CommandException
     */
    private function gatewayToken(Payment $payment): string
    {
        $token = $payment->getExtensionAttributes()?->getVaultPaymentToken();
        $rectoken = $token instanceof PaymentTokenInterface ? (string)$token->getGatewayToken() : '';

        if ($rectoken === '') {
            throw new CommandException(__('The saved card could not be loaded. Please pay another way.'));
        }

        return $rectoken;
    }

    /**
     * Run the recurring call, mapping failures: dead token → purge the vault
     * entry; definitive decline → ledger declined; transport → leave the
     * mint for the reconciler to probe (the charge MAY exist at Flitt — its
     * callback or the sweep resolves it either way).
     *
     * @param Payment $payment
     * @param array<string, string|int> $request
     * @param string $flittOrderId
     * @param int $storeId
     * @return array<string, mixed> The signed order envelope.
     * @throws CommandException
     */
    private function execute(Payment $payment, array $request, string $flittOrderId, int $storeId): array
    {
        try {
            return $this->client->createRecurringOrder($request, $storeId ?: null);
        } catch (FlittApiException $e) {
            $errorCode = $e->getApiErrorCode();

            if (in_array($errorCode, self::TOKEN_DEAD_ERROR_CODES, true)) {
                $this->ledger->transition($flittOrderId, Ledger::STATE_DECLINED);
                $this->purgeToken($payment, $errorCode);

                throw new CommandException(
                    __('This saved card can no longer be used. Please pay another way and save the card again.')
                );
            }

            if ($errorCode !== null) {
                $this->ledger->transition($flittOrderId, Ledger::STATE_DECLINED);

                throw new CommandException(__('The payment was not approved. Please try another payment method.'));
            }

            throw new CommandException(
                __('Your payment could not be processed right now. Please try again in a moment.')
            );
        }
    }

    /**
     * The signed envelope must show the exact approved hold this order
     * expects — anything else refuses the placement (money may have moved;
     * the ledger row keeps it findable).
     *
     * @param array<string, mixed> $response
     * @param array<string, string|int> $payload
     * @param string $flittOrderId
     * @param string $incrementId
     * @param int $storeId
     * @return void
     * @throws CommandException
     */
    private function assertApproved(
        array $response,
        array $payload,
        string $flittOrderId,
        string $incrementId,
        int $storeId,
    ): void {
        if ((string)($response['order_id'] ?? '') !== $flittOrderId) {
            $this->logger->error(sprintf(
                'vault charge: envelope is for flitt order "%s" but "%s" was requested (order %s)',
                (string)($response['order_id'] ?? ''),
                $flittOrderId,
                $incrementId
            ));

            throw new CommandException(__('Your payment could not be verified. Please try again.'));
        }

        $orderStatus = (string)($response['order_status'] ?? '');

        if ($orderStatus !== 'approved') {
            $this->ledger->transition($flittOrderId, Ledger::STATE_DECLINED);
            $this->logger->info(sprintf(
                'vault charge: flitt order %s is "%s" — placement rejected for order %s',
                $flittOrderId,
                $orderStatus,
                $incrementId
            ));

            throw new CommandException(__('The payment was not approved. Please try another payment method.'));
        }

        if ((int)($response['amount'] ?? -1) !== (int)$payload['amount']
            || (string)($response['currency'] ?? '') !== (string)$payload['currency']
            || (int)($response['reversal_amount'] ?? 0) !== 0
            || (int)($response['merchant_id'] ?? 0) !== $this->config->getMerchantId($storeId)
        ) {
            $this->logger->error(sprintf(
                'vault charge: APPROVED payment %s does NOT match order %s — got %d %s (reversed %d, merchant %d),'
                . ' expected %d %s. Money may have moved; needs review/reversal.',
                $flittOrderId,
                $incrementId,
                (int)($response['amount'] ?? -1),
                (string)($response['currency'] ?? ''),
                (int)($response['reversal_amount'] ?? 0),
                (int)($response['merchant_id'] ?? 0),
                (int)$payload['amount'],
                (string)$payload['currency']
            ));

            throw new CommandException(
                __('The payment does not match this order and will be reviewed. Please contact us before paying again.')
            );
        }
    }

    /**
     * Record the outcome on the payment — the same facts placement
     * verification records, so downstream (capture/refund/void, admin info
     * blocks) sees one shape.
     *
     * @param Payment $payment
     * @param array<string, mixed> $response
     * @param bool $capture
     * @return void
     */
    private function record(Payment $payment, array $response, bool $capture): void
    {
        $paymentId = (string)($response['payment_id'] ?? '');
        $payment->setTransactionId($paymentId);
        $payment->setIsTransactionClosed($capture);

        $maskedCard = (string)($response['masked_card'] ?? '');

        if ($maskedCard !== '') {
            $payment->setAdditionalInformation(AbstractVerifyCommand::INFO_MASKED_CARD, $maskedCard);
            $payment->setCcLast4(substr($maskedCard, -4));
        }

        if (($response['card_type'] ?? '') !== '') {
            $payment->setAdditionalInformation(AbstractVerifyCommand::INFO_CARD_TYPE, (string)$response['card_type']);
            $payment->setCcType((string)$response['card_type']);
        }

        $payment->setAdditionalInformation(AbstractVerifyCommand::INFO_PAYMENT_ID, $paymentId);
        $payment->setAdditionalInformation(AbstractVerifyCommand::INFO_RRN, (string)($response['rrn'] ?? ''));
        $payment->setAdditionalInformation(
            AbstractVerifyCommand::INFO_APPROVAL_CODE,
            (string)($response['approval_code'] ?? '')
        );
    }

    /**
     * Deactivate the dead vault entry (soft delete — hidden from checkout).
     *
     * @param Payment $payment
     * @param int $errorCode
     * @return void
     */
    private function purgeToken(Payment $payment, int $errorCode): void
    {
        try {
            $token = $payment->getExtensionAttributes()?->getVaultPaymentToken();

            if ($token instanceof PaymentTokenInterface && $token->getEntityId() !== null) {
                $this->tokenRepository->delete($token);
                $this->logger->info(sprintf(
                    'vault charge: stored card %s purged after Flitt error %d (order %s)',
                    (string)$token->getPublicHash(),
                    $errorCode,
                    (string)$payment->getOrder()->getIncrementId()
                ));
            }
        } catch (\Throwable $e) {
            $this->logger->error('vault charge: purging the dead stored card failed: ' . $e->getMessage());
        }
    }
}
