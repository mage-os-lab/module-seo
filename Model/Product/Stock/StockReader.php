<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Stock;

use Magento\Framework\Module\ModuleListInterface;

/**
 * The stock reader for this installation: MSI's stock index while MSI is installed and enabled,
 * CatalogInventory's otherwise.
 *
 * MSI is Magento_InventorySales and Magento_InventoryIndexer, which implement the two interfaces
 * MsiStockReader reads through. Magento_InventorySalesApi declares them, but can be enabled while
 * neither is.
 *
 * "Installed and enabled" is ModuleListInterface::getOne(): registered with the component registrar
 * and enabled in config.php. ModuleManager::isEnabled() reads config.php alone, and says yes for a
 * package composer has removed while config.php still lists it. Both are lookups in the module list
 * already loaded at bootstrap: choosing costs no query.
 */
class StockReader implements StockReaderInterface
{
    /**
     * The modules MsiStockReader needs.
     */
    private const MSI_MODULES = ['Magento_InventorySales', 'Magento_InventoryIndexer'];

    /**
     * @param ModuleListInterface $moduleList
     * @param CatalogInventoryStockReader $catalogInventory
     * @param MsiStockReader $msi
     */
    public function __construct(
        private readonly ModuleListInterface         $moduleList,
        private readonly CatalogInventoryStockReader $catalogInventory,
        private readonly MsiStockReader              $msi
    ) {
    }

    /**
     * @inheritdoc
     */
    public function read(array $products): array
    {
        return $this->reader()->read($products);
    }

    /**
     * @inheritdoc
     */
    public function getCurrentStockId(): int
    {
        return $this->reader()->getCurrentStockId();
    }

    /**
     * MSI's reader when every module it needs is installed and enabled, else CatalogInventory's.
     *
     * @return StockReaderInterface
     */
    private function reader(): StockReaderInterface
    {
        foreach (self::MSI_MODULES as $module) {
            if ($this->moduleList->getOne($module) === null) {
                return $this->catalogInventory;
            }
        }

        return $this->msi;
    }
}
