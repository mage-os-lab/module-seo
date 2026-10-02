<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * Resolves alternate-language URLs for the current page across store views.
 *
 * One resolver per page type (product, category, CMS, …). The ResolverPool picks the first matching
 * resolver that returns links, then appends language-only and x-default tags. A bridge module adds a
 * resolver for a custom URL type (vendor profile, blog post) via its own di.xml — MageOS_Seo is not
 * modified.
 *
 * @api
 */
interface HreflangResolverInterface
{
    /**
     * Layout handles this resolver applies to. ['*'] = every page.
     *
     * @return string[]
     */
    public function getHandles(): array;

    /**
     * Return alternate URL entries for the current page across all active store views.
     *
     * Each entry: ['hreflang' => 'en-GB', 'url' => 'https://...', 'store_id' => 1]. Returns [] when
     * not applicable (no current entity, or no URL rewrites). Do NOT include language-only tags or
     * x-default — the pool appends those.
     *
     * @return array<int, array{hreflang: string, url: string, store_id: int}>
     */
    public function getLinks(): array;
}
