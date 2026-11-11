<?php

declare(strict_types=1);

namespace Shubo\Flitt\Controller\Checkout;

use Laminas\Http\Response as HttpResponse;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Exception\FlittApiException;
use Shubo\Flitt\Exception\PaymentInFlightException;
use Shubo\Flitt\Model\Checkout\PaymentStateResolver;
use Shubo\Flitt\Model\Checkout\TokenManager;

/**
 * AJAX endpoint minting the Flitt embed token for the current quote
 * (POST /flitt/checkout/token, pay-first flow). CSRF is Magento's default
 * for frontend POST actions: form key, or the X-Requested-With header for
 * AJAX (cross-origin XHR cannot send that header without CORS approval).
 * Only the token reaches the browser; the flitt_order_id stays server-side
 * for placement verification and the callback. When the quote already has an
 * approved session, no token is handed out: 409 with the payment state, so the
 * form shows "paid" or "contact us" instead of a second payment.
 */
class Token implements HttpPostActionInterface
{
    /**
     * @param CheckoutSession $checkoutSession
     * @param TokenManager $tokenManager
     * @param JsonFactory $jsonFactory
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     * @param PaymentStateResolver $stateResolver
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly TokenManager $tokenManager,
        private readonly JsonFactory $jsonFactory,
        private readonly LoggerInterface $logger,
        private readonly PaymentStateResolver $stateResolver,
    ) {
    }

    /**
     * Mint or reuse the embed token for the session's active quote.
     *
     * @return Json
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        try {
            $quote = $this->checkoutSession->getQuote();

            if (!$quote->getId() || !$quote->getItemsCount()) {
                return $result->setHttpResponseCode(HttpResponse::STATUS_CODE_400)
                    ->setData(['error' => (string)__('Your cart is empty.')]);
            }

            $token = $this->tokenManager->getTokenForQuote($quote)['token'];

            return $result->setData(['token' => $token]);
        } catch (PaymentInFlightException) {
            return $result->setHttpResponseCode(HttpResponse::STATUS_CODE_409)
                ->setData(['state' => $this->stateResolver->resolve($this->checkoutSession->getQuote())]);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('checkout/token endpoint rejected quote: ' . $e->getMessage());

            return $result->setHttpResponseCode(HttpResponse::STATUS_CODE_400)
                ->setData(['error' => (string)__('This cart cannot be paid with this method.')]);
        } catch (FlittApiException $e) {
            // FlittClient already logged the failure in detail.
            return $result->setHttpResponseCode(HttpResponse::STATUS_CODE_502)
                ->setData(['error' => (string)__('The payment service is temporarily unavailable. Please try again.')]);
        } catch (\Throwable $e) {
            // Quote save failures etc. — the embed JS must always get its JSON contract.
            $this->logger->error('checkout/token endpoint failure: ' . $e->getMessage());

            return $result->setHttpResponseCode(HttpResponse::STATUS_CODE_500)
                ->setData(['error' => (string)__('The payment service is temporarily unavailable. Please try again.')]);
        }
    }
}
