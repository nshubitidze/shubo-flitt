<?php

declare(strict_types=1);

namespace Shubo\Flitt\Gateway\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;
use Shubo\Flitt\Model\Checkout\PaymentVerifier;
use Shubo\Flitt\Model\Vault\TokenPersister;

/**
 * Shared placement-time verification for the pay-first flow: the customer
 * has ALREADY paid in the embed by the time Magento runs authorize/capture,
 * so these commands move no money — they verify the cached Flitt session
 * (PaymentVerifier) and record the transaction on the payment.
 */
abstract class AbstractVerifyCommand implements CommandInterface
{
    public const string INFO_PAYMENT_ID = 'flitt_payment_id';
    public const string INFO_MASKED_CARD = 'flitt_masked_card';
    public const string INFO_CARD_TYPE = 'flitt_card_type';
    public const string INFO_RRN = 'flitt_rrn';
    public const string INFO_APPROVAL_CODE = 'flitt_approval_code';

    /**
     * @param PaymentVerifier $verifier
     * @param TokenPersister $tokenPersister
     */
    public function __construct(
        private readonly PaymentVerifier $verifier,
        private readonly TokenPersister $tokenPersister,
    ) {
    }

    /**
     * Verify the already-made payment and record its transaction.
     *
     * @param array<string, mixed> $commandSubject
     * @throws CommandException|LocalizedException
     * @return null
     */
    public function execute(array $commandSubject)
    {
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();

        if (!$payment instanceof Payment) {
            throw new CommandException(__('Flitt placement verification requires an order payment.'));
        }

        // Authorize-first: checkout only ever mints holds (preauth=Y), so
        // both placement modes verify a hold; VerifyChargeCommand then
        // captures it before the order may exist.
        $status = $this->verifier->verify($payment, 'Y');
        $this->settle($payment, $status);

        $payment->setTransactionId((string)($status['payment_id'] ?? ''));
        $payment->setIsTransactionClosed($this->transactionClosed());

        $maskedCard = (string)($status['masked_card'] ?? '');

        if ($maskedCard !== '') {
            $payment->setAdditionalInformation(self::INFO_MASKED_CARD, $maskedCard);
            $payment->setCcLast4(substr($maskedCard, -4));
        }

        if (($status['card_type'] ?? '') !== '') {
            $payment->setAdditionalInformation(self::INFO_CARD_TYPE, (string)$status['card_type']);
            $payment->setCcType((string)$status['card_type']);
        }

        $payment->setAdditionalInformation(self::INFO_PAYMENT_ID, (string)($status['payment_id'] ?? ''));
        $payment->setAdditionalInformation(self::INFO_RRN, (string)($status['rrn'] ?? ''));
        $payment->setAdditionalInformation(self::INFO_APPROVAL_CODE, (string)($status['approval_code'] ?? ''));

        // Saved cards: persist the issued rectoken when the
        // customer consented — best-effort, never fails the placement.
        $this->tokenPersister->attach($payment, $status);

        return null;
    }

    /**
     * Whether the recorded transaction is final.
     *
     * True for a captured charge; false for a hold awaiting the merchant's
     * capture (Invoice → Capture Online).
     *
     * @return bool
     */
    abstract protected function transactionClosed(): bool;

    /**
     * Mode-specific money step between verification and recording — capture
     * the hold (authorize_capture) or leave it open (authorize).
     *
     * @param Payment $payment
     * @param array<string, mixed> $status The verified signed status envelope.
     * @return void
     * @throws CommandException
     */
    abstract protected function settle(Payment $payment, array $status): void;
}
