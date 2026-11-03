<?php

declare(strict_types=1);

namespace Shubo\Flitt\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Shubo\Flitt\Gateway\Config;

/**
 * Gateway "currency" validator: Adapter::canUseForCurrency() consults this
 * pool entry, hiding the method at checkout for orders in currencies the
 * merchant has not allowed (payment/shubo_flitt/allowed_currencies).
 */
class CurrencyValidator extends AbstractValidator
{
    /**
     * @param ResultInterfaceFactory $resultFactory
     * @param Config $config
     */
    public function __construct(
        ResultInterfaceFactory $resultFactory,
        private readonly Config $config,
    ) {
        parent::__construct($resultFactory);
    }

    /**
     * Validate the ['currency' => string, 'storeId' => int|string|null] subject.
     *
     * @param array<string, mixed> $validationSubject
     * @return ResultInterface
     */
    public function validate(array $validationSubject): ResultInterface
    {
        $storeId = $validationSubject['storeId'] ?? null;

        return $this->createResult($this->config->isCurrencyAllowed(
            (string)($validationSubject['currency'] ?? ''),
            $storeId !== null ? (int)$storeId : null
        ));
    }
}
