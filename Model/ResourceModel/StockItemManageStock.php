<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel;

/**
 * Whether stock is managed, for a list of SKUs in one query.
 *
 * Read from the stock item, where MSI keeps it too: InventoryConfiguration's
 * GetStockItemConfiguration reads the same row, one SKU at a time. AvailabilityResolver needs it
 * only for products the stock index has as salable at zero or below, to tell a backorder from a
 * product whose stock isn't managed. MSI has no bulk read of it on Magento 2.4.7.
 */
class StockItemManageStock extends AbstractConnectedResource
{
    /**
     * Initialize the main table and primary key.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('cataloginventory_stock_item', 'item_id');
    }

    /**
     * Manage stock per SKU.
     *
     * True or false as the stock item has it, null where it follows the configuration. A SKU without
     * a stock item is left out.
     *
     * @param string[] $skus
     * @param int $websiteId The stock configuration's default scope (0)
     * @return array<string, bool|null>
     */
    public function forSkus(array $skus, int $websiteId): array
    {
        if ($skus === []) {
            return [];
        }

        $connection = $this->connection();
        $select     = $connection->select()
            ->from(['item' => $this->getMainTable()], ['use_config_manage_stock', 'manage_stock'])
            ->join(
                ['product' => $this->getTable('catalog_product_entity')],
                'product.entity_id = item.product_id',
                ['sku']
            )
            ->where('product.sku IN (?)', array_values($skus))
            ->where('item.website_id = ?', $websiteId);

        $managed = [];
        foreach ($connection->fetchAll($select) as $row) {
            $managed[(string) $row['sku']] = (bool) $row['use_config_manage_stock']
                ? null
                : (bool) $row['manage_stock'];
        }

        return $managed;
    }
}
