<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use PHPUnit\Framework\TestCase;

/**
 * Issue #30: availability as the storefront's stock index has it, telling stock on hand from stock
 * only on backorder, for real products on the default stock, a batch at a time.
 *
 * Database isolation is off so the saves' commit callbacks run, and with them the stock indexer.
 * The fixtures remove themselves.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class AvailabilityTest extends TestCase
{
    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'onHand')]
    #[DataFixture(
        ProductFixture::class,
        ['extension_attributes' => ['stock_item' => [
            'qty' => 0, 'is_in_stock' => true, 'backorders' => 1, 'use_config_backorders' => false,
        ]]],
        'backorder'
    )]
    #[DataFixture(
        ProductFixture::class,
        ['extension_attributes' => ['stock_item' => ['qty' => 0, 'is_in_stock' => false]]],
        'soldOut'
    )]
    #[DataFixture(
        ProductFixture::class,
        ['extension_attributes' => ['stock_item' => [
            'qty' => 0, 'manage_stock' => false, 'use_config_manage_stock' => false,
        ]]],
        'unmanaged'
    )]
    public function testEachProductIsAsAvailableAsItsStock(): void
    {
        $resolver = Bootstrap::getObjectManager()->get(AvailabilityResolver::class);

        $this->assertSame(AvailabilityResolver::IN_STOCK, $resolver->resolve($this->fixture('onHand')));
        $this->assertSame(AvailabilityResolver::BACKORDER, $resolver->resolve($this->fixture('backorder')));
        $this->assertSame(AvailabilityResolver::OUT_OF_STOCK, $resolver->resolve($this->fixture('soldOut')));
        $this->assertSame(AvailabilityResolver::IN_STOCK, $resolver->resolve($this->fixture('unmanaged')));
    }

    /**
     * A batch costs the stock lookup, and the manage-stock lookup when some product is salable at
     * zero: never a lookup per product.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'p1')]
    #[DataFixture(ProductFixture::class, as: 'p2')]
    #[DataFixture(ProductFixture::class, as: 'p3')]
    #[DataFixture(ProductFixture::class, as: 'p4')]
    #[DataFixture(
        ProductFixture::class,
        ['extension_attributes' => ['stock_item' => [
            'qty' => 0, 'is_in_stock' => true, 'backorders' => 1, 'use_config_backorders' => false,
        ]]],
        'p5'
    )]
    #[DataFixture(
        ProductFixture::class,
        ['extension_attributes' => ['stock_item' => ['qty' => 0, 'is_in_stock' => false]]],
        'p6'
    )]
    public function testABatchCostsAtMostTwoQueries(): void
    {
        $resolver = Bootstrap::getObjectManager()->create(AvailabilityResolver::class);
        $resolver->getCurrentStockId();
        $products = array_map([$this, 'fixture'], ['p1', 'p2', 'p3', 'p4', 'p5', 'p6']);

        $profiler = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection()->getProfiler();
        $profiler->clear();
        $profiler->setEnabled(true);
        try {
            $availability = $resolver->resolveMany($products);
            $queries      = (int) $profiler->getTotalNumQueries();
        } finally {
            $profiler->setEnabled(false);
        }

        $this->assertCount(6, $availability);
        $this->assertSame(AvailabilityResolver::BACKORDER, $availability[(string) $products[4]->getSku()]);
        $this->assertLessThanOrEqual(2, $queries);
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
