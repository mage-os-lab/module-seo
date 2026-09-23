<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Sitemap;

/**
 * Decides whether a sitemap item is listed.
 *
 * Runs after the enrichers, so it can decide from the item's data bag. An item every filter
 * accepts is written; one any filter rejects is left out.
 *
 * Register on `MageOS\Seo\Model\Sitemap\Generator`'s `filters` argument.
 *
 * @api
 */
interface ItemFilterInterface
{
    /**
     * Whether the item belongs in the sitemap.
     *
     * @param SitemapItemInterface $item
     * @param int $storeId
     * @return bool
     */
    public function isIncluded(SitemapItemInterface $item, int $storeId): bool;
}
