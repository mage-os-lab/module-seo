<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\RobotsMeta\Provider;

use MageOS\Seo\Api\RobotsMetaProviderInterface;
use MageOS\Seo\Model\Config;

/**
 * Robots meta for search result pages: the Search Results Pages Default.
 *
 * Covers quick search and advanced search results. Empty (the shipped default) means no opinion,
 * so core's Design → Search Engine Robots decides, as for product, category and CMS pages: a staging
 * site set to NOINDEX,NOFOLLOW stays as it is.
 *
 * NOINDEX,FOLLOW keeps result pages out of the index while their links are still followed. To stop
 * them being crawled at all — Google's advice for crawl budget — disallow them in robots.txt
 * instead; a page blocked there is never fetched, so its robots meta is never read.
 */
class SearchResultsRobotsProvider implements RobotsMetaProviderInterface
{
    /**
     * @param Config $seoConfig
     */
    public function __construct(
        private readonly Config $seoConfig
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHandles(): array
    {
        return ['catalogsearch_result_index', 'catalogsearch_advanced_result'];
    }

    /**
     * @inheritdoc
     */
    public function getRobots(int $storeId): ?string
    {
        $default = $this->seoConfig->getRobotsSearchDefault($storeId);

        return $default === '' ? null : $default;
    }

    /**
     * @inheritdoc
     */
    public function getSortOrder(): int
    {
        return 100;
    }
}
