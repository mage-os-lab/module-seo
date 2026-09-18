<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Category;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Category\ConfigRepository;
use MageOS\Seo\Model\ResourceModel\CategoryConfig\CollectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Per-category SEO configuration against a real database.
 *
 * Covers what the unit tests can only describe: that a save really writes one row per category
 * and store view, that a second save updates it instead of adding another, and that the store
 * view fallback and ancestor inheritance read what was written.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 */
class ConfigRepositoryTest extends TestCase
{
    /**
     * Category IDs whose configuration this test wrote.
     *
     * @var int[]|null
     */
    private ?array $writtenCategoryIds = [];

    /**
     * Remove the rows the test wrote; the category fixtures revert themselves.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->writtenCategoryIds !== []) {
            $collection = Bootstrap::getObjectManager()->create(CollectionFactory::class)->create();
            $collection->addFieldToFilter('category_id', ['in' => $this->writtenCategoryIds]);
            foreach ($collection as $config) {
                $config->delete();
            }
        }
        $this->writtenCategoryIds = [];
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testASavedConfigurationReadsBack(): void
    {
        $categoryId = $this->categoryId('category');

        $this->repository()->save($categoryId, [
            'schema_template' => 'generic',
            'robots_meta'     => 'NOINDEX,FOLLOW',
            'enabled_fields'  => [2 => 'brand', 5 => 'sku'],
            'override_fields' => ['brand' => 'Acme'],
        ]);

        $row = $this->repository()->getForCategory($categoryId);

        $this->assertSame('generic', $row['schema_template']);
        $this->assertSame('NOINDEX,FOLLOW', $row['robots_meta']);
        // Array keys from the admin form must not leak into the stored JSON.
        $this->assertSame('["brand","sku"]', $row['enabled_fields']);

        $decoded = $this->repository()->decode($row);
        $this->assertSame(['brand', 'sku'], $decoded['enabled_fields']);
        $this->assertSame(['brand' => 'Acme'], $decoded['override_fields']);
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testASecondSaveUpdatesTheSameRowAndLeavesUnlistedFieldsAlone(): void
    {
        $categoryId = $this->categoryId('category');
        $repository = $this->repository();

        $repository->save($categoryId, ['schema_template' => 'generic', 'robots_meta' => 'NOINDEX,FOLLOW']);
        $repository->save($categoryId, ['schema_template' => 'food']);

        $row = $this->repository()->getForCategory($categoryId);
        $this->assertSame('food', $row['schema_template']);
        $this->assertSame('NOINDEX,FOLLOW', $row['robots_meta'], 'A field not in the save is untouched.');
        $this->assertSame(1, $this->rowCount($categoryId), 'One row per category and store view.');
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testUpdatedAtIsRefreshedByTheDatabaseOnEverySave(): void
    {
        $categoryId = $this->categoryId('category');
        $this->repository()->save($categoryId, ['schema_template' => 'generic']);

        // Age the row, then save again: the column is ON UPDATE CURRENT_TIMESTAMP, which only
        // fires when the statement does not set updated_at itself.
        $collection = Bootstrap::getObjectManager()->create(CollectionFactory::class)->create();
        $collection->addFieldToFilter('category_id', $categoryId);
        $config = $collection->getFirstItem();
        $config->setData('updated_at', '2020-01-01 00:00:00');
        $config->save();

        $this->repository()->save($categoryId, ['schema_template' => 'food']);

        $reloaded = Bootstrap::getObjectManager()->create(CollectionFactory::class)->create();
        $reloaded->addFieldToFilter('category_id', $categoryId);
        $this->assertNotSame('2020-01-01 00:00:00', (string) $reloaded->getFirstItem()->getData('updated_at'));
    }

    /**
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'store')]
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testAStoreViewRowOverridesTheGlobalOneFieldByField(): void
    {
        $categoryId = $this->categoryId('category');
        $storeId    = (int) $this->fixture('store')->getId();
        $repository = $this->repository();

        $repository->save($categoryId, ['schema_template' => 'generic', 'robots_meta' => 'INDEX,FOLLOW'], 0);
        $repository->save($categoryId, ['schema_template' => 'food'], $storeId);

        $row = $this->repository()->getForCategory($categoryId, [], $storeId);

        $this->assertSame('food', $row['schema_template'], 'The store view wins.');
        $this->assertSame('INDEX,FOLLOW', $row['robots_meta'], 'And inherits what it does not set.');
        // The global row is unchanged for everyone else.
        $this->assertSame('generic', $this->repository()->getForCategory($categoryId)['schema_template']);
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'parent')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => '$parent.id$'], 'child')]
    public function testATemplateIsInheritedFromTheNearestConfiguredAncestor(): void
    {
        $parentId = $this->categoryId('parent');
        $childId  = $this->categoryId('child');

        $this->repository()->save($parentId, ['schema_template' => 'generic']);
        $this->repository()->save($childId, ['robots_meta' => 'NOINDEX,FOLLOW']);

        $child = Bootstrap::getObjectManager()->get(CategoryRepositoryInterface::class)->get($childId);
        $row   = $this->repository()->getForCategory($childId, explode('/', (string) $child->getPath()));

        $this->assertSame('generic', $row['schema_template'], 'Inherited from the parent.');
        $this->assertSame('NOINDEX,FOLLOW', $row['robots_meta'], 'Its own values are kept.');
    }

    /**
     * @return void
     */
    public function testAnUnconfiguredCategoryReadsAsNothing(): void
    {
        $this->assertSame([], $this->repository()->getForCategory(999999));
    }

    /**
     * A repository with an empty read cache, and whose writes this test cleans up.
     *
     * @return ConfigRepository
     */
    private function repository(): ConfigRepository
    {
        return Bootstrap::getObjectManager()->create(ConfigRepository::class);
    }

    /**
     * How many rows exist for a category, across store views.
     *
     * @param int $categoryId
     * @return int
     */
    private function rowCount(int $categoryId): int
    {
        $collection = Bootstrap::getObjectManager()->create(CollectionFactory::class)->create();
        $collection->addFieldToFilter('category_id', $categoryId);

        return $collection->getSize();
    }

    /**
     * The ID of a fixture category, remembered for cleanup.
     *
     * @param string $name
     * @return int
     */
    private function categoryId(string $name): int
    {
        $categoryId                 = (int) $this->fixture($name)->getId();
        $this->writtenCategoryIds[] = $categoryId;

        return $categoryId;
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
