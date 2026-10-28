<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Stock;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\InventorySalesApi\Model\GetStockItemsDataInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Stock from MSI's stock index, for the stock the current website sells from.
 *
 * MSI is optional (composer `suggest`), so this class has hidden dependencies, fetched from the
 * object manager when a method runs rather than taken in the constructor:
 * - Magento\InventorySalesApi\Api\StockResolverInterface, implemented by Magento_InventorySales;
 * - Magento\InventorySalesApi\Model\GetStockItemsDataInterface, implemented by
 *   Magento_InventoryIndexer;
 * - Magento\InventorySalesApi\Api\Data\SalesChannelInterface, for its TYPE_WEBSITE constant.
 *
 * Why: `setup:di:compile` reflects the class of every constructor parameter of every enabled
 * module, and fails on a class that does not exist. With MSI's packages removed (a composer
 * `replace`), a constructor naming them would stop this module compiling. A name in a method body is
 * resolved only when the method runs, and StockReader calls this reader only while both modules are
 * registered and enabled. Test\Unit\Model\Product\Stock\MsiCompileSafetyTest keeps it that way.
 * Mollie's payment module reaches its optional MSI dependency the same way.
 *
 * get(), not create(): the shared instance through the area's preference, as constructor injection
 * gives it. On the storefront that is InventoryIndexer's GetStockItemsDataCache, which the product
 * page's own product load has already filled, so a product page costs no query here. Elsewhere it is
 * the uncached read: one query for any number of products.
 */
class MsiStockReader implements StockReaderInterface, ResetAfterRequestInterface
{
    /**
     * @var array<string, int> website code => stock ID
     */
    private array $stockIds = [];

    /**
     * @param StoreManagerInterface $storeManager
     * @param ObjectManagerInterface $objectManager MSI's services, which may not exist (see above)
     */
    public function __construct(
        private readonly StoreManagerInterface  $storeManager,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * @inheritdoc
     */
    public function read(array $products): array
    {
        // SKUs that look numeric become integer keys; MSI gets them as the strings they are, or
        // MySQL would compare "0123" with 123 as numbers.
        $skus = [];
        foreach ($products as $product) {
            $sku = (string) $product->getSku();
            if ($sku !== '') {
                $skus[$sku] = $sku;
            }
        }
        if ($skus === []) {
            return [];
        }
        $skus = array_map('strval', array_values($skus));

        $rows  = $this->objectManager->get(GetStockItemsDataInterface::class)
            ->execute($skus, $this->getCurrentStockId());
        $stock = [];
        foreach ($skus as $sku) {
            $row = $rows[$sku] ?? null;
            if (\is_array($row)) {
                $stock[$sku] = [
                    'salable'  => (bool) ($row[GetStockItemsDataInterface::IS_SALABLE] ?? false),
                    'quantity' => (float) ($row[GetStockItemsDataInterface::QUANTITY] ?? 0),
                ];
            }
        }

        return $stock;
    }

    /**
     * The MSI stock serving the current website (memoised per website).
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @return int
     */
    public function getCurrentStockId(): int
    {
        $websiteCode = (string) $this->storeManager->getWebsite()->getCode();
        if (!isset($this->stockIds[$websiteCode])) {
            $this->stockIds[$websiteCode] = (int) $this->objectManager->get(StockResolverInterface::class)
                ->execute(SalesChannelInterface::TYPE_WEBSITE, $websiteCode)
                ->getStockId();
        }

        return $this->stockIds[$websiteCode];
    }

    /**
     * Drop the memoised stock IDs.
     *
     * Between requests (worker mode) and between queue messages (Model\Rebuild\BuildFreshness).
     *
     * @return void
     */
    public function _resetState(): void // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- framework interface
    {
        $this->stockIds = [];
    }
}
