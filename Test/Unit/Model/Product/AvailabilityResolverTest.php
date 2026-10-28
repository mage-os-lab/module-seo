<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\Exception\LocalizedException;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use MageOS\Seo\Model\Product\Stock\StockReaderInterface;
use MageOS\Seo\Model\ResourceModel\StockItemManageStock;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Issue #30: availability from the stock index, as the storefront reads it, telling stock on hand
 * from stock that is only on backorder, a page or a feed page at a time.
 *
 * The stock comes from a StockReaderInterface (MSI's index or CatalogInventory's); the readers have
 * tests of their own.
 */
class AvailabilityResolverTest extends TestCase
{
    /**
     * @var StockConfigurationInterface&Stub
     */
    private StockConfigurationInterface&Stub $stockConfiguration;

    protected function setUp(): void
    {
        $this->stockConfiguration = $this->createStub(StockConfigurationInterface::class);
        $this->stockConfiguration->method('getDefaultScopeId')->willReturn(0);
        $this->stockConfiguration->method('getManageStock')->willReturn(1);
        // Whether each product type has a quantity of its own, as product_types.xml declares it.
        $this->stockConfiguration->method('getIsQtyTypeIds')->willReturn([
            'simple'       => true,
            'virtual'      => true,
            'downloadable' => true,
            'configurable' => false,
            'bundle'       => false,
            'grouped'      => false,
        ]);
    }

    public function testEachProductOfABatchGetsItsAvailability(): void
    {
        $resolver = $this->resolver(
            [
                'on-hand'      => ['salable' => true, 'quantity' => 4.0],
                'backorder'    => ['salable' => true, 'quantity' => 0.0],
                'below-zero'   => ['salable' => true, 'quantity' => -3.0],
                'unmanaged'    => ['salable' => true, 'quantity' => 0.0],
                'sold-out'     => ['salable' => false, 'quantity' => 0.0],
                'configurable' => ['salable' => true, 'quantity' => 0.0],
                // 'not-in-stock' has no row.
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
            [
                'on-hand'  => ['salable' => true, 'quantity' => 4.0],
                'sold-out' => ['salable' => false, 'quantity' => 0.0],
            ],
            manageStock: $manageStock
        );

        $resolver->resolveMany([$this->product('on-hand'), $this->product('sold-out')]);
    }

    public function testManageStockIsLookedUpWithSkusAsStrings(): void
    {
        // As array keys PHP turns "123" into 123; MySQL would compare "0123" with 123 as numbers.
        $manageStock = $this->createMock(StockItemManageStock::class);
        $manageStock->expects($this->once())
            ->method('forSkus')
            ->with(['123', '0123'], 0)
            ->willReturn([]);

        $this->resolver(
            ['123' => ['salable' => true, 'quantity' => 0.0], '0123' => ['salable' => true, 'quantity' => 0.0]],
            manageStock: $manageStock
        )->resolveMany([$this->product('123'), $this->product('0123')]);
    }

    public function testABatchIsOneStockRead(): void
    {
        $asked  = null;
        $reader = $this->reader();
        $reader->expects($this->once())
            ->method('read')
            ->willReturnCallback(function (array $products) use (&$asked): array {
                $asked = self::skus($products);
                return [];
            });

        $this->resolver(reader: $reader)
            ->resolveMany([$this->product('a'), $this->product('b'), $this->product('123'), $this->product('')]);

        // Products without a SKU are left out.
        $this->assertSame(['a', 'b', '123'], $asked);
    }

    public function testProductsPrefetchedAlreadyAreNotLookedUpAgain(): void
    {
        $reader = $this->reader();
        $reader->expects($this->once())
            ->method('read')
            ->willReturn(['a' => ['salable' => true, 'quantity' => 1.0]]);
        $resolver = $this->resolver(reader: $reader);

        $resolver->prefetch([$this->product('a')]);
        $resolver->prefetch([$this->product('a')]);
    }

    public function testTheBestOfSeveralProductsIsOneLookup(): void
    {
        $reader = $this->reader();
        $reader->expects($this->once())
            ->method('read')
            ->willReturn([
                'a' => ['salable' => false, 'quantity' => 0.0],
                'b' => ['salable' => true, 'quantity' => 3.0],
            ]);

        $this->assertSame(
            AvailabilityResolver::IN_STOCK,
            $this->resolver(reader: $reader)->best([$this->product('a'), $this->product('b')])
        );
    }

    public function testAProductWithoutASkuIsOutOfStockWithoutALookup(): void
    {
        $reader = $this->reader();
        $reader->expects($this->never())->method('read');

        $this->assertSame(
            AvailabilityResolver::OUT_OF_STOCK,
            $this->resolver(reader: $reader)->resolve($this->product(''))
        );
    }

    public function testAFailedBatchThrows(): void
    {
        $this->expectException(LocalizedException::class);
        $this->resolver(reader: $this->failingReader())->resolveMany([$this->product('a')]);
    }

    /**
     * Review low item, decision D8a: a lookup that failed says nothing about the stock, so the
     * answer is unknown (null), not OutOfStock.
     */
    public function testAFailedLookupForOneProductIsLoggedAndUnknown(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('"a"'));

        $this->assertNull(
            $this->resolver(reader: $this->failingReader(), logger: $logger)->resolve($this->product('a'))
        );
    }

