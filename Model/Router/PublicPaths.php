<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Router;

/**
 * The paths this module serves directly, in one place.
 *
 * Two routers own the forwarding, but they are not the only code that has to recognise these
 * requests: a session must not be started for them, because starting one sets a session cookie and
 * makes PHP emit no-cache headers on a response meant to be shared-cached for a day. That decision
 * happens before routing, so it cannot ask the routers — hence this class, which each of them uses
 * too, so there is a single answer to "is this one of ours".
 */
class PublicPaths
{
    /**
     * Request path => the controller that answers it.
     */
    public const LLMS_ROUTES = [
        'llms.txt'      => ['module' => 'mageos-seo', 'controller' => 'llms',      'action' => 'index'],
        'llms-full.txt' => ['module' => 'mageos-seo', 'controller' => 'llmsfull',  'action' => 'index'],
        'llms.jsonl'    => ['module' => 'mageos-seo', 'controller' => 'llmsjsonl', 'action' => 'index'],
    ];

    /**
     * Everything under the agentic-discovery prefix.
     */
    public const WELL_KNOWN_PREFIX = '.well-known/';

    /**
     * The front name the routers forward to, shared by every controller here.
     */
    public const MODULE_FRONT_NAME = 'mageos-seo';

    /**
     * Whether a request path is one this module serves.
     *
     * @param string $pathInfo Raw path info, with or without surrounding slashes
     * @return bool
     */
    public function isPublicPath(string $pathInfo): bool
    {
        $path = trim($pathInfo, '/');

        return isset(self::LLMS_ROUTES[$path])
            || str_starts_with($path, self::WELL_KNOWN_PREFIX)
            // The internal controller URLs, which the canonical-path redirect sends back to the
            // paths above: they must not start a session on the way through either.
            || str_starts_with($path, self::MODULE_FRONT_NAME . '/');
    }
}
