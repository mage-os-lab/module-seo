<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Store;

use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * A store view's base URL as its canonical URLs use it — independent of the request.
 *
 * Magento's own sitemap builds `<loc>` from the link base URL with the store view's configured
 * secure flag. `Store::getBaseUrl()` without arguments decides http or https from the current
 * request instead, which under cron or the CLI is plain http, whatever the store is configured
 * for. Every URL this module writes for a store view — sitemap rows, hreflang alternates — is built
 * from here, so a URL and the alternates that point at it cannot disagree about their scheme.
 */
class CanonicalBaseUrl
{
    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The base URL of the store view with the given ID, without a trailing slash.
     *
     * @param int $storeId
     * @return string
     */
    public function forStore(int $storeId): string
    {
        /** @var Store $store */
        $store = $this->storeManager->getStore($storeId);

        return $this->of($store);
    }

    /**
     * The base URL of the given store view, without a trailing slash.
     *
     * @param Store $store
     * @return string
     */
    public function of(Store $store): string
    {
        return rtrim((string) $store->getBaseUrl(UrlInterface::URL_TYPE_LINK, $store->isUrlSecure()), '/');
    }
}
