<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData\Provider;

use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\StructuredDataProviderInterface;
use MageOS\Seo\Model\Catalog\CurrentEntity;
use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;
use MageOS\Seo\Model\Category\PathResolver as CategoryPathResolver;
use MageOS\Seo\Model\Product\OverrideRepository;
use MageOS\Seo\Model\Product\SchemaBuilderPool;
use MageOS\Seo\Model\Product\SchemaRegistry;
use MageOS\Seo\Model\Product\SchemaTemplateResolver;
use MageOS\Seo\Model\Product\Variant\ProductGroupBuilder;

class ProductSchemaProvider implements StructuredDataProviderInterface
{
    /**
     * @param CurrentEntity $currentEntity
     * @param SchemaBuilderPool $builderPool
     * @param SchemaRegistry $schemaRegistry
     * @param CategoryConfigRepository $categoryConfigRepository
     * @param OverrideRepository $productOverrideRepository
     * @param StoreManagerInterface $storeManager
     * @param SchemaTemplateResolver $templateResolver
     * @param CategoryPathResolver $categoryPathResolver
     * @param ProductGroupBuilder $productGroupBuilder
     */
    public function __construct(
        private readonly CurrentEntity             $currentEntity,
        private readonly SchemaBuilderPool         $builderPool,
        private readonly SchemaRegistry            $schemaRegistry,
        private readonly CategoryConfigRepository  $categoryConfigRepository,
        private readonly OverrideRepository $productOverrideRepository,
        private readonly StoreManagerInterface     $storeManager,
        private readonly SchemaTemplateResolver    $templateResolver,
        private readonly CategoryPathResolver      $categoryPathResolver,
        private readonly ProductGroupBuilder       $productGroupBuilder
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

        $templateCode = $this->templateResolver->resolve((string) ($categoryRow['schema_template'] ?? ''), $storeId);

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

        // After the template, so what varies can come off the group whatever the template set:
        // a configurable within has_variant_max becomes a ProductGroup of its variants.
        $schema = $this->productGroupBuilder->build($schema, $product, $enabledFields);

        // Store in the registry. The compositor reads the final registry state after every
        // provider has run, so another module's provider can still adjust the node.
        $this->schemaRegistry->set($schema);

        return [];
    }
}
