<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model;

use MageOS\Seo\Model\Organisation;
use MageOS\Seo\Model\OrganisationFactory;
use MageOS\Seo\Model\OrganisationRepository;
use MageOS\Seo\Model\ResourceModel\Organisation as OrganisationResource;
use MageOS\Seo\Model\ResourceModel\Organisation\Collection;
use MageOS\Seo\Model\ResourceModel\Organisation\CollectionFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Reading and deleting Organisation records by scope.
 *
 * Deletion goes by scope because the table carries no foreign key: scope_id points at a website
 * or at a store view depending on the scope column. Reading is memoised, because one getForScope()
 * walks up to three scopes and a page makes several independent calls.
 *
 * The model and collection factories are Magento's generated classes, so this test needs an
 * installation to have generated them. The mutation-testing run works from the module directory
 * alone and excludes this group; the unit job, which runs inside an installation, does not.
 *
 * @group magento-generated
 */
#[Group('magento-generated')]
class OrganisationRepositoryTest extends TestCase
{
    /**
     * Filters applied to the collection, as field => condition.
     *
     * @var array<string, mixed>
     */
    private array $filters = [];

    /**
     * Records the collection hands back.
     *
     * @var Organisation[]
     */
    private array $records = [];

    /**
     * Records passed to the resource model's delete().
     *
     * @var Organisation[]
     */
    private array $deleted = [];

    /**
     * Rows the table holds, keyed "{scope}_{scopeId}".
     *
     * @var array<string, mixed[]>
     */
    private array $rows = [];

    /**
     * Every scope loadByScope() was asked for, in order, as "{scope}_{scopeId}".
     *
     * @var string[]
     */
    private array $loads = [];

    protected function setUp(): void
    {
        $this->filters = [];
        $this->records = [];
        $this->deleted = [];
        $this->rows    = [];
        $this->loads   = [];
    }

    public function testDeleteForScopeDeletesEveryMatchingRecord(): void
    {
        $this->records = [$this->record(1), $this->record(2)];

        $deleted = $this->repository()->deleteForScope('stores', [3, 4]);

        $this->assertSame(2, $deleted);
        $this->assertSame($this->records, $this->deleted);
        $this->assertSame(['scope' => 'stores', 'scope_id' => ['in' => [3, 4]]], $this->filters);
    }

    public function testDeleteForScopeNormalisesTheScopeIds(): void
    {
        $this->repository()->deleteForScope('stores', ['3', 3, '4']);

        $this->assertSame(['scope' => 'stores', 'scope_id' => ['in' => [3, 4]]], $this->filters);
    }

    public function testAnEmptyScopeIdListTouchesNothing(): void
    {
        $this->assertSame(0, $this->repository()->deleteForScope('stores', []));
        $this->assertSame([], $this->filters, 'No collection is loaded at all.');
        $this->assertSame([], $this->deleted);
    }

    public function testFallbackChainStopsAtTheFirstScopeThatHasARow(): void
    {
        $this->rows = ['stores_2' => ['entity_id' => 9, 'name' => 'Store view']];

        $organisation = $this->repository()->getForScope(2, 1);

        $this->assertSame('Store view', $organisation->getName());
        $this->assertSame(['stores_2'], $this->loads, 'The website and default rows are never read.');
    }

    public function testFallbackChainWalksToTheGlobalDefault(): void
    {
        $this->rows = ['default_0' => ['entity_id' => 1, 'name' => 'Global']];

        $organisation = $this->repository()->getForScope(2, 1);

        $this->assertSame('Global', $organisation->getName());
        $this->assertSame(['stores_2', 'websites_1', 'default_0'], $this->loads);
    }

    public function testTheSameScopeIsReadOnlyOnce(): void
    {
        $this->rows = ['default_0' => ['entity_id' => 1, 'name' => 'Global']];
        $repository = $this->repository();

        $first  = $repository->getForScope(2, 1);
        $second = $repository->getForScope(2, 1);

        // Three loads, not six: the second call is answered from the memo.
        $this->assertSame(['stores_2', 'websites_1', 'default_0'], $this->loads);
        $this->assertSame($first, $second);
    }

