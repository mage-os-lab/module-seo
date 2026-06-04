<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Canonical;

use Magento\Framework\View\Page\Config as PageConfig;

/**
 * Manages canonical URL output for product and category pages.
 *
 * Solves the duplicate-canonical problem documented in ProductVariantUrl_Preselect_CONTEXT.md
 * by removing the default canonical before setting the correct one. This service is the
 * single authoritative place for canonical manipulation across all MageOS modules.
 */
class CanonicalUrlManager
{
    /**
     * Set the canonical URL, replacing any existing canonical already emitted.
     *
     * @param string $canonicalUrl Absolute URL to use as the canonical
     * @param \Magento\Framework\View\Page\Config $pageConfig
     * @param string $urlKey The product or category URL key (without suffix), used to
     *                       identify the default canonical to remove
     * @return void
     */
    public function setCanonical(string $canonicalUrl, PageConfig $pageConfig, string $urlKey = ''): void
    {
        if ($urlKey !== '') {
            $this->removeDefaultCanonical($pageConfig, $urlKey);
        }

        $pageConfig->addRemotePageAsset(
            $canonicalUrl,
            'canonical',
            ['attributes' => ['rel' => 'canonical']]
        );
    }

    /**
     * Remove the default canonical URL that Magento adds based on the product/category URL key.
     *
     * The asset collection is keyed by the full URL string, so we match on the url_key suffix.
     *
     * @param \Magento\Framework\View\Page\Config $pageConfig
     * @param string $urlKey
     * @return void
     */
    private function removeDefaultCanonical(PageConfig $pageConfig, string $urlKey): void
    {
        $assets = $pageConfig->getAssetCollection();
        $pattern = '#/' . preg_quote($urlKey, '#') . '(\.[a-zA-Z]{1,5})?$#';
        foreach (array_keys($assets->getAll()) as $identifier) {
            if (preg_match($pattern, (string) $identifier)) {
                $assets->remove($identifier);
            }
        }
    }
}
