<?php

declare(strict_types=1);

namespace Shubo\Flitt\Controller\Checkout;

use Laminas\Http\Response as HttpResponse;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Model\Checkout\PaymentStateResolver;

/**
 * AJAX endpoint classifying the quote's Flitt payment session
 * (POST /flitt/checkout/state, pay-first flow). Same CSRF posture as the
 * token endpoint: Magento's default frontend POST validation. Returns only
 * the coarse advice state — placement always re-verifies server-side, so
 * nothing here is trusted with money decisions.
 */
class State implements HttpPostActionInterface
{
    /**
     * @param CheckoutSession $checkoutSession
     * @param PaymentStateResolver $stateResolver
     * @param JsonFactory $jsonFactory
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly PaymentStateResolver $stateResolver,
        private readonly JsonFactory $jsonFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Classify the session's active quote payment session.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        try {
            $quote = $this->checkoutSession->getQuote();

            if (!$quote->getId()) {
                return $result->setData(['state' => PaymentStateResolver::STATE_NONE]);
            }

            return $result->setData(['state' => $this->stateResolver->resolve($quote)]);
        } catch (\Throwable $e) {
            $this->logger->error('checkout/state endpoint failure: ' . $e->getMessage());

            return $result->setHttpResponseCode(HttpResponse::STATUS_CODE_500)
                ->setData(['state' => PaymentStateResolver::STATE_UNKNOWN]);
        }
    }
}
