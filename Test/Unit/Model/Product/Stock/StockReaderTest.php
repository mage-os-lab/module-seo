<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\Stock;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Module\ModuleListInterface;
use MageOS\Seo\Model\Product\Stock\CatalogInventoryStockReader;
use MageOS\Seo\Model\Product\Stock\MsiStockReader;
use MageOS\Seo\Model\Product\Stock\StockReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MSI is optional: its stock index is read while MSI is installed and enabled, CatalogInventory's
 * otherwise.
 */
class StockReaderTest extends TestCase
{
    public function testMsisStockIsReadWhileItsModulesAreInstalledAndEnabled(): void
    {
        $rows = ['a' => ['salable' => true, 'quantity' => 1.0]];
        $msi  = $this->createMock(MsiStockReader::class);
        $msi->expects($this->once())->method('read')->willReturn($rows);
        $msi->expects($this->once())->method('getCurrentStockId')->willReturn(2);
        $catalogInventory = $this->createMock(CatalogInventoryStockReader::class);
        $catalogInventory->expects($this->never())->method('read');
        $catalogInventory->expects($this->never())->method('getCurrentStockId');

        $reader = new StockReader(
            $this->moduleList(['Magento_InventorySales', 'Magento_InventoryIndexer']),
            $catalogInventory,
            $msi
        );

        $this->assertSame($rows, $reader->read([$this->createStub(ProductInterface::class)]));
        $this->assertSame(2, $reader->getCurrentStockId());
    }

    /**
     * @dataProvider withoutMsi
     * @param string[] $modules The installed and enabled modules
     * @return void
     */
    #[DataProvider('withoutMsi')]
    public function testCatalogInventorysStockIsReadWithoutThem(array $modules): void
    {
        $rows = ['a' => ['salable' => false, 'quantity' => 0.0]];
        $msi  = $this->createMock(MsiStockReader::class);
        $msi->expects($this->never())->method('read');
        $msi->expects($this->never())->method('getCurrentStockId');
        $catalogInventory = $this->createMock(CatalogInventoryStockReader::class);
        $catalogInventory->expects($this->once())->method('read')->willReturn($rows);
        $catalogInventory->expects($this->once())->method('getCurrentStockId')->willReturn(1);

        $reader = new StockReader($this->moduleList($modules), $catalogInventory, $msi);

        $this->assertSame($rows, $reader->read([$this->createStub(ProductInterface::class)]));
        $this->assertSame(1, $reader->getCurrentStockId());
    }

    /**
     * @return array<string, array{string[]}>
     */
    public static function withoutMsi(): array
    {
        return [
            'neither module'          => [[]],
            'no InventoryIndexer'     => [['Magento_InventorySales']],
            'no InventorySales'       => [['Magento_InventoryIndexer']],
            // It declares the interfaces, but implements neither.
            'only InventorySalesApi'  => [['Magento_InventorySalesApi']],
        ];
    }

    /**
     * A module list that has the modules given, and no other.
     *
     * @param string[] $modules
     * @return ModuleListInterface
     */
    private function moduleList(array $modules): ModuleListInterface
    {
        $moduleList = $this->createStub(ModuleListInterface::class);
        $moduleList->method('getOne')->willReturnCallback(
            static fn (string $name): ?array => \in_array($name, $modules, true) ? ['name' => $name] : null
        );

        return $moduleList;
    }
}
