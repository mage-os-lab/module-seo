<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\Variant;

use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Pricing\Price\ConfigurableOptionsFilterInterface;
use Magento\ConfigurableProduct\Pricing\Price\ConfigurableOptionsProviderInterface;
use MageOS\Seo\Model\Product\Variant\ChildLoader;
use MageOS\Seo\Model\Product\Variant\ChildProducts;
use MageOS\Seo\Model\ResourceModel\VariantLinks;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A configurable's children: core's provider on a product page, and a page of products' children
 * read at once for a feed (PLAN-G, "G2.3 follow-up").
 *
 * Read one by one, every configurable in a llms.jsonl build paid core's whole child load: 10 queries,
 * the media gallery and the configurable attributes among them. A prefetched page is read in one
 * collection, and core's filter still decides which children are sold.
 */
class ChildProductsTest extends TestCase
{
    /**
     * @return void
     */
    public function testAnyOtherProductTypeHasNoChildren(): void
    {
        $provider = $this->createMock(ConfigurableOptionsProviderInterface::class);
        $provider->expects($this->never())->method('getProducts');

        $this->assertSame([], $this->childProducts($provider)->get($this->product(1, 'simple')));
    }

    /**
     * @return void
     */
    public function testAParentNotPrefetchedAsksCoresProvider(): void
    {
        $child    = $this->product(11, 'simple');
        $provider = $this->createMock(ConfigurableOptionsProviderInterface::class);
        $provider->expects($this->once())->method('getProducts')->willReturn(['x' => $child]);

        $this->assertSame([$child], $this->childProducts($provider)->get($this->product(10)));
    }

    /**
     * Core's provider filters in the frontend area only; a feed is built in the queue or cron area,
     * so the storefront's filter is applied to its answer too.
     *
     * @return void
     */
    public function testTheStorefrontFilterAppliesToTheProvidersAnswer(): void
    {
        $parent   = $this->product(10);
        $sold     = $this->product(11, 'simple');
        $provider = $this->createStub(ConfigurableOptionsProviderInterface::class);
        $provider->method('getProducts')->willReturn([$sold, $this->product(12, 'simple')]);
        $filter = $this->createMock(ConfigurableOptionsFilterInterface::class);
        $filter->expects($this->once())->method('filter')->with($parent, $this->countOf(2))->willReturn([$sold]);

        $this->assertSame([$sold], $this->childProducts($provider, null, null, $filter)->get($parent));
    }

    /**
     * @return void
     */
    public function testAPrefetchedParentIsAnsweredWithoutAskingTheProvider(): void
    {
        $parent   = $this->product(10);
        $children = [$this->product(11, 'simple'), $this->product(12, 'simple')];
        $provider = $this->createMock(ConfigurableOptionsProviderInterface::class);
        $provider->expects($this->never())->method('getProducts');
        $loader = $this->createStub(ChildLoader::class);
        $loader->method('load')->willReturn([10 => $children]);

        $childProducts = $this->childProducts($provider, $this->links([10 => [11, 12]]), $loader);
        $childProducts->prefetch([$parent]);

        $this->assertSame($children, $childProducts->get($parent));
    }

    /**
     * Core's filter decides which children are sold, for a prefetched page as for a product page.
     *
     * @return void
     */
    public function testCoresFilterIsAppliedToEachParentsChildren(): void
    {
        $parent  = $this->product(10);
        $enabled = $this->product(11, 'simple');
        $loader  = $this->createStub(ChildLoader::class);
        $loader->method('load')->willReturn([10 => [$enabled, $this->product(12, 'simple')]]);
        $filter = $this->createMock(ConfigurableOptionsFilterInterface::class);
        $filter->expects($this->once())->method('filter')
            ->with($parent, $this->countOf(2))
            ->willReturn([$enabled]);

        $childProducts = $this->childProducts(null, $this->links([10 => [11, 12]]), $loader, $filter);
        $childProducts->prefetch([$parent]);

        $this->assertSame([$enabled], $childProducts->get($parent));
    }

    /**
     * One page is held at a time, so memory stays flat over a long build.
     *
     * @return void
     */
    public function testALaterPrefetchReplacesThePage(): void
    {
        $first    = $this->product(10);
        $provider = $this->createMock(ConfigurableOptionsProviderInterface::class);
        $provider->expects($this->once())->method('getProducts')->with($first)->willReturn([]);
        $loader = $this->createStub(ChildLoader::class);
        $loader->method('load')->willReturnCallback(
            static fn (array $parents): array => array_fill_keys(array_keys($parents), [])
        );

        $childProducts = $this->childProducts($provider, $this->links([10 => [11], 20 => [21]]), $loader);
        $childProducts->prefetch([$first]);
        $childProducts->prefetch([$this->product(20)]);

        $childProducts->get($first);
    }

