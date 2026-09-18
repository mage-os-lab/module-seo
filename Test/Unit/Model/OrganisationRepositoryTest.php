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
 * Deleting Organisation records by scope: the table carries no foreign key, because scope_id
 * points at a website or a store view depending on the scope column.
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
     * Records the collection handed back.
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

    protected function setUp(): void
    {
        $this->filters = [];
        $this->records = [];
        $this->deleted = [];
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

    /**
     * The repository over a collection that records its filters and yields the set records.
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

        return new OrganisationRepository(
            $this->createStub(OrganisationFactory::class),
            $resource,
            $collectionFactory
        );
    }

    /**
     * An Organisation model without its constructor dependencies.
     *
     * @param int $entityId
     * @return Organisation
     */
    private function record(int $entityId): Organisation
    {
        /** @var Organisation $organisation */
        $organisation = (new \ReflectionClass(Organisation::class))->newInstanceWithoutConstructor();
        $organisation->setData('entity_id', $entityId);

        return $organisation;
    }
}
