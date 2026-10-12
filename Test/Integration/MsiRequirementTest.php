<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration;

use Magento\Framework\Module\DependencyChecker;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Review M3, decision D1b: this module requires MSI, and says so where it is enforced.
 *
 * Availability is read from MSI's stock index through three interfaces whose implementations live
 * in Magento_InventorySales, Magento_InventoryIndexer and Magento_InventoryConfiguration. Without
 * them nothing can build the availability resolver: product pages lose their structured data and
 * every rebuild fails. The module required only the two `-api` packages, so the modules that
 * implement them could be disabled underneath it.
 *
 * `bin/magento module:disable` refuses when an enabled module's composer.json requires the module
 * being disabled; this asks the same check, without disabling anything.
 */
class MsiRequirementTest extends TestCase
{
    /**
     * @dataProvider msiModules
     * @param string $module
     * @return void
     */
    #[DataProvider('msiModules')]
    public function testAnMsiModuleSeoNeedsCannotBeDisabledUnderneathIt(string $module): void
    {
        $dependents = Bootstrap::getObjectManager()->get(DependencyChecker::class)
            ->checkDependenciesWhenDisableModules([$module]);

        $this->assertArrayHasKey('MageOS_Seo', $dependents[$module] ?? []);
    }

    /**
     * The modules that implement the interfaces the availability resolver takes.
     *
     * @return array<string, array{string}>
     */
    public static function msiModules(): array
    {
        return [
            'StockResolverInterface'                                => ['Magento_InventorySales'],
            'GetStockItemsDataInterface'                            => ['Magento_InventoryIndexer'],
            'IsSourceItemManagementAllowedForProductTypeInterface'  => ['Magento_InventoryConfiguration'],
        ];
    }
}
