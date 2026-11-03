<?php

declare(strict_types=1);

namespace Shubo\Flitt\Model\Callback;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The absolute Flitt-facing URLs for a store: server_callback_url (where
 * Flitt POSTs the signed host-to-host payment result) and the
 * browser-return response_url/cancel_url.
 *
 * Flitt callbacks do NOT follow redirects, so these are built from the
 * store's own link base URL (secure when configured) rather than anything
 * that could bounce — a www/scheme/trailing redirect would silently eat
 * every callback.
 */
class CallbackUrl
{
    private const string ROUTE_PATH = 'flitt/callback/index';

    /**
     * One route serves BOTH response_url and cancel_url: the return
     * controller resolves the outcome from the signed payload/ledger/status,
     * never from which link the customer arrived by.
     */
    private const string RETURN_ROUTE_PATH = 'flitt/redirect/complete';

    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * @param int|null $storeId Null resolves the current store.
     * @return string
     * @throws \RuntimeException When the resolved store is not a concrete frontend store.
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function forStore(?int $storeId = null): string
    {
        return $this->baseUrl($storeId) . self::ROUTE_PATH;
    }

    /**
     * The browser-return URL (response_url AND cancel_url).
     *
     * @param int|null $storeId Null resolves the current store.
     * @return string
     * @throws \RuntimeException When the resolved store is not a concrete frontend store.
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function returnForStore(?int $storeId = null): string
    {
        return $this->baseUrl($storeId) . self::RETURN_ROUTE_PATH;
    }

    /**
     * @param int|null $storeId
     * @return string
     * @throws \RuntimeException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function baseUrl(?int $storeId): string
    {
        $store = $this->storeManager->getStore($storeId);

        if (!$store instanceof Store) {
            throw new \RuntimeException(sprintf(
                'Cannot build a Flitt callback URL: store %s has no base URL.',
                $store->getCode()
            ));
        }

        return $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true);
    }
}
