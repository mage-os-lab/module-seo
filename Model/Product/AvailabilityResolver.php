<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use MageOS\Seo\Api\Product\AvailabilityResolverInterface;
use MageOS\Seo\Model\Product\Stock\StockReaderInterface;
use MageOS\Seo\Model\ResourceModel\StockItemManageStock;
use Psr\Log\LoggerInterface;

/**
 * The schema.org availability of products on the current website's stock: in stock, on backorder,
 * or out of stock.
 *
 * Read as the storefront reads it: from the stock index, through Model\Product\Stock\StockReader.
 * While MSI is installed and enabled that is MSI's index, for the website's stock. In the frontend
 * and GraphQL areas MSI answers "salable" from it alone (InventoryIndexer's IsProductSalable and
 * AreMultipleProductsSalable replace the condition chain there), without reservations, so the
 * markup says what the page says. Without MSI it is CatalogInventory's index, the table MSI reads
 * for its default stock too. The index has a product's salability and quantity; quantity tells stock
 * on hand from stock only on backorder:
 * - not salable, or not in the index → OutOfStock;
 * - a product type without a quantity of its own (configurable, bundle, grouped) → InStock: salable
 *   means a child can be bought. Model\Product\OfferBuilder derives a configurable's own
 *   availability from its children where they are loaded. Which types have one is `is_qty` in
 *   product_types.xml, read through CatalogInventory's StockConfigurationInterface::getIsQtyTypeIds(),
 *   as MSI's IsSourceItemManagementAllowedForProductType reads it;
 * - quantity above zero → InStock;
 * - salable at zero or below → BackOrder when stock is managed, InStock when it isn't.
 *
 * resolveMany() costs one stock-index query for any number of products, and one more for whether
 * stock is managed, only when some product is salable at zero or below
 * (Model\ResourceModel\StockItemManageStock). On the storefront the index read goes through a
 * request cache: MSI's, which the product page's own product load fills, its variants included; or,
 * without MSI, CatalogInventory's StockRegistryStorage, which a product load fills for that product.
 * Before, every product cost MSI's per-SKU condition chain: about six queries each in a feed build.
 *
 * resolve() answers one product from what prefetch() loaded, or as a batch of one.
 * Model\Product\Variant\ProductGroupBuilder prefetches a configurable and its variants together.
 *
 * MSI is optional: composer suggests it, and StockReader reads CatalogInventory's index while
 * Magento_InventorySales or Magento_InventoryIndexer is missing or disabled.
 */
class AvailabilityResolver implements AvailabilityResolverInterface, ResetAfterRequestInterface
{
    /**
     * Known to be sellable, most available first: the order best() ranks by.
     */
    private const SELLABLE = [self::IN_STOCK, self::BACKORDER];

    /**
     * Availability from prefetch(), by "stock ID:SKU"; null where its lookup failed.
     *
     * @var array<string, string|null>
     */
    private array $prefetched = [];

    /**
     * @param StockReaderInterface $stockReader
     * @param StockItemManageStock $manageStock
     * @param StockConfigurationInterface $stockConfiguration
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly StockReaderInterface        $stockReader,
        private readonly StockItemManageStock        $manageStock,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly LoggerInterface             $logger
    ) {
    }

    /**
     * The availability of one product, or null when its stock could not be read.
     *
     * From what prefetch() loaded, or looked up as a batch of one. A lookup that fails is logged
     * with the SKU and answers null: the page still renders, without an availability nothing backs
     * (review low item, decision D8a: it was OutOfStock), and the log says why.
     *
     * @param ProductInterface $product
     * @return string|null
     */
    public function resolve(ProductInterface $product): ?string
    {
        $sku = (string) $product->getSku();
        if ($sku === '') {
            return self::OUT_OF_STOCK;
        }

        try {
            $key = $this->getCurrentStockId() . ':' . $sku;
            if (\array_key_exists($key, $this->prefetched)) {
                return $this->prefetched[$key];
            }

            return $this->resolveMany([$product])[$sku] ?? self::OUT_OF_STOCK;
        } catch (\Exception $e) {
            $this->logger->error(
                \sprintf('MageOS_Seo: could not read the stock of product "%s": %s', $sku, $e->getMessage()),
                ['exception' => $e]
            );

            return null;
        }
    }

