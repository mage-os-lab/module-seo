<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Canonical;

use Magento\Framework\View\Page\Config as PageConfig;
use MageOS\Seo\Api\CanonicalUrlManagerInterface;

/**
 * Manages canonical URL output for product and category pages.
 *
 * Bridge-module API: nothing inside MageOS_Seo calls setCanonical() — sibling modules
 * (e.g. product-variant URL modules) use it to replace the core canonical with their
 * own. It is the single authoritative place for canonical manipulation so consumers
 * never end up emitting duplicate canonical tags.
 */
class CanonicalUrlManager implements CanonicalUrlManagerInterface
{
    /**
     * Set the canonical URL, replacing any existing canonical already emitted.
     *
     * @param string $canonicalUrl Absolute URL to use as the canonical
     * @param \Magento\Framework\View\Page\Config $pageConfig
     * @return void
     */
    public function setCanonical(string $canonicalUrl, PageConfig $pageConfig): void
    {
        $this->removeExistingCanonicals($pageConfig);

        $pageConfig->addRemotePageAsset(
            $canonicalUrl,
            'canonical',
            ['attributes' => ['rel' => 'canonical']]
        );
    }

    /**
     * Remove every existing canonical link asset (e.g. the default one core adds).
     *
     * Matches on the asset content type: the page asset collection also holds all
     * CSS/JS/image assets, so matching identifiers against a URL-key pattern could
     * remove arbitrary assets (a url_key of "print" would match css/print.css).
     *
     * @param \Magento\Framework\View\Page\Config $pageConfig
     * @return void
     */
    private function removeExistingCanonicals(PageConfig $pageConfig): void
    {
        $assets = $pageConfig->getAssetCollection();
        foreach ($assets->getAll() as $identifier => $asset) {
            if ($asset->getContentType() === 'canonical') {
                $assets->remove((string) $identifier);
            }
        }
    }
}
