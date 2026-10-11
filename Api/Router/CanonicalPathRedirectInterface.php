<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Router;

use Magento\Framework\Controller\Result\Redirect;

/**
 * 301-redirects a directly served document's request to its canonical path.
 *
 * The cache key of a page includes its query string, and a trailing slash or the internal controller
 * URL is another URL again: each would be a separate cache entry regenerating the document. A
 * controller serving such a document asks first, and returns the redirect when there is one.
 *
 * @api
 */
interface CanonicalPathRedirectInterface
{
    /**
     * A 301 redirect to the canonical path, or null when the request is already for it.
     *
     * @param string $canonicalPath Path relative to the store's base URL, e.g. "llms.txt"
     * @return Redirect|null
     */
    public function check(string $canonicalPath): ?Redirect;
}
