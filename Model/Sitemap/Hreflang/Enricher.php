<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap\Hreflang;

use MageOS\Seo\Api\Sitemap\ItemEnricherInterface;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;
use MageOS\Seo\Model\Cms\ConfigRepository as CmsConfigRepository;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Hreflang\AlternateBuilder;
use MageOS\Seo\Model\Hreflang\LinkBuilder;
use MageOS\Seo\Model\Hreflang\UrlRewriteFetcher;
use MageOS\Seo\Model\Store\CanonicalBaseUrl;

/**
 * Files each sitemap item's hreflang alternates in its data bag, for Renderer to write.
 *
 * The alternates are the ones the page's own head declares — built by the same LinkBuilder and
 * AlternateBuilder, so the same codes, language-only tags, x-default and event observers apply.
 * What differs is how they are fetched: for a whole chunk of items at once, one query per entity
 * type, rather than one per page. CMS pages in a translation group get their group's alternates.
 *
 * An item is only given alternates when its own URL is among them. Google requires every URL in a
 * set to list itself; a URL the alternates do not include — a CMS page that is not its group's
 * page for this store view, say — is left without any rather than with a set that is invalid.
 */
class Enricher implements ItemEnricherInterface
{
    /**
     * The data bag key the alternates are filed under.
     */
    public const BAG_KEY = 'hreflang';

    /**
     * Entity types whose alternates are their own URL rewrites in the other store views.
     */
    private const REWRITE_TYPES = [
        SitemapItemInterface::ENTITY_PRODUCT,
        SitemapItemInterface::ENTITY_CATEGORY,
    ];

    /**
     * @param Config $seoConfig
     * @param UrlRewriteFetcher $urlRewriteFetcher
     * @param CmsConfigRepository $cmsConfigRepository
     * @param LinkBuilder $linkBuilder
     * @param AlternateBuilder $alternateBuilder
     * @param CanonicalBaseUrl $canonicalBaseUrl
     */
    public function __construct(
        private readonly Config              $seoConfig,
        private readonly UrlRewriteFetcher   $urlRewriteFetcher,
        private readonly CmsConfigRepository $cmsConfigRepository,
        private readonly LinkBuilder         $linkBuilder,
        private readonly AlternateBuilder    $alternateBuilder,
        private readonly CanonicalBaseUrl    $canonicalBaseUrl
    ) {
    }

    /**
     * @inheritdoc
     */
    public function enrich(array $items, int $storeId): void
    {
        if (!$this->seoConfig->isHreflangSitemapEnabled() || !$this->seoConfig->isHreflangEnabled($storeId)) {
            return;
        }

        $regionLinks = $this->regionLinks($items);
        $baseUrl     = $this->canonicalBaseUrl->forStore($storeId);

        foreach ($items as $item) {
            $links = $regionLinks[spl_object_id($item)] ?? [];
            $url   = $baseUrl . '/' . ltrim((string) $item->getUrl(), '/');
            if (!$this->listsItself($links, $storeId, $url)) {
                continue;
            }

            $alternates = $this->alternateBuilder->build($links);
            if ($alternates !== []) {
                $item->updateDataBag(self::BAG_KEY, new Alternates($alternates));
            }
        }
    }

    /**
     * The region links of every item that has any, keyed by the item's object ID.
     *
     * @param SitemapItemInterface[] $items
     * @return array<int,array<int,array{hreflang:string,url:string,store_id:int}>>
     */
    private function regionLinks(array $items): array
    {
        $paths = $this->pathsByEntity($items);
        $home  = null;
        $links = [];

        foreach ($items as $item) {
            $type = $item->getEntityType();
            $id   = $item->getEntityId();

            if ($type === SitemapItemInterface::ENTITY_STORE) {
                $links[spl_object_id($item)] = $home ??= $this->linkBuilder->buildHome();
            } elseif ($type !== null && $id !== null && isset($paths[$type][$id])) {
                $links[spl_object_id($item)] = $this->linkBuilder->buildFromPaths($paths[$type][$id]);
            }
        }

        return $links;
    }

    /**
     * Each item's request paths in every store view, fetched a type at a time.
     *
     * @param SitemapItemInterface[] $items
     * @return array<string,array<int,array<int,string>>> type => entity ID => (store ID => path)
     */
    private function pathsByEntity(array $items): array
    {
        $ids = [];
        foreach ($items as $item) {
            $type = $item->getEntityType();
            $id   = $item->getEntityId();
            if ($type !== null && $id !== null) {
                $ids[$type][] = $id;
            }
        }

        $paths = [];
        foreach (self::REWRITE_TYPES as $type) {
            if (isset($ids[$type])) {
                $paths[$type] = $this->urlRewriteFetcher->fetchForEntities($type, $ids[$type]);
            }
        }

        if (isset($ids[SitemapItemInterface::ENTITY_CMS_PAGE])) {
            $paths[SitemapItemInterface::ENTITY_CMS_PAGE] = $this->cmsPagePaths(
                $ids[SitemapItemInterface::ENTITY_CMS_PAGE]
            );
        }

        return $paths;
    }

    /**
     * CMS pages' paths: their translation group's where they have one, else their own.
     *
     * @param int[] $pageIds
     * @return array<int,array<int,string>> page ID => (store ID => path)
     */
    private function cmsPagePaths(array $pageIds): array
    {
        $groups     = $this->cmsConfigRepository->getHreflangGroups($pageIds);
        $groupPaths = $groups === [] ? [] : $this->urlRewriteFetcher->fetchForCmsGroups(
            array_values(array_unique($groups))
        );
        $ownPaths   = $this->urlRewriteFetcher->fetchForEntities(
            SitemapItemInterface::ENTITY_CMS_PAGE,
            array_values(array_diff($pageIds, array_keys($groups)))
        );

        $paths = [];
        foreach ($pageIds as $pageId) {
            $paths[$pageId] = isset($groups[$pageId])
                ? $groupPaths[$groups[$pageId]] ?? []
                : $ownPaths[$pageId] ?? [];
        }

        return $paths;
    }

    /**
     * Whether the links include the item's own URL for its store view.
     *
     * @param array<int,array{hreflang:string,url:string,store_id:int}> $links
     * @param int $storeId
     * @param string $url
     * @return bool
     */
    private function listsItself(array $links, int $storeId, string $url): bool
    {
        foreach ($links as $link) {
            if ($link['store_id'] === $storeId && $link['url'] === $url) {
                return true;
            }
        }

        return false;
    }
}
