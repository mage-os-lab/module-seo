<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Hreflang;

/**
 * Builds the contents of the hreflang sitemap.
 *
 * Three bulk queries (product, category, cms-page) plus the home pages cover the whole catalogue.
 * Every entity emits one <url> block per store view it exists on, each carrying the full
 * <xhtml:link> alternate set (region links + language-only + x-default) — the layout Google
 * requires for a hreflang sitemap.
 *
 * Blocks are streamed one entity at a time; SitemapFileWriter turns them into files, so a
 * 100k-product catalogue is never held in memory as one document.
 */
class SitemapGenerator
{
    private const ENTITY_TYPES = ['product', 'category', 'cms-page'];

    /**
     * Sitemap protocol caps a file at 50,000 URLs; chunk below the cap for headroom.
     */
    public const MAX_URLS_PER_FILE = 45000;

    public const INDEX_FILE   = 'hreflang-sitemap.xml';
    public const CHUNK_FORMAT = 'hreflang-sitemap-%d.xml';

    /**
     * @param StoreLocaleMap $storeLocaleMap
     * @param UrlRewriteFetcher $urlRewriteFetcher
     * @param LinkBuilder $linkBuilder
     * @param AlternateBuilder $alternateBuilder
     */
    public function __construct(
        private readonly StoreLocaleMap    $storeLocaleMap,
        private readonly UrlRewriteFetcher $urlRewriteFetcher,
        private readonly LinkBuilder       $linkBuilder,
        private readonly AlternateBuilder  $alternateBuilder
    ) {
    }

    /**
     * Stream every <url> block of the sitemap (home pages first, then each entity type).
     *
     * @return \Generator<string>
     */
    public function streamBlocks(): \Generator
    {
        $map      = $this->storeLocaleMap->getMap();
        $storeIds = array_keys($map);

        yield from $this->entityBlocks($this->homeRegionLinks($map));

        foreach (self::ENTITY_TYPES as $entityType) {
            foreach ($this->urlRewriteFetcher->streamAllForType($entityType, $storeIds) as $paths) {
                yield from $this->entityBlocks($this->linkBuilder->buildFromPaths($paths));
            }
        }
    }

    /**
     * Opening lines of a urlset document.
     *
     * @return string
     */
    public function documentHeader(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n"
            . '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
    }

    /**
     * Closing line of a urlset document.
     *
     * @return string
     */
    public function documentFooter(): string
    {
        return '</urlset>' . "\n";
    }

    /**
     * Render the sitemap index listing the chunk files of one store view.
     *
     * @param string $baseUrl Store base URL the chunks are served from
     * @param string[] $chunkFileNames
     * @return string
     */
    public function indexDocument(string $baseUrl, array $chunkFileNames): string
    {
        $entries = [];
        foreach ($chunkFileNames as $fileName) {
            $entries[] = '  <sitemap>' . "\n"
                . '    <loc>' . $this->escape(rtrim($baseUrl, '/') . '/' . $fileName) . '</loc>' . "\n"
                . '  </sitemap>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
            . implode("\n", $entries) . "\n"
            . '</sitemapindex>' . "\n";
    }

    /**
     * Home-page region links built directly from each store's base URL.
     *
     * @param array<int,array{base_url:string,locale:string,language:string}> $map
     * @return array<int, array{hreflang: string, url: string, store_id: int}>
     */
    private function homeRegionLinks(array $map): array
    {
        $links = [];
        foreach ($map as $storeId => $data) {
            $links[] = [
                'hreflang' => $data['locale'],
                'url'      => $data['base_url'] . '/',
                'store_id' => $storeId,
            ];
        }

        return $links;
    }

    /**
     * Build one <url> block per store view for an entity, each with the full alternate set.
     *
     * @param array<int,array{hreflang:string,url:string,store_id:int}> $regionLinks
     * @return string[]
     */
    private function entityBlocks(array $regionLinks): array
    {
        $alternates = $this->alternateBuilder->build($regionLinks);
        if ($alternates === []) {
            return [];
        }

        $blocks = [];
        foreach ($regionLinks as $link) {
            $blocks[] = $this->renderUrlBlock($link['url'], $alternates);
        }

        return $blocks;
    }

    /**
     * Render a single <url> block.
     *
     * @param string $loc
     * @param array<int,array{hreflang:string,url:string}> $alternates
     * @return string
     */
    private function renderUrlBlock(string $loc, array $alternates): string
    {
        $lines = ['  <url>', '    <loc>' . $this->escape($loc) . '</loc>'];
        foreach ($alternates as $alternate) {
            $lines[] = \sprintf(
                '    <xhtml:link rel="alternate" hreflang="%s" href="%s"/>',
                $this->escape($alternate['hreflang']),
                $this->escape($alternate['url'])
            );
        }
        $lines[] = '  </url>';

        return implode("\n", $lines);
    }

    /**
     * Escape a value for safe inclusion in XML.
     *
     * @param string $value
     * @return string
     */
    private function escape(string $value): string
    {
        // Native escaping with ENT_XML1 is required for valid sitemap XML output.
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.DiscouragedWithAlternative
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
