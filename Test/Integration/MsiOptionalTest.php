<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration;

use Magento\Framework\Module\DependencyChecker;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MSI is optional: composer suggests it, and without it availability is read from CatalogInventory
 * (Model\Product\Stock\StockReader). So a store may switch MSI off while this module is enabled.
 *
 * `bin/magento module:disable` refuses when an enabled module's composer.json requires the module
 * being disabled; this asks the same check, without disabling anything. It replaces the check that
 * MSI was required (review M3, decision D1b), which made the opposite assertion.
 */
class MsiOptionalTest extends TestCase
{
    /**
     * @dataProvider msiModules
     * @param string $module
     * @return void
     */
    #[DataProvider('msiModules')]
    public function testMsiCanBeDisabledUnderneathThisModule(string $module): void
    {
        $dependents = Bootstrap::getObjectManager()->get(DependencyChecker::class)
            ->checkDependenciesWhenDisableModules([$module]);

        $this->assertArrayNotHasKey('MageOS_Seo', $dependents[$module] ?? []);
    }

    /**
     * The MSI modules this module read stock through, or once required.
     *
     * @return array<string, array{string}>
     */
    public static function msiModules(): array
    {
        return [
            'InventorySales'            => ['Magento_InventorySales'],
            'InventorySalesApi'         => ['Magento_InventorySalesApi'],
            'InventoryIndexer'          => ['Magento_InventoryIndexer'],
            'InventoryConfiguration'    => ['Magento_InventoryConfiguration'],
            'InventoryConfigurationApi' => ['Magento_InventoryConfigurationApi'],
        ];
    }
}
