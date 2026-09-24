<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Category;

use Magento\Framework\Model\AbstractModel;
use MageOS\Seo\Model\Category\ProductOverrideRepository;
use MageOS\Seo\Model\ProductOverride;
use MageOS\Seo\Model\ResourceModel\ProductOverride as ProductOverrideResource;
use MageOS\Seo\Model\ResourceModel\ProductOverride\Collection;
use MageOS\Seo\Model\ResourceModel\ProductOverride\CollectionFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The collection factory is one of Magento's generated classes, so this test needs an
 * installation to have generated it. The mutation-testing run works from the module directory
 * alone and excludes this group; the unit job, which runs inside an installation, does not.
 *
 * @group magento-generated
 */
#[Group('magento-generated')]
class ProductOverrideRepositoryTest extends TestCase
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

    public function testGetForProductMergesStoreRowOverGlobalAndMemoises(): void
    {
        $this->rows = [
            $this->row(10, 0, '{"brand":"Acme","color":"Blue"}', null),
            $this->row(10, 2, '{"color":"Red"}', 'NOINDEX,FOLLOW'),
        ];

        $repository = $this->repository();
        $first      = $repository->getForProduct(10, 2);
        $second     = $repository->getForProduct(10, 2);

        $this->assertSame($first, $second);
        $this->assertSame('Acme', $first['override_fields']['brand']);
        $this->assertSame('Red', $first['override_fields']['color']);
        $this->assertSame('NOINDEX,FOLLOW', $first['robots_meta']);
        $this->assertCount(1, $this->filterSets, 'The second read came from the memo.');
        $this->assertSame(['in' => [0, 2]], $this->filterSets[0]['store_id']);
    }

    public function testGetForProductsMergesEachProductAsGetForProductDoes(): void
    {
        $this->rows = [
            $this->row(10, 0, '{"brand":"Acme"}', 'NOINDEX'),
            $this->row(11, 0, null, 'INDEX,FOLLOW'),
            $this->row(10, 2, '{"color":"Red"}', ''),
            $this->row(11, 2, null, 'NOINDEX,NOFOLLOW'),
        ];

        $result = $this->repository()->getForProducts([10, 11, 12], 2);

        $this->assertSame(['brand' => 'Acme', 'color' => 'Red'], $result[10]['override_fields']);
        $this->assertSame('NOINDEX', $result[10]['robots_meta'], 'An empty store row does not blank the global one.');
        $this->assertSame('NOINDEX,NOFOLLOW', $result[11]['robots_meta'], 'The store row wins.');
        $this->assertSame(['override_fields' => [], 'robots_meta' => null], $result[12], 'No rows: the empty shape.');
        $this->assertCount(1, $this->filterSets, 'One query for all three.');
        $this->assertSame(['in' => [10, 11, 12]], $this->filterSets[0]['product_id']);
    }

    public function testGetForProductsIsNotMemoised(): void
    {
        // The sitemap reads the whole catalogue through it; holding every row would undo streaming.
        $repository = $this->repository();
        $repository->getForProducts([10], 2);
        $repository->getForProducts([10], 2);

        $this->assertCount(2, $this->filterSets);
    }

    public function testGetForProductsWithNoIdsReadsNothing(): void
    {
        $this->assertSame([], $this->repository()->getForProducts([], 2));
        $this->assertCount(0, $this->filterSets);
    }

    public function testAProductWithNoOverridesStillReturnsTheMergedShape(): void
    {
        $result = $this->repository()->getForProduct(10, 2);

        $this->assertSame(['override_fields' => [], 'robots_meta' => null], $result);
    }

    public function testResetStateClearsTheMemoisedRows(): void
    {
        $repository = $this->repository();
        $repository->getForProduct(10, 2);
        $repository->_resetState();
        $repository->getForProduct(10, 2);

        $this->assertCount(2, $this->filterSets);
    }

    public function testNothingIsHeldBetweenOperations(): void
    {
        // The collection and the resource model resolve their own connection per call.
        // ResourceConnection::_resetState() closes connections between worker-mode requests,
        // so a handle kept at construction would go stale.
        $repository = $this->repository();
        $repository->getForProduct(10, 2);
        $repository->getForProduct(11, 2);

        $this->assertCount(2, $this->filterSets);
    }

    public function testSaveInvalidatesTheMemoisedRow(): void
    {
        $repository = $this->repository();
        $repository->getForProduct(10, 2);
        $repository->save(10, 2, ['override_fields' => ['brand' => 'Acme']]);
        $repository->getForProduct(10, 2);

        // Read, save, read again: the memo did not answer the second read.
        $this->assertCount(3, $this->filterSets);
    }

    public function testSaveWritesTheScopeAndLeavesUpdatedAtToTheDatabase(): void
    {
        $this->rows = [[
            'entity_id'  => 7,
            'product_id' => 10,
            'store_id'   => 2,
            'updated_at' => '2026-01-01 00:00:00',
        ]];

        $this->repository()->save(10, 2, ['override_fields' => ['brand' => 'Acme'], 'robots_meta' => 'NOINDEX']);

        $this->assertCount(1, $this->saved);
        $saved = $this->saved[0];
        $this->assertSame(7, $saved->getData('entity_id'), 'The existing row is updated, not duplicated.');
        $this->assertSame(10, $saved->getData('product_id'));
        $this->assertSame(2, $saved->getData('store_id'));
        $this->assertSame('{"brand":"Acme"}', $saved->getData('override_fields'));
        $this->assertSame('NOINDEX', $saved->getData('robots_meta'));
        // An explicit value would win over the column's ON UPDATE CURRENT_TIMESTAMP.
        $this->assertNull($saved->getData('updated_at'));
    }

    public function testSavingAProductThatHasNoRowYetInsertsOne(): void
    {
        $this->repository()->save(10, 0, ['robots_meta' => 'NOINDEX']);

        $this->assertCount(1, $this->saved);
        $this->assertNull($this->saved[0]->getData('entity_id'));
        $this->assertSame(10, $this->saved[0]->getData('product_id'));
        $this->assertSame(0, $this->saved[0]->getData('store_id'));
    }

    /**
     * A table row. Tests list global rows before store rows, as the query's ORDER BY returns them.
     *
     * @param int $productId
     * @param int $storeId
     * @param string|null $fields
     * @param string|null $robots
     * @return mixed[]
     */
    private function row(int $productId, int $storeId, ?string $fields, ?string $robots): array
    {
        return [
            'product_id'      => $productId,
            'store_id'        => $storeId,
            'override_fields' => $fields,
            'robots_meta'     => $robots,
        ];
    }

    /**
     * The repository over a collection factory that records filters and yields the set rows.
     *
     * @return ProductOverrideRepository
     */
    private function repository(): ProductOverrideRepository
    {
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturnCallback(fn (): Collection => $this->collection());

        $resource = $this->createStub(ProductOverrideResource::class);
        $resource->method('save')->willReturnCallback(
            function (AbstractModel $model) use ($resource): ProductOverrideResource {
                $this->saved[] = $model;
                return $resource;
            }
        );

        return new ProductOverrideRepository($collectionFactory, $resource);
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
            fn (): ProductOverride => $this->models()[0] ?? $this->model([])
        );

        return $collection;
    }

    /**
     * The set rows as models, the way a loaded collection hands them over.
     *
     * @return ProductOverride[]
     */
    private function models(): array
    {
        return array_map(fn (array $row): ProductOverride => $this->model($row), $this->rows);
    }

    /**
     * A model without its constructor dependencies.
     *
     * @param mixed[] $row
     * @return ProductOverride
     */
    private function model(array $row): ProductOverride
    {
        /** @var ProductOverride $model */
        $model = (new \ReflectionClass(ProductOverride::class))->newInstanceWithoutConstructor();
        $model->setData($row);

        return $model;
    }
}
