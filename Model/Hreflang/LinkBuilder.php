<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Hreflang;

/**
 * Builds hreflang link entries for an entity from its URL rewrites and the store locale map.
 *
 * Shared by the per-page-type resolvers so the rewrite-to-link mapping lives in one place.
 */
class LinkBuilder
{
    /**
     * @param UrlRewriteFetcher $urlRewriteFetcher
     * @param StoreLocaleMap $storeLocaleMap
     */
    public function __construct(
        private readonly UrlRewriteFetcher $urlRewriteFetcher,
        private readonly StoreLocaleMap    $storeLocaleMap
    ) {
    }

    /**
     * Build alternate-link entries for one entity across the eligible store views.
     *
     * @param string $entityType
     * @param int $entityId
     * @return array<int, array{hreflang: string, url: string, store_id: int}>
     */
    public function build(string $entityType, int $entityId): array
    {
        return $this->buildFromPaths($this->urlRewriteFetcher->fetchForEntity($entityType, $entityId));
    }

    /**
     * Build alternate-link entries from an already-fetched store_id => request_path map.
     *
     * @param array<int,string> $paths
     * @return array<int, array{hreflang: string, url: string, store_id: int}>
     */
    public function buildFromPaths(array $paths): array
    {
        $map = $this->storeLocaleMap->getMap();

        $links = [];
        foreach ($paths as $storeId => $path) {
            if (!isset($map[$storeId])) {
                continue;
            }
            $url = $map[$storeId]['base_url'] . '/' . ltrim($path, '/');
            foreach ($this->linksFor((int) $storeId, $map[$storeId]['codes'], $url) as $link) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /**
     * Build the home-page alternates: every eligible store view's base URL.
     *
     * The home page has no URL rewrite to look up, so it is built from the store map directly. The
     * head resolver and the sitemap generator used to each carry their own copy of this.
     *
     * @return array<int, array{hreflang: string, url: string, store_id: int}>
     */
    public function buildHome(): array
    {
        $links = [];
        foreach ($this->storeLocaleMap->getMap() as $storeId => $data) {
            foreach ($this->linksFor((int) $storeId, $data['codes'], $data['base_url'] . '/') as $link) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /**
     * One link per code a store view claims, all pointing at the same URL.
     *
     * A store serving es-MX, es-AR and es-CL is one page in three regions; Google expects one
     * annotation for each, sharing the URL.
     *
     * @param int $storeId
     * @param string[] $codes
     * @param string $url
     * @return array<int, array{hreflang: string, url: string, store_id: int}>
     */
    private function linksFor(int $storeId, array $codes, string $url): array
    {
        return array_map(
            static fn (string $code): array => ['hreflang' => $code, 'url' => $url, 'store_id' => $storeId],
            $codes
        );
    }
}
