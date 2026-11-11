<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Callback;

use InvalidArgumentException;
use Laminas\Http\Response as HttpResponse;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Gateway\Command\CommandException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Exception\FlittApiException;
use Shubo\Flitt\Exception\PaymentSessionMismatchException;
use Shubo\Flitt\Gateway\Config;
use Shubo\Flitt\Gateway\Signature;
use Shubo\Flitt\Model\Checkout\TokenManager;
use Shubo\Flitt\Model\Ledger;
use Shubo\Flitt\Model\LogSanitizer;
use Shubo\Flitt\Model\OrderLock;
use Shubo\Flitt\Model\Ui\ConfigProvider;

/**
 * Processes the Flitt callback and returns the response.
 *
 * matrix:
 * 200 = final (processed or terminally ignored — Flitt stops),
 * 400 = bad payload/signature (retrying cannot help),
 * 503 = transient (Flitt retries at 2/60/300/600/3600/86400s).
 *
 * Trust chain: the POSTed order_id resolves a ledger row, the row resolves
 * the store, the store resolves the secret, and ONLY a signature valid
 * under that secret lets the callback touch anything.
 */
class Processor
{
    /**
     * Order states that already reflect the money (idempotent re-delivery).
     */
    private const array SETTLED_ORDER_STATES = [
        Order::STATE_PROCESSING,
        Order::STATE_COMPLETE,
        Order::STATE_CLOSED,
        Order::STATE_HOLDED,
    ];

    /**
     * @param Json $json
     * @param Ledger $ledger
     * @param Signature $signature
     * @param Config $config
     * @param OrderSettler $settler
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param OrderLock $orderLock
     * @param LogSanitizer $logSanitizer
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly Json $json,
        private readonly Ledger $ledger,
        private readonly Signature $signature,
        private readonly Config $config,
        private readonly OrderSettler $settler,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly OrderLock $orderLock,
        private readonly LogSanitizer $logSanitizer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Process one callback delivery.
     *
     * @param string $rawBody Raw request body (official wire format: JSON).
     * @param array<string, mixed> $postParams Form params, accepted as a
     *     legacy fallback — cloudipsp-era gateways delivered form-encoded
     *     callbacks; the log line below shows which shape a live
     *     callback actually used.
     * @return Result
     * @throws FlittApiException
     */
    public function process(string $rawBody, array $postParams = []): Result
    {
        $params = $this->parse($rawBody, $postParams);

        if ($params === null) {
            $this->logger->error(sprintf(
                'callback: malformed delivery (not JSON, no form params), body: %s',
                $this->logSanitizer->excerpt($rawBody)
            ));

            return new Result(HttpResponse::STATUS_CODE_400, 'malformed callback');
        }

        $flittOrderId = $params['order_id'] ?? null;

        if (!is_string($flittOrderId) || $flittOrderId === '') {
            $this->logger->error('callback: delivery without an order_id');

            return new Result(HttpResponse::STATUS_CODE_400, 'missing order_id');
        }

        $loggedOrderId = $this->logSanitizer->line($flittOrderId);
        $row = $this->ledger->find($flittOrderId);

        if ($row === null) {
            $this->logger->warning(sprintf('callback: unknown flitt order "%s" — acknowledged, ignored', $loggedOrderId));

            return new Result(HttpResponse::STATUS_CODE_200, 'ignored');
        }

        $storeId = (int)$row['store_id'];

        try {
            $secretKey = $this->config->getSecretKey($storeId, strict: true);
        } catch (FlittApiException) {
            $this->logger->error(sprintf(
                'callback: no secret key for store %d (flitt order "%s") — asked Flitt to retry',
                $storeId,
                $loggedOrderId
            ));

            return new Result(HttpResponse::STATUS_CODE_503, 'credentials unavailable');
        }

        if (!$this->signature->verify($params, $secretKey)) {
            $this->logger->error(sprintf('callback: INVALID SIGNATURE for flitt order "%s" — rejected', $loggedOrderId));

            return new Result(HttpResponse::STATUS_CODE_400, 'invalid signature');
        }

        if ((int)($params['merchant_id'] ?? 0) !== $this->config->getMerchantId($storeId)) {
            $this->logger->error(sprintf(
                'callback: merchant mismatch for flitt order "%s" (got %d) — rejected',
                $loggedOrderId,
                (int)($params['merchant_id'] ?? 0)
            ));

            return new Result(HttpResponse::STATUS_CODE_400, 'merchant mismatch');
        }

        if ($this->config->isDebugEnabled($storeId)) {
            $this->logger->debug('callback payload', ['payload' => $this->logSanitizer->mask($params)]);
        }

        $incrementId = (string)$row['increment_id'];

        if (!$this->orderLock->acquire($incrementId)) {
            $this->logger->warning(sprintf(
                'callback: could not lock order %s (flitt order "%s") — asked Flitt to retry',
                $incrementId,
                $loggedOrderId
            ));

            return new Result(HttpResponse::STATUS_CODE_503, 'busy');
        }

        try {
            $this->ledger->recordCallback($flittOrderId, $this->facts($params));

            if ((string)($params['verification_status'] ?? '') !== '') {
                // Card-verification orders are not sales; never settle one.
                $this->logger->info(sprintf(
                    'callback: verification order "%s" (%s) acknowledged',
                    $loggedOrderId,
                    $this->logSanitizer->line((string)$params['verification_status'])
                ));

                return new Result(HttpResponse::STATUS_CODE_200, 'verification acknowledged');
            }

            $orderStatus = (string)($params['order_status'] ?? '');

            $result = match ($orderStatus) {
                'approved' => $this->handleApproved($flittOrderId, $row),
                'declined' => $this->acknowledge($flittOrderId, Ledger::STATE_DECLINED, $orderStatus),
                'expired' => $this->acknowledge($flittOrderId, Ledger::STATE_EXPIRED, $orderStatus),
                'reversed' => $this->acknowledge($flittOrderId, Ledger::STATE_REVERSED, $orderStatus),
                default => new Result(HttpResponse::STATUS_CODE_200, 'noted'),
            };

            $this->logger->info(sprintf(
                'callback: flitt order "%s" status "%s" -> %d %s',
                $loggedOrderId,
                $this->logSanitizer->line($orderStatus),
                $result->httpCode,
                $result->body
            ));

            return $result;
        } finally {
            $this->orderLock->release($incrementId);
        }
    }

