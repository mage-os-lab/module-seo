<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * The settings of this module that other modules' storefront output follows.
 *
 * @api
 */
interface StorefrontSettingsInterface
{
    /**
     * Whether this module writes structured data (JSON-LD) in the store view.
     *
     * @param int|string|null $storeId Null for the current store view
     * @return bool
     */
    public function isStructuredDataEnabled(int|string|null $storeId = null): bool;

    /**
     * The store view's locale code (en_GB), or '' when none is configured.
     *
     * @param int $storeId
     * @return string
     */
    public function getLocaleCode(int $storeId): string;

    /**
     * The returns policy page's URL, or null when the store view publishes none.
     *
     * @param int $storeId
     * @return string|null
     */
    public function getReturnPolicyUrl(int $storeId): ?string;
}
