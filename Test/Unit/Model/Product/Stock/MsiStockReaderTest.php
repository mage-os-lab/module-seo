<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\Stock;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\InventorySalesApi\Model\GetStockItemsDataInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Product\Stock\MsiStockReader;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Stock from MSI's index, through MSI's services fetched when the reader runs: MSI is optional.
 *
 * MSI's interfaces exist only where MSI is installed. The mutation-testing run works from the module
 * directory alone, where composer has only suggested them, and excludes this group; the unit job,
 * which runs inside an installation, does not.
 *
 * @group msi
 */
#[Group('msi')]
class MsiStockReaderTest extends TestCase
{
    /**
     * @var string The current website's code
     */
    private string $website = 'base';

    /**
     * @var array<string, int> website code => its MSI stock
     */
    private array $stocks = ['base' => 1, 'eu' => 2];

    public function testEachRowIsReadAndAProductWithoutOneLeftOut(): void
    {
        // As MSI's resource model gives them: strings from the database, null for no row.
        $getStockItemsData = $this->createStub(GetStockItemsDataInterface::class);
        $getStockItemsData->method('execute')->willReturn([
            'on-hand'  => [
                GetStockItemsDataInterface::QUANTITY   => '4.0000',
                GetStockItemsDataInterface::IS_SALABLE => '1',
            ],
            'sold-out' => [
                GetStockItemsDataInterface::QUANTITY   => '0.0000',
                GetStockItemsDataInterface::IS_SALABLE => '0',
            ],
            'null-row' => null,
        ]);

        $this->assertSame(
            [
                'on-hand'  => ['salable' => true, 'quantity' => 4.0],
                'sold-out' => ['salable' => false, 'quantity' => 0.0],
            ],
            $this->reader($getStockItemsData)->read([
                $this->product('on-hand'),
                $this->product('sold-out'),
                $this->product('null-row'),
                $this->product('no-row'),
            ])
        );
    }

    public function testSkusAreReadOnceAndAsStringsFromTheWebsitesStock(): void
    {
        // As array keys PHP turns "123" into 123; MySQL would compare "0123" with 123 as numbers.
        $this->website     = 'eu';
        $getStockItemsData = $this->createMock(GetStockItemsDataInterface::class);
        $getStockItemsData->expects($this->once())
            ->method('execute')
            ->with(['123', '0123', 'a'], 2)
            ->willReturn([]);

        $this->reader($getStockItemsData)->read([
            $this->product('123'),
            $this->product('0123'),
            $this->product('a'),
            $this->product('a'),
            $this->product(''),
        ]);
    }

    public function testProductsWithoutASkuAreNoRead(): void
    {
        $getStockItemsData = $this->createMock(GetStockItemsDataInterface::class);
        $getStockItemsData->expects($this->never())->method('execute');

        $this->assertSame([], $this->reader($getStockItemsData)->read([$this->product('')]));
    }

    public function testTheStockIsResolvedOncePerWebsite(): void
    {
        $stockResolver = $this->createMock(StockResolverInterface::class);
        $stockResolver->expects($this->exactly(2))->method('execute')->willReturnCallback($this->stockOf(...));
        $reader = $this->reader(stockResolver: $stockResolver);

        $this->assertSame(1, $reader->getCurrentStockId());
        $this->assertSame(1, $reader->getCurrentStockId());
        $this->website = 'eu';
        $this->assertSame(2, $reader->getCurrentStockId());
        $this->assertSame(2, $reader->getCurrentStockId());
    }

    public function testResetStateForgetsTheStocks(): void
    {
        $stockResolver = $this->createMock(StockResolverInterface::class);
        $stockResolver->expects($this->exactly(2))->method('execute')->willReturnCallback($this->stockOf(...));
        $reader = $this->reader(stockResolver: $stockResolver);

        $reader->getCurrentStockId();
        $reader->_resetState();
        $reader->getCurrentStockId();
    }

    /**
     * get(): the instances constructor injection would give, through the area's preference, so the
     * storefront keeps MSI's request cache.
     */
    public function testMsisServicesAreTheSharedInstances(): void
    {
        $services      = $this->services($this->createStub(GetStockItemsDataInterface::class), null);
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects($this->atLeastOnce())
            ->method('get')
            ->willReturnCallback(static fn (string $type): object => $services[$type]);
        $objectManager->expects($this->never())->method('create');

        (new MsiStockReader($this->storeManager(), $objectManager))->read([$this->product('a')]);
    }

    /**
     * @param GetStockItemsDataInterface|null $getStockItemsData
     * @param StockResolverInterface|null $stockResolver
     * @return MsiStockReader
     */
    private function reader(
        ?GetStockItemsDataInterface $getStockItemsData = null,
        ?StockResolverInterface $stockResolver = null
    ): MsiStockReader {
        $services      = $this->services(
            $getStockItemsData ?? $this->createStub(GetStockItemsDataInterface::class),
            $stockResolver
        );
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(
            static fn (string $type): object => $services[$type]
                ?? throw new \LogicException(\sprintf('No %s in this test', $type))
        );

        return new MsiStockReader($this->storeManager(), $objectManager);
    }

    /**
     * MSI's two services, by interface. Without a stock resolver given, one answering each website
     * with its stock in $this->stocks.
     *
     * @param GetStockItemsDataInterface $getStockItemsData
     * @param StockResolverInterface|null $stockResolver
     * @return array<string, object>
     */
    private function services(
        GetStockItemsDataInterface $getStockItemsData,
        ?StockResolverInterface $stockResolver
    ): array {
        if ($stockResolver === null) {
            $stockResolver = $this->createStub(StockResolverInterface::class);
            $stockResolver->method('execute')->willReturnCallback($this->stockOf(...));
        }

        return [
            GetStockItemsDataInterface::class => $getStockItemsData,
            StockResolverInterface::class     => $stockResolver,
        ];
    }

    /**
     * The MSI stock of a website, as StockResolverInterface::execute() answers it.
     *
     * @param string $type
     * @param string $code
     * @return StockInterface
     */
    private function stockOf(string $type, string $code): StockInterface
    {
        $this->assertSame('website', $type);
        $stock = $this->createStub(StockInterface::class);
        $stock->method('getStockId')->willReturn($this->stocks[$code]);

        return $stock;
    }

    /**
     * A store manager whose current website is $this->website, read when asked.
     *
     * @return StoreManagerInterface
     */
    private function storeManager(): StoreManagerInterface
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturnCallback(function (): WebsiteInterface {
            $website = $this->createStub(WebsiteInterface::class);
            $website->method('getCode')->willReturn($this->website);

            return $website;
        });

        return $storeManager;
    }

    /**
     * @param string $sku
     * @return ProductInterface
     */
    private function product(string $sku): ProductInterface
    {
        $product = $this->createStub(ProductInterface::class);
        $product->method('getSku')->willReturn($sku);

        return $product;
    }
}
