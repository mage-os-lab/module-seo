<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Stock;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * Where Model\Product\AvailabilityResolver reads stock: each product's salability and quantity on
 * the stock the current website sells from.
 *
 * StockReader, the preference, reads MSI's stock index while MSI is installed and enabled
 * (MsiStockReader), and CatalogInventory's otherwise (CatalogInventoryStockReader). A module with a
 * stock source of its own can declare a preference for this interface. It is not `@api`: its shape
 * may change in any release.
 */
interface StockReaderInterface
{
    /**
     * Salability and quantity of each product the stock index has a row for, by SKU.
     *
     * A product without a row is left out, and so is a product without a SKU: the resolver reads
     * both as out of stock.
     *
     * @param ProductInterface[] $products
     * @throws \Exception When the stock cannot be read
     * @return array<string, array{salable: bool, quantity: float}>
     */
    public function read(array $products): array;

    /**
     * The stock the current website sells from.
     *
     * @throws \Exception When it cannot be resolved
     * @return int
     */
    public function getCurrentStockId(): int;
}
