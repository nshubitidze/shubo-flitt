<?php

declare(strict_types=1);

namespace Shubo\Flitt\Controller\Redirect;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Model\Checkout\ReturnDisposition;
use Shubo\Flitt\Model\Checkout\ReturnHandler;
use Shubo\Flitt\Model\Checkout\ReturnResult;
use Shubo\Flitt\Model\Ui\ConfigProvider;

/**
 * Browser return from the Flitt hosted page (GET|POST
 * /flitt/redirect/complete — response_url AND cancel_url point here).
 *
 * Lands on STANDARD Magento pages only: success → checkout/onepage/success,
 * everything else → the cart with a message. No custom pages, no pending
 * page (core has none either; the callback stays the source of truth).
 *
 * Session rules: Flitt POSTs cross-site, which
 * arrives WITHOUT the SameSite=Lax session cookie — if that request started
 * a session, its guest Set-Cookie would OVERWRITE the customer's real
 * session cookie and log them out. So the POST leg runs session-less
 * (Plugin\ReturnSessionChecker, PayPal's TransparentSessionChecker pattern)
 * and only TRAMPOLINES: 302 to this same route as GET with the params
 * carried over. The browser sends Lax cookies on the top-level GET, so the
 * GET leg works inside the customer's original, still-logged-in session.
 * When the cookie is genuinely gone (payment finished from an email link in
 * another browser), identification rides the SIGNED payload (ReturnHandler)
 * and the success-page keys are re-seeded Payflow-style. CSRF is bypassed
 * exactly like the server callback: the payload signature is the
 * authentication, and an unsigned hit degrades to the session-or-Unknown
 * path, which discloses nothing.
 */
class Complete implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @param HttpRequest $request
     * @param CheckoutSession $checkoutSession
     * @param ReturnHandler $returnHandler
     * @param RedirectFactory $redirectFactory
     * @param MessageManager $messageManager
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly CheckoutSession $checkoutSession,
        private readonly ReturnHandler $returnHandler,
        private readonly RedirectFactory $redirectFactory,
        private readonly MessageManager $messageManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * Resolve the return and land the customer on a standard page.
     *
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->redirectFactory->create();

        if ($this->request->isPost()) {
            // Session-less trampoline (see the class docblock): nothing here
            // may touch the session — no messages, no checkoutSession reads.
            return $redirect->setPath('flitt/redirect/complete', ['_query' => $this->forwardableParams()]);
        }

        try {
            $result = $this->returnHandler->handle(
                $this->request->getParams(),
                $this->sessionIncrementId()
            );
        } catch (\Throwable $e) {
            // The return leg must never 500 in the customer's face.
            $this->logger->error('return: unhandled failure — ' . $e->getMessage());
            $this->messageManager->addNoticeMessage(
                (string)__('Your payment is being processed. Please check your order status in a moment.')
            );

            return $redirect->setPath('checkout/cart');
        }

        switch ($result->disposition) {
            case ReturnDisposition::Success:
                $this->seedSuccessSession($result);

                return $redirect->setPath('checkout/onepage/success');

            case ReturnDisposition::Failed:
                $this->restoreCart($result);
                $this->messageManager->addErrorMessage(
                    (string)__('The payment was not completed. Your cart has been restored — please try again.')
                );

                return $redirect->setPath('checkout/cart');

            case ReturnDisposition::LatePaid:
                $this->restoreCart($result);
                $this->messageManager->addWarningMessage((string)__(
                    'We received your payment, but the order had already been cancelled.'
                    . ' The payment will be refunded automatically. Your cart has been restored —'
                    . ' please place the order again, or contact us if you need help.'
                ));

                return $redirect->setPath('checkout/cart');

            case ReturnDisposition::Processing:
                $this->messageManager->addNoticeMessage(
                    (string)__('Your payment is being processed. Please check your order status in a moment.')
                );

                return $redirect->setPath('checkout/cart');

            case ReturnDisposition::Unknown:
            default:
                $this->messageManager->addNoticeMessage((string)__(
                    'We could not identify your payment. If it completed, you will receive an order confirmation email.'
                ));

                return $redirect->setPath('checkout/cart');
        }
    }

    /**
     * The params the POST trampoline carries onto the GET leg: scalars only
     * (the signed envelope is flat), minus Magento's own form_key. The
     * signature rides along, so the GET leg re-authenticates the payload
     * exactly like a direct GET return from Flitt.
     *
     * @return array<string, string>
     */
    private function forwardableParams(): array
    {
        $params = [];

        foreach ($this->request->getParams() as $key => $value) {
            if (is_scalar($value) && $key !== 'form_key') {
                $params[(string)$key] = (string)$value;
            }
        }

        return $params;
    }

    /**
     * The session's own Flitt order increment id, '' when it has none.
     *
     * @return string
     */
    private function sessionIncrementId(): string
    {
        $order = $this->checkoutSession->getLastRealOrder();

        return $order->getId() && $order->getPayment() && $order->getPayment()->getMethod() === ConfigProvider::CODE
            ? (string)$order->getIncrementId()
            : '';
    }

    /**
     * Make the standard success page render: SuccessValidator needs
     * lastSuccessQuoteId + lastQuoteId + lastOrderId (set by placeOrder in
     * the ORIGINAL session; absent when the cookie was lost on the
     * cross-site return), and the order-number block reads lastRealOrderId.
     * Safe to seed: Success renders once and clears its own quote keys.
     *
     * @param ReturnResult $result
     * @return void
     */
    private function seedSuccessSession(ReturnResult $result): void
    {
        if ($result->orderId === null) {
            return;
        }

        $this->checkoutSession
            ->setLastQuoteId($result->quoteId)
            ->setLastSuccessQuoteId($result->quoteId)
            ->setLastOrderId($result->orderId)
            ->setLastRealOrderId($result->incrementId);
    }

    /**
     * Put the unpaid order's items back into an active cart (the order
     * itself stays pending while its session is payable — ReturnHandler
     * decides). restoreQuote() works off lastRealOrderId, which is
     * re-seeded first for cookie-less returns.
     *
     * @param ReturnResult $result
     * @return void
     */
    private function restoreCart(ReturnResult $result): void
    {
        try {
            if ($result->incrementId !== '') {
                $this->checkoutSession->setLastRealOrderId($result->incrementId);
            }

            $this->checkoutSession->restoreQuote();
        } catch (\Throwable $e) {
            // Best-effort: an unrestorable quote still lands on the cart page.
            $this->logger->error(sprintf(
                'return: quote restore for order %s failed: %s',
                $result->incrementId,
                $e->getMessage()
            ));
        }
    }
}
