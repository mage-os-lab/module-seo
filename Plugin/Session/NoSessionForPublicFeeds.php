<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\Session;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Session\SessionStartChecker;
use MageOS\Seo\Model\Router\PublicPaths;

/**
 * Keeps this module's public endpoints out of the session.
 *
 * Starting a session does two things to a response that is meant to be cached by everyone for a
 * day: it sets a session cookie, which stops shared caches storing it at all, and it makes PHP's
 * session module emit its cache limiter — `Pragma: no-cache`, a past `Expires` and a no-cache
 * `Cache-Control` — directly contradicting the policy the controller just set.
 *
 * None of these endpoints read or write session state: they serve a pre-generated file, or a
 * document assembled from configuration. SessionStartChecker is the framework's own hook for
 * this; core uses it to suppress sessions under CLI.
 */
class NoSessionForPublicFeeds
{
    /**
     * @param HttpRequest $request
     * @param PublicPaths $publicPaths
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly PublicPaths $publicPaths
    ) {
    }

    /**
     * Refuse the session start for this module's public paths, leaving every other request alone.
     *
     * @param SessionStartChecker $subject
     * @param bool $result
     * @return bool
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function afterCheck(SessionStartChecker $subject, bool $result): bool
    {
        if (!$result) {
            return false;
        }

        return !$this->publicPaths->isPublicPath((string) $this->request->getPathInfo());
    }
}
