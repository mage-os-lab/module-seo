<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\Variant;

use Magento\Catalog\Model\Config as CatalogConfig;
use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable\Product\Collection;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable\Product\CollectionFactory;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use MageOS\Seo\Model\Product\Variant\ChildLoader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The children of several configurables in one of core's own child collections (PLAN-G, "G2.3
 * follow-up"), with what their prices need and nothing a product page's gallery or swatches need.
 *
 * Core's collection carries the catalog-rule price (CatalogRuleConfigurable's plugin on its load)
 * and here the tier prices, so the children's final prices cost no query of their own.
 *
 * @group magento-generated
 */
#[Group('magento-generated')]
class ChildLoaderTest extends TestCase
{
    /**
     * @return void
     */
    public function testThePagesParentsAreReadInOneCollectionWithTheirPriceDataOnly(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects($this->exactly(2))->method('setProductFilter')->willReturnSelf();
        $collection->expects($this->once())->method('addAttributeToSelect')
            ->with($this->callback(static fn (array $codes): bool => array_diff(
                ['price', 'special_price', 'special_from_date', 'special_to_date', 'tax_class_id', 'status', 'name'],
                $codes
            ) === []))
            ->willReturnSelf();
        $collection->expects($this->once())->method('addFilterByRequiredOptions')->willReturnSelf();
        $collection->expects($this->once())->method('setStoreId')->with(3)->willReturnSelf();
        $collection->expects($this->once())->method('addTierPriceData')->willReturnSelf();
        $collection->expects($this->never())->method('addMediaGalleryData');
        $child11 = $this->child(10);
        $child21 = $this->child(20);
        $child12 = $this->child(10);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$child11, $child21, $child12]));

        $children = $this->loader($collection, 'entity_id')
            ->load([10 => $this->parent(['entity_id' => 10]), 20 => $this->parent(['entity_id' => 20])], 3);

        $this->assertSame([10 => [$child11, $child12], 20 => [$child21]], $children);
    }

    /**
     * The link table holds the parent's link field, which is not its entity ID where rows are
     * versioned (row_id).
     *
     * @return void
     */
    public function testChildrenAreMatchedToTheirParentsByTheLinkField(): void
    {
        $collection = $this->createStub(Collection::class);
        $fluent = [
            'setProductFilter', 'addAttributeToSelect', 'addFilterByRequiredOptions', 'setStoreId', 'addTierPriceData',
        ];
        foreach ($fluent as $method) {
            $collection->method($method)->willReturnSelf();
        }
        $child = $this->child(110);
        $collection->method('getIterator')->willReturn(new \ArrayIterator([$child]));

        $children = $this->loader($collection, 'row_id')
            ->load([10 => $this->parent(['entity_id' => 10, 'row_id' => 110])], 1);

        $this->assertSame([10 => [$child]], $children);
    }

    /**
     * The loader over a collection, with the given product link field.
     *
     * @param Collection $collection
     * @param string $linkField
     * @return ChildLoader
     */
    private function loader(Collection $collection, string $linkField): ChildLoader
    {
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $catalogConfig = $this->createStub(CatalogConfig::class);
        $catalogConfig->method('getProductAttributes')->willReturn(['name', 'small_image']);
        $metadata = $this->createStub(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn($linkField);
        $metadataPool = $this->createStub(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturn($metadata);

        return new ChildLoader($factory, $catalogConfig, $metadataPool);
    }

    /**
     * A configurable with the given data.
     *
     * @param array<string, int> $data
     * @return Product
     */
    private function parent(array $data): Product
    {
        $parent = $this->createStub(Product::class);
        $parent->method('getData')->willReturnCallback(static fn (string $key) => $data[$key] ?? null);
        $parent->method('getId')->willReturn($data['entity_id']);

        return $parent;
    }

    /**
     * A child row of the collection, linked to the parent whose link field holds the given value.
     *
     * @param int $parentLinkId
     * @return Product
     */
    private function child(int $parentLinkId): Product
    {
        $child = $this->createStub(Product::class);
        $child->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'parent_id' ? (string) $parentLinkId : null
        );

        return $child;
    }
}
