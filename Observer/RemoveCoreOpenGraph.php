<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\LayoutInterface;
use MageOS\Seo\Model\Config;

/**
 * Stops core's product Open Graph block rendering alongside this module's.
 *
 * catalog_product_view pulls in catalog_product_opengraph, which adds `opengraph.general`:
 * og:type, og:title, og:image, og:description, og:url, and product:price:amount/currency through
 * its `opengraph.currency` child. This module emits every one of those, plus og:locale,
 * og:site_name, product:availability and a Twitter card, so leaving core's block in place put
 * two of each tag on every product page — and a crawler meeting two og:image values picks one.
 *
 * Removed at runtime rather than with `remove="true"` in layout XML, because the removal has to
 * follow this module's own setting. A layout remove is unconditional: with this module's Open
 * Graph output switched off, product pages would be left carrying none at all.
 */
class RemoveCoreOpenGraph implements ObserverInterface
{
    /**
     * Core's block, as named in Magento_Catalog::layout/catalog_product_opengraph.xml.
     */
    private const CORE_BLOCK = 'opengraph.general';

    /**
     * @param Config $seoConfig
     */
    public function __construct(
        private readonly Config $seoConfig
    ) {
    }

    /**
     * Unset core's Open Graph block when this module is the one emitting Open Graph.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        if (!$this->seoConfig->isOgTagsEnabled()) {
            return;
        }

        $layout = $observer->getEvent()->getData('layout');
        if (!$layout instanceof LayoutInterface || !$layout->hasElement(self::CORE_BLOCK)) {
            return;
        }

        // Removes the currency child with it: the element and everything nested in it go.
        $layout->unsetElement(self::CORE_BLOCK);
    }
}
