<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Product\Stock;

use Magento\Bundle\Test\Fixture\Link as BundleSelectionFixture;
use Magento\Bundle\Test\Fixture\Option as BundleOptionFixture;
use Magento\Bundle\Test\Fixture\Product as BundleProductFixture;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\CatalogInventory\Model\Spi\StockRegistryProviderInterface;
use Magento\CatalogInventory\Model\Stock;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableProductFixture;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\ModuleListInterface;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use MageOS\Seo\Model\Product\Stock\CatalogInventoryStockReader;
use MageOS\Seo\Model\Product\Stock\MsiStockReader;
use MageOS\Seo\Model\Product\Stock\StockReader;
use MageOS\Seo\Test\Integration\Fixture\ProductAttribute as AttributeFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * MSI is optional: without it, availability is read from CatalogInventory's stock index. For a
 * website on the default stock that is the table MSI reads too, so both readers must give the same
 * rows, and the resolver the same availability, for every kind of product.
 *
 * Database isolation is off so the saves' commit callbacks run, and with them the stock indexer.
 * The fixtures remove themselves.
 *
 * The comparisons with MSI need MSI installed: they carry the msi group, for a run without it to
 * exclude. The rest of the class reads CatalogInventory alone.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
#[
    DataFixture(ProductFixture::class, as: 'onHand'),
    DataFixture(
        ProductFixture::class,
        ['extension_attributes' => ['stock_item' => [
            'qty' => 0, 'is_in_stock' => true, 'backorders' => 1, 'use_config_backorders' => false,
        ]]],
        'backorder'
    ),
    DataFixture(
        ProductFixture::class,
        ['extension_attributes' => ['stock_item' => ['qty' => 0, 'is_in_stock' => false]]],
        'soldOut'
    ),
    DataFixture(
        ProductFixture::class,
        ['extension_attributes' => ['stock_item' => [
            'qty' => 0, 'manage_stock' => false, 'use_config_manage_stock' => false,
        ]]],
        'unmanaged'
    ),
    // MSI reads by SKU, CatalogInventory by ID: a SKU that looks numeric, and one in mixed case.
    DataFixture(ProductFixture::class, ['sku' => '0123987'], 'numeric'),
    DataFixture(ProductFixture::class, ['sku' => 'Parity-MixedCase-%uniqid%'], 'mixedCase'),
    // Types without a quantity of their own: a configurable and a bundle.
    DataFixture(AttributeFixture::class, ['options' => ['One', 'Two']], 'attribute'),
    DataFixture(ProductFixture::class, as: 'variantOnHand'),
    DataFixture(
        ProductFixture::class,
        ['extension_attributes' => ['stock_item' => ['qty' => 0, 'is_in_stock' => false]]],
        'variantSoldOut'
    ),
    DataFixture(
        ConfigurableProductFixture::class,
        ['_options' => ['$attribute$'], '_links' => ['$variantOnHand$', '$variantSoldOut$']],
        'configurable'
    ),
    DataFixture(ProductFixture::class, as: 'selection'),
    DataFixture(BundleSelectionFixture::class, ['sku' => '$selection.sku$'], 'link'),
    DataFixture(BundleOptionFixture::class, ['product_links' => ['$link$']], 'option'),
    DataFixture(BundleProductFixture::class, ['_options' => ['$option$']], 'bundle'),
]
class ReaderParityTest extends TestCase
{
    /**
     * Each fixture's availability.
     */
    private const EXPECTED = [
        'onHand'         => AvailabilityResolver::IN_STOCK,
        'backorder'      => AvailabilityResolver::BACKORDER,
        'soldOut'        => AvailabilityResolver::OUT_OF_STOCK,
        'unmanaged'      => AvailabilityResolver::IN_STOCK,
        'numeric'        => AvailabilityResolver::IN_STOCK,
        'mixedCase'      => AvailabilityResolver::IN_STOCK,
        'variantOnHand'  => AvailabilityResolver::IN_STOCK,
        'variantSoldOut' => AvailabilityResolver::OUT_OF_STOCK,
        'configurable'   => AvailabilityResolver::IN_STOCK,
        'selection'      => AvailabilityResolver::IN_STOCK,
        'bundle'         => AvailabilityResolver::IN_STOCK,
    ];

    /**
     * @group msi
     * @return void
     */
    #[Group('msi')]
    public function testBothReadersGiveTheSameRows(): void
    {
        $products = $this->products();

        $fromMsi = Bootstrap::getObjectManager()->create(MsiStockReader::class)->read($products);
        $fromCatalogInventory = $this->catalogInventory()->read($products);
        ksort($fromMsi);
        ksort($fromCatalogInventory);

        $this->assertCount(\count($products), $fromMsi, 'Every product has a row in the stock index.');
        $this->assertSame($fromMsi, $fromCatalogInventory);
    }