    /**
     * A collection refuses a second item with the same ID, so configurables sharing a variant are
     * loaded apart.
     *
     * @return void
     */
    public function testParentsSharingAVariantAreLoadedSeparately(): void
    {
        $loads  = [];
        $loader = $this->createStub(ChildLoader::class);
        $loader->method('load')->willReturnCallback(
            static function (array $parents) use (&$loads): array {
                $loads[] = array_keys($parents);
                return [];
            }
        );

        $this->childProducts(null, $this->links([10 => [1, 2], 20 => [2, 3], 30 => [4]]), $loader)
            ->prefetch([$this->product(10), $this->product(20), $this->product(30)]);

        $this->assertSame([[10, 30], [20]], $loads);
    }

    /**
     * A load that fails leaves its parents to core's provider: the line is still right, only slower.
     *
     * @return void
     */
    public function testAFailedLoadIsLoggedAndItsParentsFallBackToTheProvider(): void
    {
        $parent = $this->product(10);
        $loader = $this->createStub(ChildLoader::class);
        $loader->method('load')->willThrowException(new \RuntimeException('Lost connection'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with($this->stringContains('Lost connection'));
        $provider = $this->createMock(ConfigurableOptionsProviderInterface::class);
        $provider->expects($this->once())->method('getProducts')->willReturn([]);

        $childProducts = $this->childProducts($provider, $this->links([10 => [11]]), $loader, null, $logger);
        $childProducts->prefetch([$parent]);

        $this->assertSame([], $childProducts->get($parent));
    }

    /**
     * @return void
     */
    public function testOnlyConfigurablesAreLoaded(): void
    {
        $links = $this->createMock(VariantLinks::class);
        $links->expects($this->never())->method('forParents');
        $loader = $this->createMock(ChildLoader::class);
        $loader->expects($this->never())->method('load');

        $this->childProducts(null, $links, $loader)->prefetch([$this->product(1, 'simple')]);
    }

    /**
     * @return void
     */
    public function testResetStateForgetsThePage(): void
    {
        $parent   = $this->product(10);
        $provider = $this->createMock(ConfigurableOptionsProviderInterface::class);
        $provider->expects($this->once())->method('getProducts')->willReturn([]);
        $loader = $this->createStub(ChildLoader::class);
        $loader->method('load')->willReturn([10 => []]);

        $childProducts = $this->childProducts($provider, $this->links([10 => [11]]), $loader);
        $childProducts->prefetch([$parent]);
        $childProducts->_resetState();

        $childProducts->get($parent);
    }

    /**
     * The class under test over the given doubles, or stubs; core's filter passes children through.
     *
     * @param ConfigurableOptionsProviderInterface|null $provider
     * @param VariantLinks|null $links
     * @param ChildLoader|null $loader
     * @param ConfigurableOptionsFilterInterface|null $filter
     * @param LoggerInterface|null $logger
     * @return ChildProducts
     */
    private function childProducts(
        ?ConfigurableOptionsProviderInterface $provider = null,
        ?VariantLinks $links = null,
        ?ChildLoader $loader = null,
        ?ConfigurableOptionsFilterInterface $filter = null,
        ?LoggerInterface $logger = null
    ): ChildProducts {
        if ($filter === null) {
            $filter = $this->createStub(ConfigurableOptionsFilterInterface::class);
            $filter->method('filter')->willReturnArgument(1);
        }

        return new ChildProducts(
            $provider ?? $this->createStub(ConfigurableOptionsProviderInterface::class),
            $filter,
            $links ?? $this->links([]),
            $loader ?? $this->createStub(ChildLoader::class),
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * Parent → child links as the resource model reads them.
     *
     * @param array<int, int[]> $links
     * @return VariantLinks
     */
    private function links(array $links): VariantLinks
    {
        $variantLinks = $this->createStub(VariantLinks::class);
        $variantLinks->method('forParents')->willReturnCallback(
            static fn (array $parentIds): array => array_intersect_key($links, array_flip($parentIds))
        );

        return $variantLinks;
    }

    /**
     * A product of the given type in store view 1.
     *
     * @param int $id
     * @param string $typeId
     * @return Product
     */
    private function product(int $id, string $typeId = Configurable::TYPE_CODE): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn($id);
        $product->method('getTypeId')->willReturn($typeId);
        $product->method('getStoreId')->willReturn(1);
        $product->method('getSku')->willReturn('sku-' . $id);

        return $product;
    }
}
