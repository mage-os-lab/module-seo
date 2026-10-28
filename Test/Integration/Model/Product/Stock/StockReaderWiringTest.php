<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Product\Stock;

use Magento\Framework\Module\ModuleListInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Product\Stock\CatalogInventoryStockReader;
use MageOS\Seo\Model\Product\Stock\MsiStockReader;
use MageOS\Seo\Model\Product\Stock\StockReader;
use MageOS\Seo\Model\Product\Stock\StockReaderInterface;
use MageOS\Seo\Model\Rebuild\BuildFreshness;
use PHPUnit\Framework\TestCase;

/**
 * Where availability reads stock outside the storefront, where the feeds and sitemaps are built.
 *
 * @magentoAppArea crontab
 */
class StockReaderWiringTest extends TestCase
{
    /**
     * @return void
     */
    public function testStockIsReadThroughTheReaderThatChooses(): void
    {
        $this->assertInstanceOf(
            StockReader::class,
            Bootstrap::getObjectManager()->get(StockReaderInterface::class)
        );
    }

    /**
     * MSI's reader while MSI is installed and enabled, as it is in a full installation; else
     * CatalogInventory's.
     *
     * @return void
     */
    public function testTheReaderChosenIsThisInstallationsStock(): void
    {
        $moduleList = Bootstrap::getObjectManager()->get(ModuleListInterface::class);
        $expected   = $moduleList->getOne('Magento_InventorySales') !== null
            && $moduleList->getOne('Magento_InventoryIndexer') !== null
                ? MsiStockReader::class
                : CatalogInventoryStockReader::class;

        $chosen = (new \ReflectionMethod(StockReader::class, 'reader'))
            ->invoke(Bootstrap::getObjectManager()->get(StockReaderInterface::class));

        $this->assertInstanceOf($expected, $chosen);
    }

    /**
     * Outside the storefront CatalogInventory's stock is read from the table: a queue consumer keeps
     * core's request cache from one message to the next.
     *
     * @return void
     */
    public function testCatalogInventoryIsNotReadThroughCoresRequestCacheHere(): void
    {
        $reader      = Bootstrap::getObjectManager()->create(CatalogInventoryStockReader::class);
        $readThrough = new \ReflectionProperty(CatalogInventoryStockReader::class, 'readThrough');

        $this->assertFalse($readThrough->getValue($reader));
    }

    /**
     * A long-running rebuild consumer forgets MSI's stock IDs before each build, as the resolver
     * did when it kept them.
     *
     * @return void
     */
    public function testARebuildResetsMsisStockIds(): void
    {
        $services = (new \ReflectionProperty(BuildFreshness::class, 'services'))
            ->getValue(Bootstrap::getObjectManager()->get(BuildFreshness::class));

        $this->assertContains(
            MsiStockReader::class,
            array_map(static fn (object $service): string => $service::class, $services)
        );
    }
}