    public function testPrefetchedProductsAreResolvedWithoutAnotherLookup(): void
    {
        $reader = $this->reader();
        $reader->expects($this->once())
            ->method('read')
            ->willReturn([
                'a' => ['salable' => true, 'quantity' => 1.0],
                'b' => ['salable' => false, 'quantity' => 0.0],
            ]);
        $resolver = $this->resolver(reader: $reader);

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
        $reader = $this->reader();
        $reader->expects($this->once())
            ->method('read')
            ->willThrowException(new LocalizedException(__('index gone')));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $resolver = $this->resolver(reader: $reader, logger: $logger);

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
        $this->assertNull(
            $this->resolver(reader: $this->failingReader())->best([$this->product('a'), $this->product('b')])
        );
    }

    public function testTheBestOfSeveralProducts(): void
    {
        $resolver = $this->resolver(
            [
                'in'   => ['salable' => true, 'quantity' => 2.0],
                'back' => ['salable' => true, 'quantity' => 0.0],
                'out'  => ['salable' => false, 'quantity' => 0.0],
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
        $reader = $this->reader();
        $reader->expects($this->exactly(2))
            ->method('read')
            ->willReturn(['a' => ['salable' => true, 'quantity' => 1.0]]);
        $resolver = $this->resolver(reader: $reader);

        $resolver->prefetch([$this->product('a')]);
        $resolver->_resetState();
        $resolver->resolve($this->product('a'));
    }

    /**
     * What was prefetched for one stock doesn't answer for another: store emulation can move the
     * resolver to a website on a different MSI stock.
     */
    public function testPrefetchedAvailabilityIsPerStock(): void
    {
        $reader = $this->createMock(StockReaderInterface::class);
        $reader->method('getCurrentStockId')->willReturnOnConsecutiveCalls(1, 2);
        $reader->expects($this->exactly(2))
            ->method('read')
            ->willReturn(['a' => ['salable' => true, 'quantity' => 1.0]]);
        $resolver = $this->resolver(reader: $reader);

        $resolver->prefetch([$this->product('a')]);
        $resolver->resolve($this->product('a'));
    }

    public function testTheStockIsTheReaders(): void
    {
        $reader = $this->createStub(StockReaderInterface::class);
        $reader->method('getCurrentStockId')->willReturn(7);

        $this->assertSame(7, $this->resolver(reader: $reader)->getCurrentStockId());
    }

    /**
     * The resolver over stock rows (sku => row; a SKU without one has no row) and stored
     * manage-stock settings (sku => managed, or null to follow the configuration, which manages
     * stock). A SKU that looks numeric is an integer key of $stock, as PHP makes it.
     *
     * @param array<array-key, array{salable: bool, quantity: float}> $stock
     * @param array<string, bool|null> $managed
     * @param StockReaderInterface|null $reader
     * @param StockItemManageStock|null $manageStock
     * @param LoggerInterface|null $logger
     * @return AvailabilityResolver
     */
    private function resolver(
        array $stock = [],
        array $managed = [],
        ?StockReaderInterface $reader = null,
        ?StockItemManageStock $manageStock = null,
        ?LoggerInterface $logger = null
    ): AvailabilityResolver {
        if ($reader === null) {
            $reader = $this->createStub(StockReaderInterface::class);
            $reader->method('getCurrentStockId')->willReturn(1);
            $reader->method('read')->willReturnCallback(
                static fn (array $products): array => array_intersect_key($stock, array_flip(self::skus($products)))
            );
        }
        if ($manageStock === null) {
            $manageStock = $this->createStub(StockItemManageStock::class);
            $manageStock->method('forSkus')->willReturnCallback(
                static fn (array $skus): array => array_intersect_key($managed, array_flip($skus))
            );
        }

        return new AvailabilityResolver(
            $reader,
            $manageStock,
            $this->stockConfiguration,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * A reader on stock 1, its reads to be set by the test.
     *
     * @return StockReaderInterface&MockObject
     */
    private function reader(): StockReaderInterface&MockObject
    {
        $reader = $this->createMock(StockReaderInterface::class);
        $reader->method('getCurrentStockId')->willReturn(1);

        return $reader;
    }

    /**
     * A reader on stock 1 whose index can't be read.
     *
     * @return StockReaderInterface
     */
    private function failingReader(): StockReaderInterface
    {
        $reader = $this->createStub(StockReaderInterface::class);
        $reader->method('getCurrentStockId')->willReturn(1);
        $reader->method('read')->willThrowException(new LocalizedException(__('index gone')));

        return $reader;
    }

    /**
     * @param ProductInterface[] $products
     * @return string[] Their SKUs
     */
    private static function skus(array $products): array
    {
        return array_map(static fn (ProductInterface $product): string => (string) $product->getSku(), $products);
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
