<?php

declare(strict_types=1);

namespace Shubo\Flitt\Controller\Callback;

use Laminas\Http\Response as HttpResponse;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Psr\Log\LoggerInterface;
use Shubo\Flitt\Model\Callback\Processor;
use Shubo\Flitt\Model\Callback\Result;

/**
 * Flitt server-to-server callback endpoint (POST /flitt/callback/index)
 * CSRF is bypassed (host-to-host POST; the Flitt signature is the authentication)
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @param HttpRequest $request
     * @param RawFactory $rawFactory
     * @param Processor $processor
     * @param LoggerInterface $logger Bound to ShuboFlittLogger in di.xml.
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly RawFactory $rawFactory,
        private readonly Processor $processor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return Raw
     */
    public function execute(): Raw
    {
        try {
            $result = $this->processor->process(
                (string)$this->request->getContent(),
                (array)$this->request->getPostValue()
            );
        } catch (\Throwable $e) {
            // Unexpected — treat as transient so Flitt redelivers.
            $this->logger->error('callback: unhandled failure — ' . $e->getMessage());
            $result = new Result(HttpResponse::STATUS_CODE_503, 'error');
        }

        return $this->rawFactory->create()
            ->setHttpResponseCode($result->httpCode)
            ->setHeader('Content-Type', 'text/plain', true)
            ->setContents($result->body);
    }

    /**
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