    /**
     * The availability of each product, by SKU, in the order given.
     *
     * Products without a SKU are left out. A product the stock index has no row for is
     * OutOfStock. Nothing is remembered: a feed resolves page after page without holding the
     * catalogue.
     *
     * @param ProductInterface[] $products
     * @throws \Exception When the stock cannot be read; the caller decides what that means
     * @return array<string, string> SKU => availability
     */
    public function resolveMany(array $products): array
    {
        $bySku = [];
        foreach ($products as $product) {
            $sku = (string) $product->getSku();
            if ($sku !== '') {
                $bySku[$sku] = $product;
            }
        }
        if ($bySku === []) {
            return [];
        }

        // SKUs that look numeric become integer keys; the lookups get them back as the strings
        // they are, or MySQL would compare "0123" with 123 as numbers.
        $skus           = array_map('strval', array_keys($bySku));
        $stock          = $this->stockReader->read(array_values($bySku));
        $hasOwnQuantity = $this->stockConfiguration->getIsQtyTypeIds();

        $availability = [];
        $atOrBelow    = [];
        foreach ($skus as $sku) {
            $row = $stock[$sku] ?? null;
            if ($row === null || !$row['salable']) {
                $availability[$sku] = self::OUT_OF_STOCK;
            } elseif (!(bool) ($hasOwnQuantity[(string) $bySku[$sku]->getTypeId()] ?? false)
                || $row['quantity'] > 0
            ) {
                $availability[$sku] = self::IN_STOCK;
            } else {
                $availability[$sku] = self::BACKORDER;
                $atOrBelow[]        = $sku;
            }
        }

        if ($atOrBelow !== []) {
            // The configured default as MSI's own ManageStockCondition reads it: MSI has no other
            // API for it, deprecated as CatalogInventory's is.
            $managed  = $this->manageStock->forSkus($atOrBelow, (int) $this->stockConfiguration->getDefaultScopeId());
            $byConfig = (bool) $this->stockConfiguration->getManageStock();
            foreach ($atOrBelow as $sku) {
                if (!($managed[$sku] ?? $byConfig)) {
                    $availability[$sku] = self::IN_STOCK;
                }
            }
        }

        return $availability;
    }

    /**
     * Look up products together, so resolve() answers each of them without another lookup.
     *
     * A lookup that fails is logged once, and the products answer null (decision D8a): a
     * configurable's variants must not each fail and log again.
     *
     * @param ProductInterface[] $products
     * @return void
     */
    public function prefetch(array $products): void
    {
        // Kept for the failure below: asking the reader again could fail as the lookup did.
        $stockId = 0;
        try {
            $stockId = $this->getCurrentStockId();
            // Only what isn't loaded yet: a configurable's offer has already loaded its children by
            // the time ProductGroupBuilder asks for the same products.
            $products = array_filter(
                $products,
                fn (ProductInterface $product): bool
                    => !\array_key_exists($stockId . ':' . $product->getSku(), $this->prefetched)
            );
            if ($products === []) {
                return;
            }
            foreach ($this->resolveMany($products) as $sku => $availability) {
                $this->prefetched[$stockId . ':' . $sku] = $availability;
            }
        } catch (\Exception $e) {
            $skus = array_filter(
                array_map(static fn (ProductInterface $product): string => (string) $product->getSku(), $products)
            );
            $this->logger->error(
                \sprintf(
                    'MageOS_Seo: could not read the stock of products "%s": %s',
                    implode('", "', $skus),
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
            foreach ($skus as $sku) {
                $this->prefetched[$stockId . ':' . $sku] = null;
            }
        }
    }

    /**
     * The most available of several products.
     *
     * InStock if any is, else BackOrder if any is. Otherwise null when some could not be read, as
     * they might be sellable (decision D8a), and OutOfStock when none could be (also for none).
     *
     * @param ProductInterface[] $products
     * @return string|null
     */
    public function best(array $products): ?string
    {
        $this->prefetch($products);
        $found = array_map(fn (ProductInterface $product): ?string => $this->resolve($product), $products);
        foreach (self::SELLABLE as $availability) {
            if (\in_array($availability, $found, true)) {
                return $availability;
            }
        }

        return \in_array(null, $found, true) ? null : self::OUT_OF_STOCK;
    }

    /**
     * The stock serving the current website: MSI's for the website, or CatalogInventory's one stock.
     *
     * @throws \Exception When it cannot be resolved
     * @return int
     */
    public function getCurrentStockId(): int
    {
        return $this->stockReader->getCurrentStockId();
    }

    /**
     * Drop the prefetched availability.
     *
     * Between requests (worker mode) and between queue messages (Model\Rebuild\BuildFreshness).
     * Model\Product\Stock\MsiStockReader keeps, and resets, the stock IDs.
     *
     * @return void
     */
    public function _resetState(): void // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- framework interface
    {
        $this->prefetched = [];
    }
}