    public function testEachScopeIsMemoisedSeparately(): void
    {
        $this->rows = [
            'stores_2' => ['entity_id' => 9, 'name' => 'Two'],
            'stores_3' => ['entity_id' => 10, 'name' => 'Three'],
        ];
        $repository = $this->repository();

        $this->assertSame('Two', $repository->getForScope(2, 1)->getName());
        $this->assertSame('Three', $repository->getForScope(3, 1)->getName());
        $this->assertSame(['stores_2', 'stores_3'], $this->loads);
    }

    public function testSavingClearsTheMemo(): void
    {
        $this->rows = ['default_0' => ['entity_id' => 1, 'name' => 'Global']];
        $repository = $this->repository();

        $repository->getForScope(2, 1);
        $repository->save($this->record(1));
        $repository->getForScope(2, 1);

        // A saved row can change which scope answers, so the chain is walked again.
        $this->assertSame(
            ['stores_2', 'websites_1', 'default_0', 'stores_2', 'websites_1', 'default_0'],
            $this->loads
        );
    }

    public function testDeletingClearsTheMemo(): void
    {
        $this->rows    = ['stores_2' => ['entity_id' => 9, 'name' => 'Store view']];
        $this->records = [$this->record(9)];
        $repository    = $this->repository();

        $repository->getForScope(2, 1);
        $repository->deleteForScope('stores', [2]);
        $repository->getForScope(2, 1);

        $this->assertSame(['stores_2', 'stores_2'], $this->loads);
    }

    public function testDeletingNothingLeavesTheMemoAlone(): void
    {
        $this->rows = ['stores_2' => ['entity_id' => 9, 'name' => 'Store view']];
        $repository = $this->repository();

        $repository->getForScope(2, 1);
        $repository->deleteForScope('stores', [7]);
        $repository->getForScope(2, 1);

        $this->assertSame(['stores_2'], $this->loads);
    }

    public function testResetStateClearsTheMemoBetweenWorkerRequests(): void
    {
        $this->rows = ['stores_2' => ['entity_id' => 9, 'name' => 'Store view']];
        $repository = $this->repository();

        $repository->getForScope(2, 1);
        $repository->_resetState();
        $repository->getForScope(2, 1);

        // Otherwise this visitor's Organisation would be served to the next one.
        $this->assertSame(['stores_2', 'stores_2'], $this->loads);
    }

    /**
     * The repository over a resource that answers from $rows and a collection that records
     * its filters and yields the set records.
     *
     * @return OrganisationRepository
     */
    private function repository(): OrganisationRepository
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, mixed $condition) use ($collection): Collection {
                $this->filters[$field] = $condition;
                return $collection;
            }
        );
        $collection->method('getIterator')->willReturnCallback(
            fn (): \ArrayIterator => new \ArrayIterator($this->records)
        );

        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $resource = $this->createStub(OrganisationResource::class);
        $resource->method('delete')->willReturnCallback(
            function (Organisation $organisation) use ($resource): OrganisationResource {
                $this->deleted[] = $organisation;
                return $resource;
            }
        );
        $resource->method('loadByScope')->willReturnCallback(
            function (Organisation $organisation, string $scope, int $scopeId): void {
                $key           = "{$scope}_{$scopeId}";
                $this->loads[] = $key;
                if (isset($this->rows[$key])) {
                    $organisation->setData($this->rows[$key]);
                }
            }
        );

        $factory = $this->createStub(OrganisationFactory::class);
        $factory->method('create')->willReturnCallback(fn (): Organisation => $this->record(null));

        return new OrganisationRepository($factory, $resource, $collectionFactory);
    }

    /**
     * An Organisation model without its constructor dependencies.
     *
     * newInstanceWithoutConstructor() skips AbstractModel::_init(), which is what normally copies
     * the id field name off the resource model — so getId() would read "id" and the fallback
     * chain would never see a loaded row.
     *
     * @param int|null $entityId
     * @return Organisation
     */
    private function record(?int $entityId): Organisation
    {
        /** @var Organisation $organisation */
        $organisation = (new \ReflectionClass(Organisation::class))->newInstanceWithoutConstructor();
        $organisation->setIdFieldName('entity_id');

        if ($entityId !== null) {
            $organisation->setData('entity_id', $entityId);
        }

        return $organisation;
    }
}
