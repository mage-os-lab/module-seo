<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap\Robots;

/**
 * The robots directive a sitemap item's page is served with, as filed in its data bag.
 *
 * This module's directive for the page where it has one, else core's Design → Search Engine Robots
 * — what the page's head carries unless another module changes it while the page renders.
 */
class Directive
{
    /**
     * @param string $directive Comma-separated, as in the robots meta tag, e.g. "NOINDEX,FOLLOW"
     */
    public function __construct(
        private readonly string $directive
    ) {
    }

    /**
     * The directive as the page's robots meta tag carries it.
     *
     * @return string
     */
    public function getDirective(): string
    {
        return $this->directive;
    }

    /**
     * Whether the directive keeps the page out of search results.
     *
     * A noindex token, or none — which is noindex and nofollow together. Tokens such as
     * noimageindex restrict something else and do not count.
     *
     * @return bool
     */
    public function isNoindex(): bool
    {
        foreach (explode(',', $this->directive) as $token) {
            $token = strtolower(trim($token));
            if ($token === 'noindex' || $token === 'none') {
                return true;
            }
        }

        return false;
    }
}
