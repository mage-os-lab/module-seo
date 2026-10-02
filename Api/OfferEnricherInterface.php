<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Contributes additional fields to a product's Offer node in JSON-LD.
 *
 * Enrichers form a collect-all pool: every enricher's fragment is merged into the Offer, ordered by
 * sortOrder (higher wins on key conflicts). Built-ins cover shipping, returns and item condition; a
 * module with live shipping rates or per-seller return policies adds its own enricher via di.xml
 * without modifying MageOS_Seo.
 *
 * @api
 */
interface OfferEnricherInterface
{
    /**
     * Return Offer fragment fields to merge, or [] when this enricher contributes nothing.
     *
     * @param ProductInterface $product
     * @param int $storeId
     * @return array<string, mixed>
     */
    public function enrich(ProductInterface $product, int $storeId): array;

    /**
     * Merge precedence within the pool. Higher wins on conflicting keys. Built-ins use 100.
     *
     * @return int
     */
    public function getSortOrder(): int;
}