    /**
     * The approved branch — the only one that may touch a Magento order.
     *
     * @param string $flittOrderId
     * @param array<string, mixed> $row Ledger row.
     * @return Result
     */
    private function handleApproved(string $flittOrderId, array $row): Result
    {
        $order = $this->findOrder((string)$row['increment_id'], (int)$row['store_id']);

        if ($order === null) {
            $this->ledger->transition($flittOrderId, Ledger::STATE_APPROVED);

            return new Result(HttpResponse::STATUS_CODE_200, 'approved recorded');
        }

        $payment = $order->getPayment();

        if (!$payment instanceof Payment || $payment->getMethod() !== ConfigProvider::CODE) {
            $this->logger->error(sprintf(
                'callback: order %s is not a Flitt order — approved hold parked for reversal',
                $order->getIncrementId()
            ));
            $this->ledger->transition($flittOrderId, Ledger::STATE_APPROVED);

            return new Result(HttpResponse::STATUS_CODE_200, 'approved for non-flitt order');
        }

        $state = (string)$order->getState();

        if ($state === Order::STATE_PENDING_PAYMENT) {
            try {
                $this->settler->settle($order, $flittOrderId);
            } catch (PaymentSessionMismatchException) {
                $this->logger->error(sprintf(
                    'callback: approved hold "%s" is not order %s\'s current session — parked for reversal',
                    $this->logSanitizer->line($flittOrderId),
                    $order->getIncrementId()
                ));
                $this->ledger->transition($flittOrderId, Ledger::STATE_APPROVED);

                return new Result(HttpResponse::STATUS_CODE_200, 'approved for superseded session');
            } catch (CommandException $e) {
                $this->logger->error(sprintf(
                    'callback: settling order %s failed (%s) — asked Flitt to retry',
                    $order->getIncrementId(),
                    $e->getMessage()
                ));

                return new Result(HttpResponse::STATUS_CODE_503, 'settle failed');
            }

            $this->ledger->transition($flittOrderId, Ledger::STATE_SETTLED);

            return new Result(HttpResponse::STATUS_CODE_200, 'settled');
        }

        if (in_array($state, self::SETTLED_ORDER_STATES, true)) {
            $settledBy = (string)$payment->getAdditionalInformation(TokenManager::INFO_ORDER_ID);

            if ($settledBy !== $flittOrderId) {
                $this->logger->error(sprintf(
                    'callback: approved hold "%s" is not the session order %s settled with ("%s")'
                    . ' — parked for reversal',
                    $this->logSanitizer->line($flittOrderId),
                    $order->getIncrementId(),
                    $this->logSanitizer->line($settledBy !== '' ? $settledBy : '(none)')
                ));
                $this->ledger->transition($flittOrderId, Ledger::STATE_APPROVED);

                return new Result(HttpResponse::STATUS_CODE_200, 'approved for order settled by another session');
            }

            $this->ledger->transition($flittOrderId, Ledger::STATE_SETTLED);

            return new Result(HttpResponse::STATUS_CODE_200, 'already settled');
        }

        if ($state === Order::STATE_CANCELED) {
            $this->logger->error(sprintf(
                'callback: approved payment for CANCELED order %s — hold parked for reversal',
                $order->getIncrementId()
            ));
            $this->ledger->transition($flittOrderId, Ledger::STATE_APPROVED);

            return new Result(HttpResponse::STATUS_CODE_200, 'approved for canceled order');
        }

        $this->logger->warning(sprintf(
            'callback: approved payment for order %s in unexpected state "%s" — recorded only',
            $order->getIncrementId(),
            $state
        ));
        $this->ledger->transition($flittOrderId, Ledger::STATE_APPROVED);

        return new Result(HttpResponse::STATUS_CODE_200, 'noted');
    }

