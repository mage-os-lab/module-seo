<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Sitemap;

/**
 * Adds information to sitemap items before they are filtered and rendered.
 *
 * Receives items a chunk at a time — about a thousand — so it can load what it needs for all of
 * them in one query per entity type rather than one per item, and files it in each item's data
 * bag under its own key for a filter or renderer to read.
 *
 * Register on `MageOS\Seo\Model\Sitemap\Generator`'s `enrichers` argument.
 *
 * @api
 */
interface ItemEnricherInterface
{
    /**
     * Fill the data bags of a chunk of items.
     *
     * @param SitemapItemInterface[] $items
     * @param int $storeId
     * @return void
     */
    public function enrich(array $items, int $storeId): void;
}
