<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product;

use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\InventorySalesApi\Model\GetStockItemsDataInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use MageOS\Seo\Model\ResourceModel\StockItemManageStock;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Issue #30: availability from the stock index, as the storefront reads it, telling stock on hand
 * from stock that is only on backorder, a page or a feed page at a time.
 */
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
     * @var StockConfigurationInterface&Stub
     */
    private StockConfigurationInterface&Stub $stockConfiguration;

    protected function setUp(): void
    {
        $this->storeManager       = $this->createStub(StoreManagerInterface::class);
        $this->stockResolver      = $this->createStub(StockResolverInterface::class);
        $this->stockConfiguration = $this->createStub(StockConfigurationInterface::class);

        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getCode')->willReturn('base');
        $this->storeManager->method('getWebsite')->willReturn($website);

        $stock = $this->createStub(StockInterface::class);
        $stock->method('getStockId')->willReturn(1);
        $this->stockResolver->method('execute')->willReturn($stock);

        $this->stockConfiguration->method('getDefaultScopeId')->willReturn(0);
        $this->stockConfiguration->method('getManageStock')->willReturn(1);
    }

    public function testEachProductOfABatchGetsItsAvailability(): void
    {
        $resolver = $this->resolver(
            [
                'on-hand'       => ['quantity' => 4, 'is_salable' => 1],
                'backorder'     => ['quantity' => 0, 'is_salable' => 1],
                'below-zero'    => ['quantity' => -3, 'is_salable' => 1],
                'unmanaged'     => ['quantity' => 0, 'is_salable' => 1],
                'sold-out'      => ['quantity' => 0, 'is_salable' => 0],
                'configurable'  => ['quantity' => 0, 'is_salable' => 1],
                'not-in-stock'  => null,
            ],
            ['backorder' => true, 'below-zero' => null, 'unmanaged' => false]
        );

        $this->assertSame(
            [
                'on-hand'      => AvailabilityResolver::IN_STOCK,
                // Salable at zero or below: on backorder when stock is managed (configured or by
                // default), in stock when it isn't.
                'backorder'    => AvailabilityResolver::BACKORDER,
                'below-zero'   => AvailabilityResolver::BACKORDER,
                'unmanaged'    => AvailabilityResolver::IN_STOCK,
                'sold-out'     => AvailabilityResolver::OUT_OF_STOCK,
                // No quantity of its own: salable means a child can be bought.
                'configurable' => AvailabilityResolver::IN_STOCK,
                'not-in-stock' => AvailabilityResolver::OUT_OF_STOCK,
            ],
            $resolver->resolveMany([
                $this->product('on-hand'),
                $this->product('backorder'),
                $this->product('below-zero'),
                $this->product('unmanaged'),
                $this->product('sold-out'),
                $this->product('configurable', 'configurable'),
                $this->product('not-in-stock'),
            ])
        );
    }

    public function testManageStockIsReadOnlyForProductsSalableAtZeroOrBelow(): void
    {
        $manageStock = $this->createMock(StockItemManageStock::class);
        $manageStock->expects($this->never())->method('forSkus');

        $resolver = $this->resolver(
            ['on-hand' => ['quantity' => 4, 'is_salable' => 1], 'sold-out' => ['quantity' => 0, 'is_salable' => 0]],
            manageStock: $manageStock
        );

        $resolver->resolveMany([$this->product('on-hand'), $this->product('sold-out')]);
    }

    public function testABatchIsOneStockLookup(): void
    {
        $getStockItemsData = $this->createMock(GetStockItemsDataInterface::class);
        $getStockItemsData->expects($this->once())
            ->method('execute')
            ->with(['a', 'b', '123'], 1)
            ->willReturn([]);

        $this->resolver(getStockItemsData: $getStockItemsData)
            ->resolveMany([$this->product('a'), $this->product('b'), $this->product('123'), $this->product('')]);
    }

    public function testSkusThatLookNumericAreLookedUpAsStrings(): void
    {
        // As array keys PHP turns "123" into 123; MySQL would compare "0123" with 123 as numbers.
        $asked             = null;
        $getStockItemsData = $this->createStub(GetStockItemsDataInterface::class);
        $getStockItemsData->method('execute')->willReturnCallback(
            function (array $skus) use (&$asked): array {
                $asked = $skus;
                return [];
            }
        );

        $this->resolver(getStockItemsData: $getStockItemsData)
            ->resolveMany([$this->product('123'), $this->product('0123')]);

        $this->assertSame(['123', '0123'], $asked);
    }

    public function testProductsPrefetchedAlreadyAreNotLookedUpAgain(): void
    {
        $getStockItemsData = $this->createMock(GetStockItemsDataInterface::class);
        $getStockItemsData->expects($this->once())
            ->method('execute')
            ->willReturn(['a' => ['quantity' => 1, 'is_salable' => 1]]);
        $resolver = $this->resolver(getStockItemsData: $getStockItemsData);

        $resolver->prefetch([$this->product('a')]);
        $resolver->prefetch([$this->product('a')]);
    }

    public function testTheBestOfSeveralProductsIsOneLookup(): void
    {
        $getStockItemsData = $this->createMock(GetStockItemsDataInterface::class);
        $getStockItemsData->expects($this->once())
            ->method('execute')
            ->willReturn(['a' => ['quantity' => 0, 'is_salable' => 0], 'b' => ['quantity' => 3, 'is_salable' => 1]]);

        $this->assertSame(
            AvailabilityResolver::IN_STOCK,
            $this->resolver(getStockItemsData: $getStockItemsData)->best([$this->product('a'), $this->product('b')])
        );
    }

    public function testAProductWithoutASkuIsOutOfStockWithoutALookup(): void
    {
        $getStockItemsData = $this->createMock(GetStockItemsDataInterface::class);
        $getStockItemsData->expects($this->never())->method('execute');

        $this->assertSame(
            AvailabilityResolver::OUT_OF_STOCK,
            $this->resolver(getStockItemsData: $getStockItemsData)->resolve($this->product(''))
        );
    }

    public function testAFailedBatchThrows(): void
    {
        $getStockItemsData = $this->createStub(GetStockItemsDataInterface::class);
        $getStockItemsData->method('execute')->willThrowException(new LocalizedException(__('index gone')));

        $this->expectException(LocalizedException::class);
        $this->resolver(getStockItemsData: $getStockItemsData)->resolveMany([$this->product('a')]);
    }

    /**
     * Review low item, decision D8a: a lookup that failed says nothing about the stock, so the
     * answer is unknown (null), not OutOfStock.
     */
    public function testAFailedLookupForOneProductIsLoggedAndUnknown(): void
    {
        $getStockItemsData = $this->createStub(GetStockItemsDataInterface::class);
        $getStockItemsData->method('execute')->willThrowException(new LocalizedException(__('index gone')));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('"a"'));

        $this->assertNull(
            $this->resolver(getStockItemsData: $getStockItemsData, logger: $logger)->resolve($this->product('a'))
        );
    }

    public function testPrefetchedProductsAreResolvedWithoutAnotherLookup(): void
    {
        $getStockItemsData = $this->createMock(GetStockItemsDataInterface::class);
        $getStockItemsData->expects($this->once())
            ->method('execute')
            ->willReturn(['a' => ['quantity' => 1, 'is_salable' => 1], 'b' => ['quantity' => 0, 'is_salable' => 0]]);
        $resolver = $this->resolver(getStockItemsData: $getStockItemsData);

        $resolver->prefetch([$this->product('a'), $this->product('b')]);

        $this->assertSame(AvailabilityResolver::IN_STOCK, $resolver->resolve($this->product('a')));
        $this->assertSame(AvailabilityResolver::OUT_OF_STOCK, $resolver->resolve($this->product('b')));
    }

    /**
     * Decision D8a: the products of a failed prefetch are unknown, and are not looked up again
     * one by one, logging the same failure for each.
     */
    public function testAFailedPrefetchIsLoggedOnceAndItsProductsAreUnknown(): void
    {
        $getStockItemsData = $this->createMock(GetStockItemsDataInterface::class);
        $getStockItemsData->expects($this->once())
            ->method('execute')
            ->willThrowException(new LocalizedException(__('index gone')));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $resolver = $this->resolver(getStockItemsData: $getStockItemsData, logger: $logger);

        $resolver->prefetch([$this->product('a'), $this->product('b')]);

        $this->assertNull($resolver->resolve($this->product('a')));
        $this->assertNull($resolver->resolve($this->product('b')));
    }

    /**
     * Decision D8a: the best of several products is unknown when nothing known beats OutOfStock and
     * some could not be read; one known to be in stock or on backorder still answers.
     */
    public function testTheBestOfProductsSomeOfWhichAreUnknown(): void
    {
        $getStockItemsData = $this->createStub(GetStockItemsDataInterface::class);
        $getStockItemsData->method('execute')->willThrowException(new LocalizedException(__('index gone')));
        $resolver = $this->resolver(getStockItemsData: $getStockItemsData);

        $this->assertNull($resolver->best([$this->product('a'), $this->product('b')]));
    }

    public function testTheBestOfSeveralProducts(): void
    {
        $resolver = $this->resolver(
            [
                'in'   => ['quantity' => 2, 'is_salable' => 1],
                'back' => ['quantity' => 0, 'is_salable' => 1],
                'out'  => ['quantity' => 0, 'is_salable' => 0],
            ],
            ['back' => true]
        );

        $this->assertSame(
            AvailabilityResolver::IN_STOCK,
            $resolver->best([$this->product('out'), $this->product('back'), $this->product('in')])
        );
        $this->assertSame(
            AvailabilityResolver::BACKORDER,
            $resolver->best([$this->product('out'), $this->product('back')])
        );
        $this->assertSame(AvailabilityResolver::OUT_OF_STOCK, $resolver->best([$this->product('out')]));
        $this->assertSame(AvailabilityResolver::OUT_OF_STOCK, $resolver->best([]));
    }

    public function testResetStateForgetsThePrefetchedAvailability(): void
    {
        $getStockItemsData = $this->createMock(GetStockItemsDataInterface::class);
        $getStockItemsData->expects($this->exactly(2))
            ->method('execute')
            ->willReturn(['a' => ['quantity' => 1, 'is_salable' => 1]]);
        $resolver = $this->resolver(getStockItemsData: $getStockItemsData);

        $resolver->prefetch([$this->product('a')]);
        $resolver->_resetState();
        $resolver->resolve($this->product('a'));
    }

    public function testTheStockIdIsMemoisedPerWebsite(): void
    {
        $stock = $this->createStub(StockInterface::class);
        $stock->method('getStockId')->willReturn(1);
        $stockResolver = $this->createMock(StockResolverInterface::class);
        $stockResolver->expects($this->once())->method('execute')->willReturn($stock);
        $this->stockResolver = $stockResolver;

        $resolver = $this->resolver(['a' => ['quantity' => 1, 'is_salable' => 1]]);
        $resolver->resolveMany([$this->product('a')]);
        $resolver->resolveMany([$this->product('a')]);
    }

    /**
     * The resolver over stock data (sku => row, or null for no row) and stored manage-stock settings
     * (sku => managed, or null to follow the configuration, which manages stock).
     *
     * @param array<string, array{quantity: int|float, is_salable: int}|null> $stockData
     * @param array<string, bool|null> $managed
     * @param GetStockItemsDataInterface|null $getStockItemsData
     * @param StockItemManageStock|null $manageStock
     * @param LoggerInterface|null $logger
     * @return AvailabilityResolver
     */
    private function resolver(
        array $stockData = [],
        array $managed = [],
        ?GetStockItemsDataInterface $getStockItemsData = null,
        ?StockItemManageStock $manageStock = null,
        ?LoggerInterface $logger = null
    ): AvailabilityResolver {
        if ($getStockItemsData === null) {
            $getStockItemsData = $this->createStub(GetStockItemsDataInterface::class);
            $getStockItemsData->method('execute')->willReturnCallback(
                static fn (array $skus): array => array_intersect_key($stockData, array_flip($skus))
            );
        }
        if ($manageStock === null) {
            $manageStock = $this->createStub(StockItemManageStock::class);
            $manageStock->method('forSkus')->willReturnCallback(
                static fn (array $skus): array => array_intersect_key($managed, array_flip($skus))
            );
        }
        $productTypes = $this->createStub(IsSourceItemManagementAllowedForProductTypeInterface::class);
        $productTypes->method('execute')->willReturnCallback(
            static fn (string $type): bool => \in_array($type, ['simple', 'virtual', 'downloadable'], true)
        );

        return new AvailabilityResolver(
            $this->storeManager,
            $this->stockResolver,
            $getStockItemsData,
            $productTypes,
            $manageStock,
            $this->stockConfiguration,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * @param string $sku
     * @param string $typeId
     * @return Product&Stub
     */
    private function product(string $sku, string $typeId = 'simple'): Product&Stub
    {
        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn($sku);
        $product->method('getTypeId')->willReturn($typeId);

        return $product;
    }
}
