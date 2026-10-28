<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\Stock;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Model\StockRegistryPreloader;
use Magento\CatalogInventory\Model\StockRegistryPreloaderFactory;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\CatalogInventory\Model\StockRegistryStorageFactory;
use MageOS\Seo\Model\Product\Stock\CatalogInventoryStockReader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Stock from CatalogInventory's index, the one stock a store without MSI has, read through core's
 * request cache on the storefront and around it elsewhere.
 *
 * The preloader and storage factories are Magento's generated classes, so this test needs an
 * installation to have generated them. The mutation-testing run works from the module directory
 * alone and excludes this group; the unit job, which runs inside an installation, does not.
 *
 * @group magento-generated
 */
#[Group('magento-generated')]
class CatalogInventoryStockReaderTest extends TestCase
{
    public function testEachStatusIsReadBySkuInOneQuery(): void
    {
        $preloader = $this->createMock(StockRegistryPreloader::class);
        $preloader->expects($this->once())
            ->method('preloadStockStatuses')
            ->with([1, 2, 3], 0)
            // Product 3 has no row; 99 was not asked for.
            ->willReturn([
                $this->stockStatus(1, 1, 5.0),
                $this->stockStatus(2, 0, 0.0),
                $this->stockStatus(99, 1, 1.0),
            ]);

        $this->assertSame(
            [
                'a' => ['salable' => true, 'quantity' => 5.0],
                'b' => ['salable' => false, 'quantity' => 0.0],
            ],
            $this->reader(isolated: $preloader)->read([
                $this->product(1, 'a'),
                $this->product(2, 'b'),
                $this->product(3, 'c'),
                // Neither has a row: one has no ID, the other no SKU.
                $this->product(0, 'd'),
                $this->product(4, ''),
            ])
        );
    }

    /**
     * Outside the storefront: a preloader with a storage of its own, so core's shared request cache
     * is neither read (it may be stale in a queue consumer) nor filled (a feed would fill it with
     * the catalogue).
     */
    public function testOutsideTheStorefrontCoresRequestCacheIsNeitherReadNorFilled(): void
    {
        $shared = $this->createMock(StockRegistryStorage::class);
        $shared->expects($this->never())->method('getStockStatus');
        $shared->expects($this->never())->method('setStockStatus');
        $sharedPreloader = $this->createMock(StockRegistryPreloader::class);
        $sharedPreloader->expects($this->never())->method('preloadStockStatuses');
        $isolatedStorage = $this->createStub(StockRegistryStorage::class);
        $isolated        = $this->createStub(StockRegistryPreloader::class);
        $isolated->method('preloadStockStatuses')->willReturn([$this->stockStatus(1, 1, 1.0)]);
        $preloaderFactory = $this->createMock(StockRegistryPreloaderFactory::class);
        $preloaderFactory->expects($this->once())
            ->method('create')
            ->with(['stockRegistryStorage' => $isolatedStorage])
            ->willReturn($isolated);
        $storageFactory = $this->createStub(StockRegistryStorageFactory::class);
        $storageFactory->method('create')->willReturn($isolatedStorage);

        $reader = new CatalogInventoryStockReader(
            $sharedPreloader,
            $preloaderFactory,
            $storageFactory,
            $shared,
            $this->stockConfiguration()
        );

        $this->assertSame(['a' => ['salable' => true, 'quantity' => 1.0]], $reader->read([$this->product(1, 'a')]));
    }

    public function testProductsWithoutAnIdAndSkuAreNoQuery(): void
    {
        $preloader = $this->createMock(StockRegistryPreloader::class);
        $preloader->expects($this->never())->method('preloadStockStatuses');

        $this->assertSame(
            [],
            $this->reader(isolated: $preloader)->read([$this->product(0, 'a'), $this->product(1, '')])
        );
    }

    public function testReadThroughAnswersFromCoresCacheWithoutAQuery(): void
    {
        $preloader = $this->createMock(StockRegistryPreloader::class);
        $preloader->expects($this->never())->method('preloadStockStatuses');
        $storage = $this->storage([1 => $this->stockStatus(1, 1, 2.0), 2 => $this->stockStatus(2, 0, 0.0)]);

        $this->assertSame(
            [
                'a' => ['salable' => true, 'quantity' => 2.0],
                'b' => ['salable' => false, 'quantity' => 0.0],
            ],
            $this->reader($preloader, $storage, readThrough: true)
                ->read([$this->product(1, 'a'), $this->product(2, 'b')])
        );
    }

