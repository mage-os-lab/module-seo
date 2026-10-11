<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Variant;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable\Product\CollectionFactory;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Loads the variants of several configurables in one of core's own child collections.
 *
 * Core's `Configurable\Product\Collection` takes several configurables (`setProductFilter()` for
 * each), and its load carries each variant's catalog-rule price (CatalogRuleConfigurable's plugin).
 * With the price attributes and the tier prices core's own `getUsedProducts()` loads, a variant's
 * final price then costs no query. What a product page needs and a price doesn't — the media
 * gallery, the configurable attributes — is not loaded.
 *
 * The configurables must share no variant: a collection refuses a second item with the same ID.
 * ChildProducts splits them.
 */
class ChildLoader
{
    /**
     * The attributes a variant's final price reads, beyond the catalogue's listing attributes, and
     * its base image, which VariantImage lends a configurable without one.
     */
    private const PRICE_ATTRIBUTES = [
        'price', 'special_price', 'special_from_date', 'special_to_date', 'tax_class_id', 'status', 'name',
        'image',
    ];

    /**
     * @param CollectionFactory $collectionFactory
     * @param CatalogConfig $catalogConfig
     * @param MetadataPool $metadataPool
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly CatalogConfig     $catalogConfig,
        private readonly MetadataPool      $metadataPool
    ) {
    }

    /**
     * Every variant of the configurables, before core's filter decides which are sold.
     *
     * @param array<int,Product> $parents Configurables by entity ID, sharing no variant
     * @param int $storeId
     * @return array<int,ProductInterface[]> configurable entity ID => its variants
     */
    public function load(array $parents, int $storeId): array
    {
        $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();

        $collection = $this->collectionFactory->create();
        $byLinkId   = [];
        foreach ($parents as $parentId => $parent) {
            $collection->setProductFilter($parent);
            $byLinkId[(int) $parent->getData($linkField)] = $parentId;
        }
        $collection->addAttributeToSelect(array_values(array_unique(array_merge(
            $this->catalogConfig->getProductAttributes(),
            self::PRICE_ATTRIBUTES
        ))))
            ->addFilterByRequiredOptions()
            ->setStoreId($storeId)
            ->addTierPriceData();

        $children = [];
        foreach ($collection as $child) {
            $parentId = $byLinkId[(int) $child->getData('parent_id')] ?? null;
            if ($parentId !== null) {
                $children[$parentId][] = $child;
            }
        }

        return $children;
    }
}
