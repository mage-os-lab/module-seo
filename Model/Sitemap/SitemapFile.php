<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap;

use Magento\Sitemap\Model\ResourceModel\Sitemap as SitemapResource;
use Magento\Sitemap\Model\SitemapFactory;

/**
 * A Site Map entry's file, as Marketing → Site Map lists it, for naming the entry to an admin.
 *
 * Kept to this one load through the generated SitemapFactory, so the classes that name a sitemap
 * stay in the mutation run (see infection.json5).
 */
class SitemapFile
{
    /**
     * @param SitemapFactory $sitemapFactory
     * @param SitemapResource $sitemapResource
     */
    public function __construct(
        private readonly SitemapFactory  $sitemapFactory,
        private readonly SitemapResource $sitemapResource
    ) {
    }

    /**
     * The entry's path and file name ("/sitemap.xml"), or null when there is no such entry.
     *
     * @param int $sitemapId
     * @return string|null
     */
    public function pathOf(int $sitemapId): ?string
    {
        $sitemap = $this->sitemapFactory->create();
        $this->sitemapResource->load($sitemap, $sitemapId);

        $file = (string) $sitemap->getSitemapFilename();

        return $file === '' ? null : (string) $sitemap->getSitemapPath() . $file;
    }
}
