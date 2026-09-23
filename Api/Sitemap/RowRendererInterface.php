<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Sitemap;

/**
 * Writes part of a sitemap row.
 *
 * Every row is the `<url>` element around what the registered renderers return, in their sort
 * order: core's fields first (`<loc>`, `<lastmod>`, …), then images, then whatever else is
 * registered — alternates, video, news. A renderer reads the item's fields and data bag and
 * returns its elements, or an empty string when it has nothing for the item.
 *
 * Register on `MageOS\Seo\Model\Sitemap\Generator`'s `renderers` argument, with a `sortOrder`.
 *
 * @api
 */
interface RowRendererInterface
{
    /**
     * The XML namespaces the renderer's elements use, as prefix => URI.
     *
     * Declared once on each file's `<urlset>`.
     *
     * @return array<string,string>
     */
    public function getNamespaces(): array;

    /**
     * The renderer's elements for one item, escaped and ready to write.
     *
     * @param SitemapItemInterface $item
     * @param int $storeId
     * @return string
     */
    public function render(SitemapItemInterface $item, int $storeId): string;
}
