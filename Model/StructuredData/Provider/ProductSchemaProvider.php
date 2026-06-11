<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\StructuredData\Provider;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\StructuredDataProviderInterface;
use MageOS\Seo\Model\Category\ConfigRepository as CategoryConfigRepository;
use MageOS\Seo\Model\Category\ProductOverrideRepository;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\SchemaBuilderPool;
use MageOS\Seo\Model\Product\SchemaRegistry;

class ProductSchemaProvider implements StructuredDataProviderInterface
{
    // Request param set by MageOS_ProductVariantUrl router
    private const VARIANT_DATA_PARAM = 'variant_slug_data';

    /**
     * @param Registry $registry
     * @param SchemaBuilderPool $builderPool
     * @param SchemaRegistry $schemaRegistry
     * @param CategoryConfigRepository $categoryConfigRepository
     * @param ProductOverrideRepository $productOverrideRepository
     * @param StoreManagerInterface $storeManager
     * @param Config $seoConfig
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly Registry                  $registry,
        private readonly SchemaBuilderPool         $builderPool,
        private readonly SchemaRegistry            $schemaRegistry,
        private readonly CategoryConfigRepository  $categoryConfigRepository,
        private readonly ProductOverrideRepository $productOverrideRepository,
        private readonly StoreManagerInterface     $storeManager,
        private readonly Config                    $seoConfig,
        private readonly RequestInterface          $request
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
        $product = $this->registry->registry('current_product');
        if (!$product) {
            return [];
        }

        $storeId    = (int) $this->storeManager->getStore()->getId();
        $productId  = (int) $product->getId();

        // Resolve category config (template + fields) — use first assigned category
        $categoryIds  = $product->getCategoryIds();
        $categoryId   = !empty($categoryIds) ? (int) reset($categoryIds) : 0;
        $categoryRow  = $this->categoryConfigRepository->getForCategory($categoryId, [], $storeId);
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

        // Resolve variant data if a variant URL is active
        $variantData = [];
        $variantParam = $this->request->getParam(self::VARIANT_DATA_PARAM);
        if (!empty($variantParam) && \is_array($variantParam)) {
            $variantData = $variantParam;
        }

        // Build schema using the appropriate builder
        $schema = $this->builderPool->build(
            $templateCode,
            $product,
            $enabledFields,
            $overrides,
            $variantData
        );

        if (empty($schema)) {
            return [];
        }

        // Store in the registry. The compositor reads the final registry state
        // after all providers (including the variant enricher) have run.
        $this->schemaRegistry->set($schema);

        return [];
    }
}