    /**
     * Core's preloader reads the rest in one query and adds it to the request cache.
     */
    public function testReadThroughReadsTheRestThroughCoresPreloader(): void
    {
        $preloader = $this->createMock(StockRegistryPreloader::class);
        $preloader->expects($this->once())
            ->method('preloadStockStatuses')
            ->with([2], 0)
            ->willReturn([$this->stockStatus(2, 1, 3.0)]);
        $storage = $this->storage([1 => $this->stockStatus(1, 1, 2.0)]);

        $this->assertSame(
            [
                'a' => ['salable' => true, 'quantity' => 2.0],
                'b' => ['salable' => true, 'quantity' => 3.0],
            ],
            $this->reader($preloader, $storage, readThrough: true)
                ->read([$this->product(1, 'a'), $this->product(2, 'b')])
        );
    }

    public function testTheStockIsCatalogInventorysOne(): void
    {
        $this->assertSame(1, $this->reader()->getCurrentStockId());
    }

    /**
     * A reader whose shared preloader and storage are those given, and whose preloader with a storage
     * of its own is $isolated.
     *
     * @param StockRegistryPreloader|null $shared
     * @param StockRegistryStorage|null $storage
     * @param StockRegistryPreloader|null $isolated
     * @param bool $readThrough
     * @return CatalogInventoryStockReader
     */
    private function reader(
        ?StockRegistryPreloader $shared = null,
        ?StockRegistryStorage $storage = null,
        ?StockRegistryPreloader $isolated = null,
        bool $readThrough = false
    ): CatalogInventoryStockReader {
        $preloaderFactory = $this->createStub(StockRegistryPreloaderFactory::class);
        $preloaderFactory->method('create')
            ->willReturn($isolated ?? $this->createStub(StockRegistryPreloader::class));
        $storageFactory = $this->createStub(StockRegistryStorageFactory::class);
        $storageFactory->method('create')->willReturn($this->createStub(StockRegistryStorage::class));

        return new CatalogInventoryStockReader(
            $shared ?? $this->createStub(StockRegistryPreloader::class),
            $preloaderFactory,
            $storageFactory,
            $storage ?? $this->createStub(StockRegistryStorage::class),
            $this->stockConfiguration(),
            $readThrough
        );
    }

    /**
     * @return StockConfigurationInterface
     */
    private function stockConfiguration(): StockConfigurationInterface
    {
        $stockConfiguration = $this->createStub(StockConfigurationInterface::class);
        $stockConfiguration->method('getDefaultScopeId')->willReturn(0);

        return $stockConfiguration;
    }

    /**
     * Core's request cache, holding the statuses given (product ID => status) at the default scope.
     *
     * @param array<int, StockStatusInterface> $statuses
     * @return StockRegistryStorage&MockObject
     */
    private function storage(array $statuses): StockRegistryStorage&MockObject
    {
        $storage = $this->createMock(StockRegistryStorage::class);
        $storage->expects($this->atLeastOnce())->method('getStockStatus')->willReturnCallback(
            static fn (int $productId, int $scopeId): ?StockStatusInterface
                => $scopeId === 0 ? ($statuses[$productId] ?? null) : null
        );

        return $storage;
    }

    /**
     * @param int $productId
     * @param int $stockStatus
     * @param float $qty
     * @return StockStatusInterface
     */
    private function stockStatus(int $productId, int $stockStatus, float $qty): StockStatusInterface
    {
        $status = $this->createStub(StockStatusInterface::class);
        $status->method('getProductId')->willReturn($productId);
        $status->method('getStockStatus')->willReturn($stockStatus);
        $status->method('getQty')->willReturn($qty);

        return $status;
    }

    /**
     * @param int $id
     * @param string $sku
     * @return ProductInterface
     */
    private function product(int $id, string $sku): ProductInterface
    {
        $product = $this->createStub(ProductInterface::class);
        $product->method('getId')->willReturn($id === 0 ? null : $id);
        $product->method('getSku')->willReturn($sku);

        return $product;
    }
}
