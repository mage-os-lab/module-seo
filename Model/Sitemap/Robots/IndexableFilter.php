<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap\Robots;

use MageOS\Seo\Api\Sitemap\ItemFilterInterface;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;

/**
 * Leaves out of the sitemap every page whose robots directive is NOINDEX.
 *
 * A sitemap lists the URLs a search engine should index; listing one the page itself tells it not
 * to index sends two contradicting signals, which Search Console reports as an error.
 *
 * Reads the directive Enricher filed. An item without one is kept — the setting is off, so the
 * enricher filed nothing.
 */
class IndexableFilter implements ItemFilterInterface
{
    /**
     * @inheritdoc
     */
    public function isIncluded(SitemapItemInterface $item, int $storeId): bool
    {
        $directive = $item->getDataBag()[Enricher::BAG_KEY] ?? null;

        return !$directive instanceof Directive || !$directive->isNoindex();
    }
}
