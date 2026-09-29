<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap\Robots;

use MageOS\Seo\Api\Sitemap\ItemEnricherInterface;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;
use MageOS\Seo\Model\Category\PathResolver;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\RobotsMeta\Provider\CategoryRobotsProvider;
use MageOS\Seo\Model\RobotsMeta\Provider\CmsPageRobotsProvider;
use MageOS\Seo\Model\RobotsMeta\Provider\ProductRobotsProvider;

/**
 * Files each sitemap item's robots directive in its data bag, for IndexableFilter to read.
 *
 * The directive is the one the page itself gets, asked of the same providers the page head asks:
 * a product's override, else the Product Pages default; a category's own or inherited override,
 * else the Category Pages default; a CMS page's override, else the CMS Pages default; the home page
 * as its CMS page. Where this module has nothing to say — and for items of other modules' types —
 * core's Design → Search Engine Robots applies, as it does on the page, so a staging store set to
 * NOINDEX gets an empty sitemap.
 *
 * Products and CMS pages are read a chunk at a time; categories one at a time, each with its path so
 * that settings are inherited from its ancestors.
 *
 * Not seen here: a directive another module changes while the page renders. Only what is stored
 * can be read for a whole catalogue.
 */
class Enricher implements ItemEnricherInterface
{
    /**
     * The data bag key the directive is filed under.
     */
    public const BAG_KEY = 'robots';

    /**
     * @param Config $seoConfig
     * @param ProductRobotsProvider $productRobots
     * @param CategoryRobotsProvider $categoryRobots
     * @param CmsPageRobotsProvider $cmsPageRobots
     * @param PathResolver $categoryPathResolver
     * @param CmsPageResolver $cmsPageResolver
     */
    public function __construct(
        private readonly Config                 $seoConfig,
        private readonly ProductRobotsProvider  $productRobots,
        private readonly CategoryRobotsProvider $categoryRobots,
        private readonly CmsPageRobotsProvider  $cmsPageRobots,
        private readonly PathResolver           $categoryPathResolver,
        private readonly CmsPageResolver        $cmsPageResolver
    ) {
    }

    /**
     * @inheritdoc
     */
    public function enrich(array $items, int $storeId): void
    {
        if (!$this->seoConfig->isSitemapNoindexExcluded($storeId)) {
            return;
        }

        $byEntity    = $this->directivesByEntity($items, $storeId);
        $coreDefault = $this->seoConfig->getRobotsCoreDefault($storeId);

        foreach ($items as $item) {
            $type      = $item->getEntityType();
            $directive = match (true) {
                $type === SitemapItemInterface::ENTITY_STORE => $this->homeDirective($storeId),
                $type !== null                               => $byEntity[$type][(int) $item->getEntityId()] ?? null,
                default                                      => null,
            };

            $item->updateDataBag(self::BAG_KEY, new Directive($directive ?? $coreDefault));
        }
    }

    /**
     * This module's directives for the chunk's products, categories and CMS pages.
     *
     * @param SitemapItemInterface[] $items
     * @param int $storeId
     * @return array<string,array<int,string|null>> type => entity ID => directive
     */
    private function directivesByEntity(array $items, int $storeId): array
    {
        $ids = [];
        foreach ($items as $item) {
            $type = $item->getEntityType();
            $id   = $item->getEntityId();
            if ($type !== null && $id !== null) {
                $ids[$type][] = $id;
            }
        }

        $directives = [];
        if (isset($ids[SitemapItemInterface::ENTITY_PRODUCT])) {
            $directives[SitemapItemInterface::ENTITY_PRODUCT] = $this->productRobots->forProducts(
                $ids[SitemapItemInterface::ENTITY_PRODUCT],
                $storeId
            );
        }
        if (isset($ids[SitemapItemInterface::ENTITY_CMS_PAGE])) {
            $directives[SitemapItemInterface::ENTITY_CMS_PAGE] = $this->cmsPageRobots->forPages(
                $ids[SitemapItemInterface::ENTITY_CMS_PAGE],
                $storeId
            );
        }
        if (isset($ids[SitemapItemInterface::ENTITY_CATEGORY])) {
            $directives[SitemapItemInterface::ENTITY_CATEGORY] = $this->categoryDirectives(
                $ids[SitemapItemInterface::ENTITY_CATEGORY],
                $storeId
            );
        }

        return $directives;
    }

    /**
     * Each category's directive, inherited through its path.
     *
     * @param int[] $categoryIds
     * @param int $storeId
     * @return array<int,string|null>
     */
    private function categoryDirectives(array $categoryIds, int $storeId): array
    {
        $directives = [];
        foreach ($this->categoryPathResolver->forCategoryIds($categoryIds) as $categoryId => $path) {
            $directives[$categoryId] = $this->categoryRobots->forCategory($categoryId, $path, $storeId);
        }

        return $directives;
    }

    /**
     * The home page's directive: its CMS page's.
     *
     * @param int $storeId
     * @return string|null
     */
    private function homeDirective(int $storeId): ?string
    {
        $page = $this->cmsPageResolver->resolveHome($storeId);
        if ($page === null) {
            return null;
        }

        $pageId = (int) $page->getId();

        return $this->cmsPageRobots->forPages([$pageId], $storeId)[$pageId] ?? null;
    }
}
