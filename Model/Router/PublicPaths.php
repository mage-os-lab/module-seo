<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Router;

/**
 * The paths served directly as public documents — the llms files, the `/.well-known/` documents and
 * their internal controller URLs — as the modules that serve them register them.
 *
 * The routers own the forwarding, but they are not the only code that has to recognise these
 * requests: a session must not be started for them, because starting one sets a session cookie and
 * makes PHP emit no-cache headers on a response meant to be shared-cached for a day. That decision
 * happens before routing, so it cannot ask the routers — hence this registry, which a module that
 * serves such documents adds its paths to from its own di.xml:
 *
 * - `paths`: exact request paths, such as `llms.txt`;
 * - `prefixes`: path prefixes ending in `/`, such as `.well-known/` — every path under one is
 *   public, the bare prefix is not (nothing is served there).
 *
 * Paths are compared without surrounding slashes.
 */
class PublicPaths
{
    /**
     * @param string[] $paths Exact request paths, without surrounding slashes
     * @param string[] $prefixes Path prefixes, each ending in `/`
     */
    public function __construct(
        private readonly array $paths = [],
        private readonly array $prefixes = []
    ) {
    }

    /**
     * Whether a request path is one a module registered as public.
     *
     * @param string $pathInfo Raw path info, with or without surrounding slashes
     * @return bool
     */
    public function isPublicPath(string $pathInfo): bool
    {
        $path = trim($pathInfo, '/');
        if (\in_array($path, $this->paths, true)) {
            return true;
        }

        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
