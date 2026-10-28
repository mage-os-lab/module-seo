<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Stock;

use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Model\Stock;
use Magento\CatalogInventory\Model\StockRegistryPreloader;
use Magento\CatalogInventory\Model\StockRegistryPreloaderFactory;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\CatalogInventory\Model\StockRegistryStorageFactory;

/**
 * Stock from CatalogInventory's stock index: the one stock a store without MSI has.
 *
 * The index is cataloginventory_stock_status, the table MSI's own GetStockItemsData reads for its
 * default stock, so a website on the default stock gets the same rows from either reader. It is read
 * by product ID, at the default scope, the only scope CatalogInventory's indexer writes, through
 * core's StockRegistryPreloader: one query for any number of products.
 *
 * With $readThrough (true in etc/frontend/di.xml) the reader answers from StockRegistryStorage, the
 * request cache core fills when it loads a product (CatalogInventory's AddInventoryDataObserver), and
 * the preloader adds what it reads there. A product page then costs no query for its own product.
 * Elsewhere the reader reads the table, through a preloader with a storage of its own that is dropped
 * after the read, for two reasons. A queue consumer keeps the shared storage from one message to the
 * next, and Model\Rebuild\BuildFreshness does not reset it, so a rebuild could read stock cached
 * before the change that queued it. And a feed build would hold a stock status for every product in
 * the catalogue. MSI makes the same split: its request cache is set up for the storefront areas only.
 *
 * Not mirrored from MSI: when no product of a batch has a row, MSI's default-stock read falls back to
 * the stock items (for disabled products). Here a product without a row has no stock.
 */
class CatalogInventoryStockReader implements StockReaderInterface
{
    /**
     * @param StockRegistryPreloader $preloader Core's, into the shared request cache
     * @param StockRegistryPreloaderFactory $preloaderFactory
     * @param StockRegistryStorageFactory $storageFactory
     * @param StockRegistryStorage $registryStorage Core's request cache of stock statuses
     * @param StockConfigurationInterface $stockConfiguration
     * @param bool $readThrough Answer from, and add to, the shared request cache (storefront only)
     */
    public function __construct(
        private readonly StockRegistryPreloader        $preloader,
        private readonly StockRegistryPreloaderFactory $preloaderFactory,
        private readonly StockRegistryStorageFactory   $storageFactory,
        private readonly StockRegistryStorage          $registryStorage,
        private readonly StockConfigurationInterface   $stockConfiguration,
        private readonly bool                          $readThrough = false
    ) {
    }

    /**
     * @inheritdoc
     *
     * A product without an ID has no row. One query for the products not in the request cache, or
     * none when all of them are.
     */
    public function read(array $products): array
    {
        $skuById = [];
        foreach ($products as $product) {
            $sku = (string) $product->getSku();
            $id  = (int) $product->getId();
            if ($sku !== '' && $id > 0) {
                $skuById[$id] = $sku;
            }
        }
        if ($skuById === []) {
            return [];
        }

        $scopeId  = (int) $this->stockConfiguration->getDefaultScopeId();
        $statuses = [];
        $missing  = [];
        foreach (array_keys($skuById) as $id) {
            $status = $this->readThrough ? $this->registryStorage->getStockStatus($id, $scopeId) : null;
            if ($status instanceof StockStatusInterface) {
                $statuses[$id] = $status;
            } else {
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            $preloader = $this->readThrough
                ? $this->preloader
                : $this->preloaderFactory->create(['stockRegistryStorage' => $this->storageFactory->create()]);
            foreach ($preloader->preloadStockStatuses($missing, $scopeId) as $status) {
                $id = (int) $status->getProductId();
                if (isset($skuById[$id])) {
                    $statuses[$id] = $status;
                }
            }
        }

        $stock = [];
        foreach ($skuById as $id => $sku) {
            if (isset($statuses[$id])) {
                $stock[(string) $sku] = [
                    'salable'  => (bool) $statuses[$id]->getStockStatus(),
                    'quantity' => (float) $statuses[$id]->getQty(),
                ];
            }
        }

        return $stock;
    }

    /**
     * CatalogInventory's single stock.
     *
     * @return int
     */
    public function getCurrentStockId(): int
    {
        return Stock::DEFAULT_STOCK_ID;
    }
}
