<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Category;

use Magento\Framework\Model\AbstractModel;
use MageOS\Seo\Model\Category\ConfigRepository;
use MageOS\Seo\Model\CategoryConfig;
use MageOS\Seo\Model\ResourceModel\CategoryConfig as CategoryConfigResource;
use MageOS\Seo\Model\ResourceModel\CategoryConfig\Collection;
use MageOS\Seo\Model\ResourceModel\CategoryConfig\CollectionFactory;
use PHPUnit\Framework\TestCase;

class ConfigRepositoryTest extends TestCase
{
    /**
     * Rows the next collection yields, as raw table rows.
     *
     * @var array<int, mixed[]>
     */
    private array $rows = [];

    /**
     * Filters applied per created collection, as field => condition.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $filterSets = [];

    /**
     * Models handed to the resource model's save().
     *
     * @var AbstractModel[]
     */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->rows       = [];
        $this->filterSets = [];
        $this->saved      = [];
    }

    public function testGetForCategoryMemoisesPerCategoryAndStore(): void
    {
        $this->rows = [['category_id' => 5, 'store_id' => 0, 'schema_template' => 'generic']];

        $repository = $this->repository();
        $first      = $repository->getForCategory(5);
        $second     = $repository->getForCategory(5);

        $this->assertSame($first, $second);
        $this->assertSame('generic', $first['schema_template']);
        $this->assertCount(1, $this->filterSets, 'The second read came from the memo.');
    }

    public function testResetStateClearsTheMemoisedRows(): void
    {
        $this->rows = [['category_id' => 5, 'store_id' => 0, 'schema_template' => 'generic']];

        $repository = $this->repository();
        $repository->getForCategory(5);
        $repository->_resetState();
        $repository->getForCategory(5);

        $this->assertCount(2, $this->filterSets);
    }

    public function testNothingIsHeldBetweenOperations(): void
    {
        // The collection and the resource model resolve their own connection per call.
        // ResourceConnection::_resetState() closes connections between worker-mode requests,
        // so a handle kept at construction would go stale.
        $repository = $this->repository();
        $repository->getForCategory(5);
        $repository->getForCategory(7);

        $this->assertCount(2, $this->filterSets);
    }

    public function testSaveInvalidatesTheMemoisedRow(): void
    {
        $this->rows = [['category_id' => 5, 'store_id' => 0, 'schema_template' => 'generic']];

        $repository = $this->repository();
        $repository->getForCategory(5);
        $repository->save(5, ['schema_template' => 'food']);
        $repository->getForCategory(5);

        // Read, save, read again: the memo did not answer the second read.
        $this->assertCount(3, $this->filterSets);
    }

    public function testStoreRowMergeLetsStoreValuesWinAndPreservesGlobalAndZero(): void
    {
        // storeId > 0 loads the global (store 0) and store rows and merges them:
        // store non-empty values win, a null store value keeps the global value,
        // and a legitimate 0 is preserved (not treated as "empty").
        $this->rows = [
            ['category_id' => 5, 'store_id' => 0, 'schema_template' => 'generic',
                'robots_meta' => 'INDEX,FOLLOW', 'item_list_enabled' => 1],
            ['category_id' => 5, 'store_id' => 2, 'schema_template' => 'food',
                'robots_meta' => null, 'item_list_enabled' => 0],
        ];

        $result = $this->repository()->getForCategory(5, [], 2);

        $this->assertSame('food', $result['schema_template']);
        $this->assertSame('INDEX,FOLLOW', $result['robots_meta']);
        $this->assertSame(0, $result['item_list_enabled']);
        $this->assertSame(['in' => [0, 2]], $this->filterSets[0]['store_id']);
    }

    public function testTemplateIsInheritedFromNearestAncestorKeepingOwnValues(): void
    {
        $this->rows = [
            ['category_id' => 14, 'store_id' => 0, 'schema_template' => '', 'robots_meta' => 'NOINDEX'],
            ['category_id' => 5, 'store_id' => 0, 'schema_template' => 'generic'],
        ];

        $result = $this->repository()->getForCategory(14, ['1', '2', '5', '14'], 0);

        $this->assertSame('generic', $result['schema_template']);
        $this->assertSame('NOINDEX', $result['robots_meta']);
        $this->assertSame(14, $result['category_id']);
    }

    public function testTheCategoryAndItsAncestorsAreReadInOneQuery(): void
    {
        $this->rows = [['category_id' => 14, 'store_id' => 0, 'schema_template' => '']];

        $this->repository()->getForCategory(14, ['1', '2', '5', '9', '14'], 0);

        $this->assertCount(1, $this->filterSets, 'One collection, not one per ancestor.');
        // Nearest ancestor first, and the root categories (1, 2) and the category itself are
        // never asked for.
        $this->assertSame(['in' => [14, 9, 5]], $this->filterSets[0]['category_id']);
    }

    public function testRootCategoriesAreNotUsedAsTemplateAncestors(): void
    {
        $this->rows = [['category_id' => 14, 'store_id' => 0, 'schema_template' => '']];

        $result = $this->repository()->getForCategory(14, ['1', '2'], 0);

        $this->assertSame('', $result['schema_template']);
        $this->assertSame(['in' => [14]], $this->filterSets[0]['category_id']);
    }

    public function testSaveWritesTheScopeAndLeavesUpdatedAtToTheDatabase(): void
    {
        $this->rows = [[
            'entity_id'       => 3,
            'category_id'     => 5,
            'store_id'        => 2,
            'schema_template' => 'generic',
            'updated_at'      => '2026-01-01 00:00:00',
        ]];

        $this->repository()->save(5, ['schema_template' => 'food', 'override_fields' => ['brand' => 'Acme']], 2);

        $this->assertCount(1, $this->saved);
        $saved = $this->saved[0];
        $this->assertSame(3, $saved->getData('entity_id'), 'The existing row is updated, not duplicated.');
        $this->assertSame(5, $saved->getData('category_id'));
        $this->assertSame(2, $saved->getData('store_id'));
        $this->assertSame('food', $saved->getData('schema_template'));
        $this->assertSame('{"brand":"Acme"}', $saved->getData('override_fields'));
        // An explicit value would win over the column's ON UPDATE CURRENT_TIMESTAMP.
        $this->assertNull($saved->getData('updated_at'));
    }

    public function testSavingACategoryThatHasNoRowYetInsertsOne(): void
    {
        $this->repository()->save(5, ['schema_template' => 'food'], 0);

        $this->assertCount(1, $this->saved);
        $this->assertNull($this->saved[0]->getData('entity_id'));
        $this->assertSame(5, $this->saved[0]->getData('category_id'));
        $this->assertSame(0, $this->saved[0]->getData('store_id'));
    }

    public function testEnabledFieldsAreStoredAsAJsonList(): void
    {
        // Array keys from the admin form must not leak into the stored JSON.
        $this->repository()->save(5, ['enabled_fields' => [2 => 'brand', 5 => 'sku']], 0);

        $this->assertSame('["brand","sku"]', $this->saved[0]->getData('enabled_fields'));
    }

    /**
     * The repository over a collection factory that records filters and yields the set rows.
     *
     * @return ConfigRepository
     */
    private function repository(): ConfigRepository
    {
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturnCallback(fn (): Collection => $this->collection());

        $resource = $this->createStub(CategoryConfigResource::class);
        $resource->method('save')->willReturnCallback(
            function (AbstractModel $model) use ($resource): CategoryConfigResource {
                $this->saved[] = $model;
                return $resource;
            }
        );

        return new ConfigRepository($collectionFactory, $resource);
    }

    /**
     * A collection that records its filters and yields the models of the set rows.
     *
     * @return Collection
     */
    private function collection(): Collection
    {
        $index                    = \count($this->filterSets);
        $this->filterSets[$index] = [];

        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, mixed $condition) use ($collection, $index): Collection {
                $this->filterSets[$index][$field] = $condition;
                return $collection;
            }
        );
        $collection->method('setOrder')->willReturn($collection);
        $collection->method('getIterator')->willReturnCallback(
            fn (): \ArrayIterator => new \ArrayIterator($this->models())
        );
        $collection->method('getFirstItem')->willReturnCallback(
            fn (): CategoryConfig => $this->models()[0] ?? $this->model([])
        );

        return $collection;
    }

    /**
     * The set rows as models, the way a loaded collection hands them over.
     *
     * @return CategoryConfig[]
     */
    private function models(): array
    {
        return array_map(fn (array $row): CategoryConfig => $this->model($row), $this->rows);
    }

    /**
     * A model without its constructor dependencies.
     *
     * @param mixed[] $row
     * @return CategoryConfig
     */
    private function model(array $row): CategoryConfig
    {
        /** @var CategoryConfig $model */
        $model = (new \ReflectionClass(CategoryConfig::class))->newInstanceWithoutConstructor();
        $model->setData($row);

        return $model;
    }
}
