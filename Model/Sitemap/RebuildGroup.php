<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap;

/**
 * The queue group that rebuilds one type of every sitemap: `sitemap-{type}`.
 *
 * Sitemap rebuilds share the feeds' queue (Feed\RegenerationRequester), whose message is a group
 * name, so a sitemap type travels as `sitemap-pages`, `sitemap-products`, or `sitemap-{type}` for
 * another module's own type — and `sitemap-*` for every type, after a change that can alter every
 * URL. One place builds and reads the name — whoever requests a rebuild and the consumer that
 * carries it out.
 */
class RebuildGroup
{
    /**
     * What a sitemap group starts with.
     */
    public const PREFIX = 'sitemap-';

    /**
     * The type that stands for every type. It cannot be a provider's: types are lower-case
     * letters, digits and hyphens (see Generator).
     */
    public const ALL_TYPES = '*';

    /**
     * The group that rebuilds the type.
     *
     * @param string $type
     * @return string
     */
    public function forType(string $type): string
    {
        return self::PREFIX . $type;
    }

    /**
     * The type a group rebuilds, or null when it is not a sitemap group.
     *
     * @param string $group
     * @return string|null
     */
    public function typeOf(string $group): ?string
    {
        return str_starts_with($group, self::PREFIX) ? substr($group, \strlen(self::PREFIX)) : null;
    }
}
