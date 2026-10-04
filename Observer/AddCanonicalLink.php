<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\Framework\Escaper;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use MageOS\Seo\Model\Config;

/**
 * Adds a canonical link to CMS pages, the home page included, as a page asset.
 *
 * Magento adds none to CMS pages. The link is added the way core adds its category and product
 * canonicals — `PageConfig::addRemotePageAsset()`, content type `canonical` — so other code can read
 * it, and `Model\Canonical\CanonicalUrlManager` can replace it.
 *
 * The URL is CmsPageResolver::currentUrl(): the store base URL on the home page — recognised as
 * core's router recognises it, by an empty path — and the base URL plus the identifier on every
 * other CMS page. It is built from the configured store base URL, never from the client-supplied
 * Host header, so store codes survive and cache poisoning cannot skew the output.
 *
 * Runs on layout_generate_blocks_after, once every block's _prepareLayout() has run, so a
 * canonical another block added is seen and left alone. Off everything but CMS pages: search,
 * cart, checkout and account pages get no canonical, since echoing such a URL back as its own
 * canonical legitimises duplicates instead of consolidating them. Switched off per store view by
 * Use Canonical Link Meta Tag For CMS Pages.
 */
class AddCanonicalLink implements ObserverInterface
{
    /**
     * Core's `Cms\Helper\Page::prepareResultPage()` adds it for the home page and every CMS page.
     */
    private const HANDLE_CMS_PAGE = 'cms_page_view';

    /**
     * @param PageConfig $pageConfig
     * @param CmsPageResolver $cmsPageResolver
     * @param Config $seoConfig
     * @param Escaper $escaper
     */
    public function __construct(
        private readonly PageConfig      $pageConfig,
        private readonly CmsPageResolver $cmsPageResolver,
        private readonly Config          $seoConfig,
        private readonly Escaper         $escaper
    ) {
    }

    /**
     * Add the CMS page's canonical, unless the page has one or should have none.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if (!$this->seoConfig->isCmsCanonicalEnabled() || $this->hasExistingCanonical()) {
            return;
        }

        $layout = $observer->getEvent()->getData('layout');
        if (!$layout instanceof LayoutInterface
            || !\in_array(self::HANDLE_CMS_PAGE, $layout->getUpdate()->getHandles(), true)
        ) {
            // Off everything but CMS pages — and off `/` when web/default/front serves something else.
            return;
        }

        try {
            $url = $this->escaper->escapeUrl($this->cmsPageResolver->currentUrl());
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- never break rendering
            return;
        }
        if ($url === '') {
            return;
        }

        $this->pageConfig->addRemotePageAsset($url, 'canonical', ['attributes' => ['rel' => 'canonical']]);
    }

    /**
     * Whether a canonical link asset has already been added by core or another module.
     *
     * Detected by asset content type: matching identifiers would false-positive on unrelated
     * remote assets (font preloads, og images).
     *
     * @return bool
     */
    private function hasExistingCanonical(): bool
    {
        foreach ($this->pageConfig->getAssetCollection()->getAll() as $asset) {
            if ($asset->getContentType() === 'canonical') {
                return true;
            }
        }

        return false;
    }
}
