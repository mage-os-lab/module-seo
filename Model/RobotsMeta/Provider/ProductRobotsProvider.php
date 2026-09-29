<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\RobotsMeta\Provider;

use MageOS\Seo\Api\RobotsMetaProviderInterface;
use MageOS\Seo\Model\Catalog\CurrentEntity;
use MageOS\Seo\Model\Category\ProductOverrideRepository;
use MageOS\Seo\Model\Config;

/**
 * Robots meta for product pages: per-product override, falling back to the configured default.
 *
 * The chain is public (forProducts()) so the sitemap asks the same question the page does, for a
 * chunk of products at once, and the two cannot come to different answers.
 */
class ProductRobotsProvider implements RobotsMetaProviderInterface
{
    /**
     * @param CurrentEntity $currentEntity
     * @param ProductOverrideRepository $productOverrideRepository
     * @param Config $seoConfig
     */
    public function __construct(
        private readonly CurrentEntity $currentEntity,
        private readonly ProductOverrideRepository $productOverrideRepository,
        private readonly Config                    $seoConfig
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHandles(): array
    {
        return ['catalog_product_view'];
    }

    /**
     * @inheritdoc
     */
    public function getRobots(int $storeId): ?string
    {
        $product = $this->currentEntity->getProduct();
        if (!$product) {
            return null;
        }

        $override = $this->productOverrideRepository->getForProduct((int) $product->getId(), $storeId);

        return $this->directive($override['robots_meta'] ?? null, $storeId);
    }

    /**
     * This module's directive for each product's page, in one read.
     *
     * @param int[] $productIds
     * @param int $storeId
     * @return array<int,string|null> product ID => directive, null where this module has none
     */
    public function forProducts(array $productIds, int $storeId): array
    {
        return array_map(
            fn (array $override): ?string => $this->directive($override['robots_meta'] ?? null, $storeId),
            $this->productOverrideRepository->getForProducts($productIds, $storeId)
        );
    }

    /**
     * The product's own directive, else the Product Pages default; null when neither says anything.
     *
     * @param mixed $override
     * @param int $storeId
     * @return string|null
     */
    private function directive(mixed $override, int $storeId): ?string
    {
        $robotsMeta = empty($override) ? $this->seoConfig->getRobotsProductDefault($storeId) : $override;

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
