<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\InventorySalesApi\Model\GetStockItemsDataInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\ResourceModel\StockItemManageStock;
use Psr\Log\LoggerInterface;

/**
 * The schema.org availability of products on the current website's stock: in stock, on backorder,
 * or out of stock.
 *
 * Read as the storefront reads it. In the frontend and GraphQL areas MSI answers "salable" from the
 * stock index alone (InventoryIndexer's IsProductSalable and AreMultipleProductsSalable replace the
 * condition chain there), without reservations, so the markup says what the page says. The index
 * has a product's salability and quantity; quantity tells stock on hand from stock only on
 * backorder:
 * - not salable, or not in the index → OutOfStock;
 * - a product type without a quantity of its own (configurable, bundle, grouped) → InStock: salable
 *   means a child can be bought. Model\Product\OfferBuilder derives a configurable's own
 *   availability from its children where they are loaded;
 * - quantity above zero → InStock;
 * - salable at zero or below → BackOrder when stock is managed, InStock when it isn't.
 *
 * resolveMany() costs one stock-index query for any number of products
 * (GetStockItemsDataInterface, MSI's bulk read), and one more for whether stock is managed, only
 * when some product is salable at zero or below (Model\ResourceModel\StockItemManageStock). On the
 * storefront the index read comes from MSI's request cache, which product collections warm.
 * Before, every product cost MSI's per-SKU condition chain: about six queries each in a feed build.
 *
 * resolve() answers one product from what prefetch() loaded, or as a batch of one.
 * Model\Product\Variant\ProductGroupBuilder prefetches a configurable and its variants together.
 */
class AvailabilityResolver implements ResetAfterRequestInterface
{
    public const IN_STOCK     = 'https://schema.org/InStock';
    public const OUT_OF_STOCK = 'https://schema.org/OutOfStock';
    public const BACKORDER    = 'https://schema.org/BackOrder';

    /**
     * Most available first: the order best() ranks by.
     */
    private const RANK = [self::IN_STOCK, self::BACKORDER, self::OUT_OF_STOCK];

    /**
     * @var array<string, int> website code => stock ID
     */
    private array $stockIds = [];

    /**
     * @var array<string, string> "stock ID:SKU" => availability, from prefetch()
     */
    private array $prefetched = [];

    /**
     * @param StoreManagerInterface $storeManager
     * @param StockResolverInterface $stockResolver
     * @param GetStockItemsDataInterface $getStockItemsData
     * @param IsSourceItemManagementAllowedForProductTypeInterface $hasOwnQuantity
     * @param StockItemManageStock $manageStock
     * @param StockConfigurationInterface $stockConfiguration
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly StoreManagerInterface                                $storeManager,
        private readonly StockResolverInterface                               $stockResolver,
        private readonly GetStockItemsDataInterface                           $getStockItemsData,
        private readonly IsSourceItemManagementAllowedForProductTypeInterface $hasOwnQuantity,
        private readonly StockItemManageStock                                 $manageStock,
        private readonly StockConfigurationInterface                          $stockConfiguration,
        private readonly LoggerInterface                                      $logger
    ) {
    }

    /**
     * The availability of one product.
     *
     * From what prefetch() loaded, or looked up as a batch of one. A lookup that fails is logged
     * with the SKU and answers OutOfStock: the page still renders, and the log says why.
     *
     * @param ProductInterface $product
     * @return string
     */
    public function resolve(ProductInterface $product): string
    {
        $sku = (string) $product->getSku();
        if ($sku === '') {
            return self::OUT_OF_STOCK;
        }

        try {
            $key = $this->getCurrentStockId() . ':' . $sku;

            return $this->prefetched[$key] ?? ($this->resolveMany([$product])[$sku] ?? self::OUT_OF_STOCK);
        } catch (\Exception $e) {
            $this->logger->error(
                \sprintf('MageOS_Seo: could not read the stock of product "%s": %s', $sku, $e->getMessage()),
                ['exception' => $e]
            );

            return self::OUT_OF_STOCK;
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
        $skus      = array_map('strval', array_keys($bySku));
        $stockData = $this->getStockItemsData->execute($skus, $this->getCurrentStockId());

        $availability = [];
        $atOrBelow    = [];
        foreach ($skus as $sku) {
            $row = $stockData[$sku] ?? null;
            if (!\is_array($row) || !(bool) ($row[GetStockItemsDataInterface::IS_SALABLE] ?? false)) {
                $availability[$sku] = self::OUT_OF_STOCK;
            } elseif (!$this->hasOwnQuantity->execute((string) $bySku[$sku]->getTypeId())
                || (float) ($row[GetStockItemsDataInterface::QUANTITY] ?? 0) > 0
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
     * A lookup that fails is logged once, and the products answer OutOfStock: a configurable's
     * variants must not each fail and log again.
     *
     * @param ProductInterface[] $products
     * @return void
     */
    public function prefetch(array $products): void
    {
        try {
            $stockId = $this->getCurrentStockId();
            // Only what isn't loaded yet: a configurable's offer has already loaded its children by
            // the time ProductGroupBuilder asks for the same products.
            $products = array_filter(
                $products,
                fn (ProductInterface $product): bool => !isset($this->prefetched[$stockId . ':' . $product->getSku()])
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
            $stockId = $this->stockIds[(string) $this->storeManager->getWebsite()->getCode()] ?? 0;
            foreach ($skus as $sku) {
                $this->prefetched[$stockId . ':' . $sku] = self::OUT_OF_STOCK;
            }
        }
    }

    /**
     * The most available of several products.
     *
     * InStock if any is, else BackOrder if any is, else OutOfStock (also for none).
     *
     * @param ProductInterface[] $products
     * @return string
     */
    public function best(array $products): string
    {
        $this->prefetch($products);
        $found = array_map(fn (ProductInterface $product): string => $this->resolve($product), $products);
        foreach (self::RANK as $availability) {
            if (\in_array($availability, $found, true)) {
                return $availability;
            }
        }

        return self::OUT_OF_STOCK;
    }

    /**
     * Resolve the MSI stock ID serving the current website (memoised per website).
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @return int
     */
    public function getCurrentStockId(): int
    {
        $websiteCode = (string) $this->storeManager->getWebsite()->getCode();
        if (!isset($this->stockIds[$websiteCode])) {
            $this->stockIds[$websiteCode] = (int) $this->stockResolver
                ->execute(SalesChannelInterface::TYPE_WEBSITE, $websiteCode)
                ->getStockId();
        }

        return $this->stockIds[$websiteCode];
    }

    /**
     * Drop the memoised stock IDs and prefetched availability.
     *
     * Between requests (worker mode) and between queue messages (Model\Rebuild\BuildFreshness).
     *
     * @return void
     */
    public function _resetState(): void // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- framework interface
    {
        $this->stockIds   = [];
        $this->prefetched = [];
    }
}