    /**
     * @group msi
     * @return void
     */
    #[Group('msi')]
    public function testTheResolverGivesTheSameAvailabilityThroughEitherReader(): void
    {
        $readers = [
            'MSI'              => Bootstrap::getObjectManager()->create(MsiStockReader::class),
            'CatalogInventory' => $this->catalogInventory(),
        ];
        foreach ($readers as $name => $reader) {
            $resolver = Bootstrap::getObjectManager()->create(AvailabilityResolver::class, ['stockReader' => $reader]);

            $this->assertSame($this->expected(), $this->sorted($resolver->resolveMany($this->products())), $name);
        }
    }

    /**
     * The installation's own StockReader, with MSI's InventorySales missing: CatalogInventory's stock.
     *
     * @return void
     */
    public function testWithoutMsiTheResolverReadsCatalogInventory(): void
    {
        $moduleList = $this->createStub(ModuleListInterface::class);
        $moduleList->method('getOne')->willReturnCallback(
            static fn (string $name): ?array => $name === 'Magento_InventorySales' ? null : ['name' => $name]
        );
        // The fixtures' saves may have cached stock statuses read before the indexer ran; a
        // storefront request starts with none.
        Bootstrap::getObjectManager()->get(StockRegistryStorage::class)->clean();
        $reader = Bootstrap::getObjectManager()->create(StockReader::class, ['moduleList' => $moduleList]);
        $resolver = Bootstrap::getObjectManager()->create(AvailabilityResolver::class, ['stockReader' => $reader]);

        $this->assertSame(Stock::DEFAULT_STOCK_ID, $resolver->getCurrentStockId());
        $this->assertSame($this->expected(), $this->sorted($resolver->resolveMany($this->products())));
    }

    /**
     * In the frontend, the CatalogInventory reader answers from core's request cache: stock core
     * cached when it loaded the product, as it does for a product page's own product, costs no query.
     *
     * What the reader reads itself goes into that cache through core's StockRegistryPreloader, which
     * the integration framework's TestModuleCatalogInventoryCache stops caching (so preloaded stock
     * can't leak from one test into the next); the unit test covers that hand-over.
     *
     * @return void
     */
    public function testOnTheStorefrontStockCoreHasCachedCostsNoQuery(): void
    {
        Bootstrap::getObjectManager()->get(StockRegistryStorage::class)->clean();
        $products = [$this->fixture('onHand'), $this->fixture('soldOut')];
        // What a product load does without MSI: AddInventoryDataObserver, through Helper\Stock.
        foreach ($products as $product) {
            Bootstrap::getObjectManager()->get(StockRegistryProviderInterface::class)
                ->getStockStatus((int) $product->getId(), 0);
        }
        $reader   = Bootstrap::getObjectManager()->create(CatalogInventoryStockReader::class);
        $profiler = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection()->getProfiler();

        $profiler->clear();
        $profiler->setEnabled(true);
        try {
            $read    = $reader->read($products);
            $queries = (int) $profiler->getTotalNumQueries();
        } finally {
            $profiler->setEnabled(false);
        }

        $this->assertSame(0, $queries, 'Answered from the request cache.');
        $this->assertSame($this->catalogInventory()->read($products), $read, 'The rows the table has.');
    }

    /**
     * A reader of CatalogInventory's stock straight from the table, not through the request cache.
     *
     * @return CatalogInventoryStockReader
     */
    private function catalogInventory(): CatalogInventoryStockReader
    {
        return Bootstrap::getObjectManager()->create(CatalogInventoryStockReader::class, ['readThrough' => false]);
    }

    /**
     * Every fixture product.
     *
     * @return ProductInterface[]
     */
    private function products(): array
    {
        return array_map([$this, 'fixture'], array_keys(self::EXPECTED));
    }

    /**
     * Each fixture's expected availability, by SKU, sorted.
     *
     * @return array<string, string>
     */
    private function expected(): array
    {
        $expected = [];
        foreach (self::EXPECTED as $fixture => $availability) {
            $expected[(string) $this->fixture($fixture)->getSku()] = $availability;
        }

        return $this->sorted($expected);
    }

    /**
     * @param array<string, string> $availability
     * @return array<string, string>
     */
    private function sorted(array $availability): array
    {
        ksort($availability);

        return $availability;
    }

    /**
     * @param string $name
     * @return ProductInterface
     */
    private function fixture(string $name): ProductInterface
    {
        return DataFixtureStorageManager::getStorage()->get($name);
    }
}