    /**
     * Record a terminal-ish attempt outcome that needs no order action.
     *
     * @param string $flittOrderId
     * @param string $ledgerState
     * @param string $orderStatus
     * @return Result
     */
    private function acknowledge(string $flittOrderId, string $ledgerState, string $orderStatus): Result
    {
        $this->ledger->transition($flittOrderId, $ledgerState);

        return new Result(HttpResponse::STATUS_CODE_200, $orderStatus . ' recorded');
    }

    /**
     * Decode the delivery: JSON body first (the documented format), form
     * params as a legacy fallback; either may nest the fields as
     * {"response": {...}} — the signature covers the inner fields.
     *
     * @param string $rawBody
     * @param array<string, mixed> $postParams
     * @return array<string, mixed>|null
     */
    private function parse(string $rawBody, array $postParams): ?array
    {
        $decoded = null;

        if ($rawBody !== '') {
            try {
                $decoded = $this->json->unserialize($rawBody);
            } catch (InvalidArgumentException) {

            }
        }

        if (!is_array($decoded) && $postParams !== []) {
            $this->logger->info('callback: form-encoded delivery (legacy wire format)');
            $decoded = $postParams;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $inner = $decoded['response'] ?? null;

        if (is_array($inner) && isset($inner['order_id'])) {
            return $inner;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, int|string|null>
     */
    private function facts(array $params): array
    {
        return [
            'payment_id' => $this->intOrNull($params['payment_id'] ?? null),
            'order_status' => $this->stringOrNull($params['order_status'] ?? null),
            'response_code' => $this->stringOrNull($params['response_code'] ?? null),
            'masked_card' => $this->stringOrNull($params['masked_card'] ?? null),
            'card_type' => $this->stringOrNull($params['card_type'] ?? null),
            'card_bin' => $this->intOrNull($params['card_bin'] ?? null),
            'rectoken_lifetime' => $this->stringOrNull($params['rectoken_lifetime'] ?? null),
        ];
    }

    /**
     * @param mixed $value
     * @return string|null
     */
    private function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) && (string)$value !== '' ? (string)$value : null;
    }

    /**
     * @param mixed $value
     * @return int|null
     */
    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int)$value !== 0 ? (int)$value : null;
    }

    /**
     * The Magento order a ledger row points at, when it exists.
     *
     * @param string $incrementId
     * @param int $storeId
     * @return OrderInterface|null
     */
    private function findOrder(string $incrementId, int $storeId): ?OrderInterface
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $incrementId)
            ->addFilter('store_id', $storeId)
            ->setPageSize(1)
            ->create();

        $orders = $this->orderRepository->getList($criteria)->getItems();

        return array_shift($orders);
    }
}
