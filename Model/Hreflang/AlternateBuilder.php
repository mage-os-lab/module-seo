<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Hreflang;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;

/**
 * Turns a set of region links into the full hreflang alternate set.
 *
 * Shared by ResolverPool (head tags) and SitemapGenerator (sitemap) so the language-only,
 * x-default and single-store rules live in one place. Returns [] when fewer than two distinct
 * codes are present (a page with one annotation has nothing to be an alternate of).
 *
 * Because both outputs pass through here, it is also where third parties get a say: see
 * EVENT_AFTER.
 */
class AlternateBuilder
{
    /**
     * Dispatched with every alternate set, for the head and for the sitemap alike.
     *
     * The observer receives `transport`, a DataObject holding `alternates` — the list about to be
     * rendered, each entry `{hreflang, url}` — and, for context, `region_links`, the per-store
     * links it was built from, each with its `store_id`. Replace `alternates` on the transport to
     * add, remove or rewrite entries. A value that is not an array is ignored, so a broken observer
     * cannot take the page down with it.
     *
     * In the sitemap the set is built once and the file copied to every store view sharing it, so
     * an observer's answer must depend only on what the transport holds — not on which store view
     * happens to be current.
     */
    public const EVENT_AFTER = 'mageos_seo_hreflang_alternates_after';

    /**
     * @param Config $seoConfig
     * @param StoreManagerInterface $storeManager
     * @param EventManagerInterface $eventManager
     */
    public function __construct(
        private readonly Config                $seoConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly EventManagerInterface $eventManager
    ) {
    }

    /**
     * Build the full ordered alternate set (region + language-only + x-default), or [].
     *
     * @param array<int,array{hreflang:string,url:string,store_id:int}> $regionLinks
     * @return array<int, array{hreflang: string, url: string}>
     */
    public function build(array $regionLinks): array
    {
        $alternates = $this->assemble($regionLinks);

        $transport = new DataObject(['alternates' => $alternates, 'region_links' => $regionLinks]);
        $this->eventManager->dispatch(self::EVENT_AFTER, ['transport' => $transport]);

        $observed = $transport->getData('alternates');

        return \is_array($observed) ? $observed : $alternates;
    }

    /**
     * The alternate set this module builds on its own, before any observer.
     *
     * @param array<int,array{hreflang:string,url:string,store_id:int}> $regionLinks
     * @return array<int, array{hreflang: string, url: string}>
     */
    private function assemble(array $regionLinks): array
    {
        if (\count(array_unique(array_column($regionLinks, 'hreflang'))) < 2) {
            return [];
        }

        $output = [];
        foreach ($regionLinks as $link) {
            $output[] = ['hreflang' => $link['hreflang'], 'url' => $link['url']];
        }

        if ($this->seoConfig->isHreflangLanguageOnlyEnabled()) {
            foreach ($this->buildLanguageOnlyTags($regionLinks) as $tag) {
                $output[] = $tag;
            }
        }

        $xDefault = $this->buildXDefault($regionLinks);
        if ($xDefault !== null) {
            $output[] = $xDefault;
        }

        return $output;
    }

    /**
     * Build language-only tags for any base language served by exactly one store view.
     *
     * Counted by store view, not by link: a store serving es-MX and es-AR is still the only Spanish
     * store, so it gets the `es` tag. A language some store already claims bare is left alone.
     *
     * @param array<int,array{hreflang:string,url:string,store_id:int}> $regionLinks
     * @return array<int, array{hreflang: string, url: string}>
     */
    private function buildLanguageOnlyTags(array $regionLinks): array
    {
        $byLanguage = [];
        foreach ($regionLinks as $link) {
            $language = explode('-', $link['hreflang'])[0];
            $byLanguage[$language][] = $link;
        }

        $tags = [];
        foreach ($byLanguage as $language => $links) {
            if (\count(array_unique(array_column($links, 'store_id'))) !== 1) {
                continue;
            }
            if (\in_array($language, array_column($links, 'hreflang'), true)) {
                continue;
            }
            $tags[] = ['hreflang' => $language, 'url' => $links[0]['url']];
        }

        return $tags;
    }

    /**
     * Build the x-default tag from the configured store view, or null if not configured/present.
     *
     * The setting is read for the website of the store view being rendered — the store the visitor
     * is on in the head, the store under emulation while the sitemap is built. A store view serving
     * several codes contributes one link per code, all at the same URL, so its first link is the one
     * x-default points at.
     *
     * @param array<int,array{hreflang:string,url:string,store_id:int}> $regionLinks
     * @return array{hreflang: string, url: string}|null
     */
    private function buildXDefault(array $regionLinks): ?array
    {
        $xDefaultStoreId = $this->seoConfig->getHreflangXDefaultStoreId(
            (int) $this->storeManager->getStore()->getWebsiteId()
        );
        if ($xDefaultStoreId <= 0) {
            return null;
        }

        foreach ($regionLinks as $link) {
            if ($link['store_id'] === $xDefaultStoreId) {
                return ['hreflang' => 'x-default', 'url' => $link['url']];
            }
        }

        return null;
    }
}
