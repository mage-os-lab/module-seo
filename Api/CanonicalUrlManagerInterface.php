<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

use Magento\Framework\View\Page\Config as PageConfig;

/**
 * Sets a page's canonical URL, replacing any canonical already on it.
 *
 * For a module whose page has a canonical of its own, such as a product-variant URL module: core's
 * catalog canonical and the module's would otherwise both be in the head, which is invalid. Nothing
 * in this module calls it.
 *
 * @api
 */
interface CanonicalUrlManagerInterface
{
    /**
     * Make the URL the page's only canonical.
     *
     * @param string $canonicalUrl Absolute URL
     * @param PageConfig $pageConfig The page's configuration
     * @return void
     */
    public function setCanonical(string $canonicalUrl, PageConfig $pageConfig): void;
}
