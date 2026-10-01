<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\LlmsTxt;

use Magento\Sitemap\Model\ResourceModel\Sitemap\CollectionFactory as SitemapCollectionFactory;
use Magento\Sitemap\Model\Sitemap;
use Magento\Store\Api\Data\StoreInterface;

/**
 * Finds the public URL of a store view's XML sitemap, for the llms.txt "Sitemap" link.
 *
 * The sitemaps are the ones configured under Marketing → Site Map; their file is often not
 * /sitemap.xml. The URL is core's own Sitemap::getSitemapUrl() for the most recently
 * generated sitemap of the store view (the one generated last when there are several), so it
 * follows the same document-root rules as the sitemap itself. No sitemap, no link.
 *
 * A failure to read the sitemaps is not caught: FeedRegenerator logs the store's failed build
 * and keeps the previous file, which is better than silently dropping the link.
 */
class SitemapUrlResolver
{
    /**
     * @param SitemapCollectionFactory $sitemapCollectionFactory
     */
    public function __construct(
        private readonly SitemapCollectionFactory $sitemapCollectionFactory
    ) {
    }

    /**
     * URL of the store view's most recently generated sitemap, or null when it has none.
     *
     * @param StoreInterface $store
     * @return string|null
     */
    public function getUrl(StoreInterface $store): ?string
    {
        $collection = $this->sitemapCollectionFactory->create();
        $collection->addStoreFilter([(int) $store->getId()]);
        $collection->setOrder('sitemap_time', 'DESC');
        $collection->setOrder('sitemap_id', 'DESC');

        /** @var Sitemap $sitemap */
        foreach ($collection as $sitemap) {
            $fileName = trim((string) $sitemap->getSitemapFilename(), '/');
            if ($fileName === '') {
                continue;
            }

            $url = trim((string) $sitemap->getSitemapUrl((string) $sitemap->getSitemapPath(), $fileName));
            return $url !== '' ? $url : null;
        }

        return null;
    }
}
