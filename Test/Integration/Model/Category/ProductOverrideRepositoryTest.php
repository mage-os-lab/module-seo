<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Category;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Category\ProductOverrideRepository;
use MageOS\Seo\Model\ResourceModel\ProductOverride\CollectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Per-product SEO overrides against a real database.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 */
class ProductOverrideRepositoryTest extends TestCase
{
    /**
     * Product IDs whose overrides this test wrote.
     *
     * @var int[]|null
     */
    private ?array $writtenProductIds = [];

    /**
     * Remove the rows the test wrote; the product fixtures revert themselves.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->writtenProductIds !== []) {
            $collection = Bootstrap::getObjectManager()->create(CollectionFactory::class)->create();
            $collection->addFieldToFilter('product_id', ['in' => $this->writtenProductIds]);
            foreach ($collection as $override) {
                $override->delete();
            }
        }
        $this->writtenProductIds = [];
    }

    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testSavedOverridesReadBackDecoded(): void
    {
        $productId = $this->productId('product');

        $this->repository()->save($productId, 0, [
            'override_fields' => ['brand' => 'Acme', 'color' => 'Blue'],
            'robots_meta'     => 'NOINDEX,FOLLOW',
        ]);

        $override = $this->repository()->getForProduct($productId, 0);

        $this->assertSame(['brand' => 'Acme', 'color' => 'Blue'], $override['override_fields']);
        $this->assertSame('NOINDEX,FOLLOW', $override['robots_meta']);
    }

    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testASecondSaveUpdatesTheSameRowAndLeavesUnlistedFieldsAlone(): void
    {
        $productId  = $this->productId('product');
        $repository = $this->repository();

        $repository->save($productId, 0, [
            'override_fields' => ['brand' => 'Acme'],
            'robots_meta'     => 'NOINDEX,FOLLOW',
        ]);
        $repository->save($productId, 0, ['override_fields' => ['brand' => 'Other']]);

        $override = $this->repository()->getForProduct($productId, 0);
        $this->assertSame(['brand' => 'Other'], $override['override_fields']);
        $this->assertSame('NOINDEX,FOLLOW', $override['robots_meta'], 'A field not in the save is untouched.');
        $this->assertSame(1, $this->rowCount($productId), 'One row per product and store view.');
    }

    /**
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'store')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testStoreViewOverridesAreMergedOverTheGlobalOnes(): void
    {
        $productId  = $this->productId('product');
        $storeId    = (int) $this->fixture('store')->getId();
        $repository = $this->repository();

        $repository->save($productId, 0, [
            'override_fields' => ['brand' => 'Acme', 'color' => 'Blue'],
            'robots_meta'     => 'INDEX,FOLLOW',
        ]);
        $repository->save($productId, $storeId, ['override_fields' => ['color' => 'Red']]);

        $override = $this->repository()->getForProduct($productId, $storeId);

        $this->assertSame('Acme', $override['override_fields']['brand'], 'Inherited from the global row.');
        $this->assertSame('Red', $override['override_fields']['color'], 'The store view wins.');
        $this->assertSame('INDEX,FOLLOW', $override['robots_meta']);
        // The global row is unchanged for everyone else.
        $this->assertSame(
            ['brand' => 'Acme', 'color' => 'Blue'],
            $this->repository()->getForProduct($productId, 0)['override_fields']
        );
    }

    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testUpdatedAtIsRefreshedByTheDatabaseOnEverySave(): void
    {
        $productId = $this->productId('product');
        $this->repository()->save($productId, 0, ['robots_meta' => 'NOINDEX,FOLLOW']);

        // Age the row, then save again: the column is ON UPDATE CURRENT_TIMESTAMP, which only
        // fires when the statement does not set updated_at itself.
        $collection = Bootstrap::getObjectManager()->create(CollectionFactory::class)->create();
        $collection->addFieldToFilter('product_id', $productId);
        $override = $collection->getFirstItem();
        $override->setData('updated_at', '2020-01-01 00:00:00');
        $override->save();

        $this->repository()->save($productId, 0, ['robots_meta' => 'INDEX,FOLLOW']);

        $reloaded = Bootstrap::getObjectManager()->create(CollectionFactory::class)->create();
        $reloaded->addFieldToFilter('product_id', $productId);
        $this->assertNotSame('2020-01-01 00:00:00', (string) $reloaded->getFirstItem()->getData('updated_at'));
    }

    /**
     * The sitemap reads a chunk of products at once; each must come back as it does read alone.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'store')]
    #[DataFixture(ProductFixture::class, as: 'first')]
    #[DataFixture(ProductFixture::class, as: 'second')]
    public function testReadingSeveralProductsAgreesWithReadingEach(): void
    {
        $first      = $this->productId('first');
        $second     = $this->productId('second');
        $storeId    = (int) $this->fixture('store')->getId();
        $repository = $this->repository();

        $repository->save($first, 0, ['override_fields' => ['brand' => 'Acme'], 'robots_meta' => 'NOINDEX,FOLLOW']);
        $repository->save($first, $storeId, ['override_fields' => ['color' => 'Red']]);
        $repository->save($second, $storeId, ['robots_meta' => 'INDEX,NOFOLLOW']);

        $several = $this->repository()->getForProducts([$first, $second, 999999], $storeId);

        $this->assertSame('NOINDEX,FOLLOW', $several[$first]['robots_meta'], 'Inherited from the global row.');
        $this->assertSame(['brand' => 'Acme', 'color' => 'Red'], $several[$first]['override_fields']);
        $this->assertSame($this->repository()->getForProduct($first, $storeId), $several[$first]);
        $this->assertSame($this->repository()->getForProduct($second, $storeId), $several[$second]);
        $this->assertSame(['override_fields' => [], 'robots_meta' => null], $several[999999]);
    }

    /**
     * @return void
     */
    public function testAProductWithNoOverridesReadsAsTheEmptyMergedShape(): void
    {
        $this->assertSame(
            ['override_fields' => [], 'robots_meta' => null],
            $this->repository()->getForProduct(999999, 0)
        );
    }

    /**
     * A repository with an empty read cache.
     *
     * @return ProductOverrideRepository
     */
    private function repository(): ProductOverrideRepository
    {
        return Bootstrap::getObjectManager()->create(ProductOverrideRepository::class);
    }

    /**
     * How many rows exist for a product, across store views.
     *
     * @param int $productId
     * @return int
     */
    private function rowCount(int $productId): int
    {
        $collection = Bootstrap::getObjectManager()->create(CollectionFactory::class)->create();
        $collection->addFieldToFilter('product_id', $productId);

        return $collection->getSize();
    }

    /**
     * The ID of a fixture product, remembered for cleanup.
     *
     * @param string $name
     * @return int
     */
    private function productId(string $name): int
    {
        $productId                 = (int) $this->fixture($name)->getId();
        $this->writtenProductIds[] = $productId;

        return $productId;
    }

    /**
     * An entity created by a data fixture.
     *
     * @param string $name
     * @return \Magento\Framework\DataObject
     */
    private function fixture(string $name): \Magento\Framework\DataObject
    {
        return DataFixtureStorageManager::getStorage()->get($name);
    }
}
