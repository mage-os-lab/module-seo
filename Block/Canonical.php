<?php

declare(strict_types=1);

namespace MageOS\Seo\Block;

use Magento\Framework\View\Element\Template;

/**
 * Outputs a canonical URL for the current page.
 *
 * Product and category pages manage their own canonicals via
 * CanonicalUrlManager (called from their respective providers/plugins).
 * This block handles all other pages — home, CMS, etc. — where no
 * other mechanism sets a canonical.
 *
 * It reads the current page URL from the store, stripping any query
 * string parameters that should not be part of the canonical.
 */
class Canonical extends Template
{
    /**
     * Return the canonical URL for the current page, or empty string if one has already been set.
     *
     * @return string
     */
    public function getCanonicalUrl(): string
    {
        // If a canonical has already been added to the asset collection
        // (by product or category providers via CanonicalUrlManager),
        // do not output a second one.
        // Canonicals are added via addRemotePageAsset() with rel=canonical,
        // and are keyed by their full URL in GroupedCollection.
        // We detect them by checking whether any asset key is an absolute
        // HTTP/HTTPS URL — which is only true for remote page assets
        // like canonicals and og: tags added via addRemotePageAsset().
        $assets = $this->pageConfig->getAssetCollection();
        foreach (array_keys($assets->getAll()) as $identifier) {
            $identifier = (string) $identifier;
            if (str_starts_with($identifier, 'http://') || str_starts_with($identifier, 'https://')) {
                // A remote page asset exists — likely a canonical added by
                // a product or category provider. Don't add another.
                return '';
            }
        }

        try {
            /** @var \Magento\Framework\App\Request\Http $request */
            $request = $this->_request;
            $scheme = $request->getScheme();
            $host   = $request->getHttpHost();
            $path   = $request->getPathInfo() ?: '/';

            return rtrim($scheme . '://' . $host . $path, '/') ?: '/';

        } catch (\Exception) {
            return '';
        }
    }

    /**
     * Render nothing if no canonical URL is needed.
     *
     * @return string
     */
    protected function _toHtml(): string
    {
        if ($this->getCanonicalUrl() === '') {
            return '';
        }
        return parent::_toHtml();
    }
}
