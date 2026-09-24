<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Sitemap;

/**
 * Asks for one type of page to be rewritten in the sitemaps this module keeps current.
 *
 * For a module with a sitemap provider of its own: this module sees changes to products,
 * categories, CMS pages and its own settings, not to what another module's provider lists. Call
 * `request()` with the provider's `getType()` when an item it lists is added or removed or its URL
 * changes — not on every save, since a rebuild rewrites every file of the type.
 *
 * The request is queued on the `mageos.seo.feed.regenerate` topic and returns at once; requests for
 * the same type collapse into one until the `mageosSeoFeedRegenerate` consumer has run. Nothing is
 * queued when no sitemap would be rebuilt: one generated before, on an active store view with the
 * MageOS SEO generator and Rebuild on Change on.
 *
 * @api
 */
interface RebuildRequesterInterface
{
    /**
     * Every type: for a change that can alter every URL a sitemap lists.
     */
    public const ALL_TYPES = '*';

    /**
     * Queue a rebuild of the type in every sitemap a change rebuilds.
     *
     * @param string $type A type a registered sitemap provider's getType() returns, or ALL_TYPES
     * @throws \InvalidArgumentException When no registered provider has the type
     * @return void
     */
    public function request(string $type): void;
}
