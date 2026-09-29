<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\RobotsMeta\Provider;

use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use MageOS\Seo\Api\RobotsMetaProviderInterface;
use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;
use MageOS\Seo\Model\Category\PathResolver as CategoryPathResolver;
use MageOS\Seo\Model\Config;

/**
 * Robots meta for category pages: per-category override, falling back to the configured default.
 *
 * The chain is public (forCategory()) so the sitemap asks the same question the page does.
 */
class CategoryRobotsProvider implements RobotsMetaProviderInterface
{
    /**
     * @param LayerResolver $layerResolver
     * @param CategoryConfigRepository $categoryConfigRepository
     * @param Config $seoConfig
     * @param CategoryPathResolver $categoryPathResolver
     */
    public function __construct(
        private readonly LayerResolver            $layerResolver,
        private readonly CategoryConfigRepository $categoryConfigRepository,
        private readonly Config                   $seoConfig,
        private readonly CategoryPathResolver     $categoryPathResolver
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHandles(): array
    {
        return ['catalog_category_view'];
    }

    /**
     * @inheritdoc
     */
    public function getRobots(int $storeId): ?string
    {
        try {
            $category = $this->layerResolver->get()->getCurrentCategory();
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- no layer, no opinion
            return null;
        }

        if (!$category) {
            return null;
        }

        return $this->forCategory(
            (int) $category->getId(),
            $this->categoryPathResolver->forCategory($category),
            $storeId
        );
    }

    /**
     * This module's directive for a category's page; null when it has none.
     *
     * The category's own or inherited override, else the Category Pages default. The path is what
     * lets a category inherit its nearest configured ancestor's settings; without it only the
     * category's own row is read and the admin's inherited value, which it shows for exactly this
     * category, never reaches the storefront.
     *
     * @param int $categoryId
     * @param string[] $categoryPath Ancestor IDs from root to leaf (PathResolver)
     * @param int $storeId
     * @return string|null
     */
    public function forCategory(int $categoryId, array $categoryPath, int $storeId): ?string
    {
        $config     = $this->categoryConfigRepository->getForCategory($categoryId, $categoryPath, $storeId);
        $robotsMeta = $config['robots_meta'] ?? null;

        if (empty($robotsMeta)) {
            $robotsMeta = $this->seoConfig->getRobotsCategoryDefault($storeId);
        }

        return empty($robotsMeta) ? null : (string) $robotsMeta;
    }

    /**
     * @inheritdoc
     */
    public function getSortOrder(): int
    {
        return 100;
    }
}
