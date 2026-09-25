<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData\Provider;

use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\StructuredDataProviderInterface;
use MageOS\Seo\Model\Catalog\CurrentEntity;
use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;
use MageOS\Seo\Model\Category\PathResolver as CategoryPathResolver;
use MageOS\Seo\Model\Category\ProductOverrideRepository;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\SchemaBuilderPool;
use MageOS\Seo\Model\Product\SchemaRegistry;

class ProductSchemaProvider implements StructuredDataProviderInterface
{
    /**
     * @param CurrentEntity $currentEntity
     * @param SchemaBuilderPool $builderPool
     * @param SchemaRegistry $schemaRegistry
     * @param CategoryConfigRepository $categoryConfigRepository
     * @param ProductOverrideRepository $productOverrideRepository
     * @param StoreManagerInterface $storeManager
     * @param Config $seoConfig
     * @param CategoryPathResolver $categoryPathResolver
     */
    public function __construct(
        private readonly CurrentEntity             $currentEntity,
        private readonly SchemaBuilderPool         $builderPool,
        private readonly SchemaRegistry            $schemaRegistry,
        private readonly CategoryConfigRepository  $categoryConfigRepository,
        private readonly ProductOverrideRepository $productOverrideRepository,
        private readonly StoreManagerInterface     $storeManager,
        private readonly Config                    $seoConfig,
        private readonly CategoryPathResolver      $categoryPathResolver
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
    public function getSchemas(): array
    {
        $product = $this->currentEntity->getProduct();
        if (!$product) {
            return [];
        }
        /** @var \Magento\Catalog\Model\Product $product */

        $storeId    = (int) $this->storeManager->getStore()->getId();
        $productId  = (int) $product->getId();

        // Resolve category config (template + fields) — use first assigned category
        $categoryIds  = $product->getCategoryIds();
        $categoryId   = !empty($categoryIds) ? (int) reset($categoryIds) : 0;
        $categoryRow  = $this->categoryConfigRepository->getForCategory(
            $categoryId,
            $this->categoryPathResolver->forCategoryId($categoryId, $storeId),
            $storeId
        );
        $categoryRow  = $this->categoryConfigRepository->decode($categoryRow);

        $templateCode  = $categoryRow['schema_template'] ?? '';
        if ($templateCode === '') {
            $templateCode = $this->seoConfig->getDefaultProductTemplate($storeId);
        }
        if ($templateCode === '') {
            $templateCode = 'GenericProduct';
        }

        $enabledFields   = $categoryRow['enabled_fields'] ?? [];
        $categoryOverrides = $categoryRow['override_fields'] ?? [];

        // Merge product-level overrides on top of category overrides
        $productOverrideRow = $this->productOverrideRepository->getForProduct($productId, $storeId);
        $overrides = array_merge($categoryOverrides, $productOverrideRow['override_fields'] ?? []);

        // Build schema using the appropriate builder
        $schema = $this->builderPool->build(
            $templateCode,
            $product,
            $enabledFields,
            $overrides
        );

        if (empty($schema)) {
            return [];
        }

        // Store in the registry. The compositor reads the final registry state after every
        // provider has run, so another module's provider can still adjust the node.
        $this->schemaRegistry->set($schema);

        return [];
    }
}
