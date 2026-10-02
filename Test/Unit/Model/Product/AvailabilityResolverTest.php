<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventoryConfigurationApi\Api\Data\StockItemConfigurationInterface;
use Magento\InventoryConfigurationApi\Api\GetStockItemConfigurationInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class AvailabilityResolverTest extends TestCase
{
    /**
     * @var StoreManagerInterface&Stub
     */
    private StoreManagerInterface&Stub $storeManager;

    /**
     * @var StockResolverInterface&Stub
     */
    private StockResolverInterface&Stub $stockResolver;

    /**
     * @var IsProductSalableInterface&Stub
     */
    private IsProductSalableInterface&Stub $isProductSalable;

    /**
     * @var GetStockItemConfigurationInterface&Stub
     */
    private GetStockItemConfigurationInterface&Stub $getStockItemConfiguration;

    /**
     * @var AvailabilityResolver
     */
    private AvailabilityResolver $resolver;

    protected function setUp(): void
    {
        $this->storeManager              = $this->createStub(StoreManagerInterface::class);
        $this->stockResolver             = $this->createStub(StockResolverInterface::class);
        $this->isProductSalable          = $this->createStub(IsProductSalableInterface::class);
        $this->getStockItemConfiguration = $this->createStub(GetStockItemConfigurationInterface::class);

        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getCode')->willReturn('base');
        $this->storeManager->method('getWebsite')->willReturn($website);

        $this->resolver = $this->resolver();
    }

    /**
     * The resolver under test, over the given inventory services or this test's stubs.
     *
     * @param StockResolverInterface|null $stockResolver
     * @param IsProductSalableInterface|null $isProductSalable
     * @param GetStockItemConfigurationInterface|null $getStockItemConfiguration
     * @return AvailabilityResolver
     */
    private function resolver(
        ?StockResolverInterface $stockResolver = null,
        ?IsProductSalableInterface $isProductSalable = null,
        ?GetStockItemConfigurationInterface $getStockItemConfiguration = null
    ): AvailabilityResolver {
        return new AvailabilityResolver(
            $this->storeManager,
            $stockResolver ?? $this->stockResolver,
            $isProductSalable ?? $this->isProductSalable,
            $getStockItemConfiguration ?? $this->getStockItemConfiguration
        );
    }

    private function makeProduct(string $sku): Product&Stub
    {
        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn($sku);
        return $product;
    }

    private function stubStockId(int $stockId): void
    {
        $stock = $this->createStub(StockInterface::class);
        $stock->method('getStockId')->willReturn($stockId);
        $this->stockResolver->method('execute')->willReturn($stock);
    }

    public function testEmptySkuResolvesOutOfStockWithoutTouchingInventory(): void
    {
        $stockResolver = $this->createMock(StockResolverInterface::class);
        $stockResolver->expects($this->never())->method('execute');

        $this->assertSame(
            AvailabilityResolver::OUT_OF_STOCK,
            $this->resolver($stockResolver)->resolve($this->makeProduct(''))
        );
    }

    public function testSalableProductResolvesInStock(): void
    {
        $this->stubStockId(1);
        $isProductSalable = $this->createMock(IsProductSalableInterface::class);
        $isProductSalable->method('execute')->with('SKU-1', 1)->willReturn(true);

        $this->assertSame(
            AvailabilityResolver::IN_STOCK,
            $this->resolver(isProductSalable: $isProductSalable)->resolve($this->makeProduct('SKU-1'))
        );
    }

    public function testBackorderableOutOfStockResolvesBackOrder(): void
    {
        $this->stubStockId(1);
        $this->isProductSalable->method('execute')->willReturn(false);

        $configuration = $this->createStub(StockItemConfigurationInterface::class);
        $configuration->method('getBackorders')->willReturn(1);
        $getStockItemConfiguration = $this->createMock(GetStockItemConfigurationInterface::class);
        $getStockItemConfiguration->method('execute')->with('SKU-1', 1)->willReturn($configuration);

        $this->assertSame(
            AvailabilityResolver::BACKORDER,
            $this->resolver(getStockItemConfiguration: $getStockItemConfiguration)
                ->resolve($this->makeProduct('SKU-1'))
        );
    }

    public function testNonBackorderableOutOfStockResolvesOutOfStock(): void
    {
        $this->stubStockId(1);
        $this->isProductSalable->method('execute')->willReturn(false);

        $configuration = $this->createStub(StockItemConfigurationInterface::class);
        $configuration->method('getBackorders')->willReturn(0);
        $this->getStockItemConfiguration->method('execute')->willReturn($configuration);

        $this->assertSame(
            AvailabilityResolver::OUT_OF_STOCK,
            $this->resolver->resolve($this->makeProduct('SKU-1'))
        );
    }

    public function testInventoryExceptionResolvesOutOfStock(): void
    {
        // Product not assigned to any stock/source: the MSI contracts throw
        // rather than answering, and the resolver must degrade to OutOfStock.
        $this->stubStockId(1);
        $this->isProductSalable->method('execute')
            ->willThrowException(new NoSuchEntityException(__('not assigned')));

        $this->assertSame(
            AvailabilityResolver::OUT_OF_STOCK,
            $this->resolver->resolve($this->makeProduct('SKU-1'))
        );
    }

    public function testStockIdIsMemoisedPerWebsite(): void
    {
        $stock = $this->createStub(StockInterface::class);
        $stock->method('getStockId')->willReturn(1);
        $stockResolver = $this->createMock(StockResolverInterface::class);
        $stockResolver->expects($this->once())->method('execute')->willReturn($stock);
        $this->isProductSalable->method('execute')->willReturn(true);
        $resolver = $this->resolver($stockResolver);

        $resolver->resolve($this->makeProduct('SKU-1'));
        $resolver->resolve($this->makeProduct('SKU-2'));
    }

    public function testResetStateDropsTheMemoisedStockIds(): void
    {
        $stock = $this->createStub(StockInterface::class);
        $stock->method('getStockId')->willReturn(1);
        $stockResolver = $this->createMock(StockResolverInterface::class);
        $stockResolver->expects($this->exactly(2))->method('execute')->willReturn($stock);
        $this->isProductSalable->method('execute')->willReturn(true);
        $resolver = $this->resolver($stockResolver);

        $resolver->resolve($this->makeProduct('SKU-1'));
        $resolver->_resetState();
        $resolver->resolve($this->makeProduct('SKU-1'));
    }
}
