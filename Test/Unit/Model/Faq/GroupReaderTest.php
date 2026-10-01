<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Faq;

use MageOS\Seo\Model\Faq;
use MageOS\Seo\Model\Faq\Repository;
use MageOS\Seo\Model\ResourceModel\Faq\Collection;
use MageOS\Seo\Model\ResourceModel\Faq\CollectionFactory;
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
class RepositoryTest extends TestCase
{
    /**
     * Rows the next collection yields, as raw table rows.
     *
     * @var array<int, mixed[]>
     */
    private array $rows = [];

    /**
     * Filters applied to the collection, as field => condition.
     *
     * @var array<string, mixed>
     */
    private array $filters = [];

    /**
     * Sort fields in the order they were applied, as field => direction.
     *
     * @var array<string, string>
     */
    private array $orders = [];

    /**
     * Number of collections the factory was asked for.
     *
     * @var int
     */
    private int $created = 0;

    protected function setUp(): void
    {
        $this->rows    = [];
        $this->filters = [];
        $this->orders  = [];
        $this->created = 0;
    }

    public function testReturnsEmptyForBlankIdentifierWithoutQuerying(): void
    {
        $this->assertSame([], $this->repository()->getByIdentifier('', 1));
        $this->assertSame(0, $this->created, 'A blank identifier must not reach the database.');
    }

    public function testMapsRowsToQuestionAnswerPairs(): void
    {
        $this->rows = [
            ['entity_id' => 1, 'question' => 'Q1', 'answer' => 'A1'],
            ['entity_id' => 2, 'question' => 'Q2', 'answer' => 'A2'],
        ];

        $this->assertSame(
            [
                ['question' => 'Q1', 'answer' => 'A1'],
                ['question' => 'Q2', 'answer' => 'A2'],
            ],
            $this->repository()->getByIdentifier('shipping', 1)
        );
    }

    public function testReturnsEmptyWhenNoRows(): void
    {
        $this->assertSame([], $this->repository()->getByIdentifier('shipping', 1));
    }

    public function testQueriesTheGroupAtTheStoreViewAndTheGlobalScope(): void
    {
        $this->repository()->getByIdentifier('shipping', 3);

        $this->assertSame(['eq' => 'shipping'], $this->filters['identifier']);
        $this->assertSame(['in' => [0, 3]], $this->filters['store_id']);
        $this->assertSame(['eq' => 1], $this->filters['is_active'], 'Disabled entries must not render.');
    }

    public function testOrdersBySortOrderThenEntityId(): void
    {
        $this->repository()->getByIdentifier('shipping', 1);

        // The second key breaks ties, so a cached FAQPage node does not reorder between renders.
        $this->assertSame(['sort_order' => 'ASC', 'entity_id' => 'ASC'], $this->orders);
    }

    /**
     * The repository over a collection factory that records what it was asked for.
     *
     * @return Repository
     */
    private function repository(): Repository
    {
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturnCallback(
            function (): Collection {
                $this->created++;
                return $this->collection();
            }
        );

        return new Repository($collectionFactory);
    }

    /**
     * A collection that records its filters and ordering and yields the models of the set rows.
     *
     * @return Collection
     */
    private function collection(): Collection
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function (string $field, mixed $condition) use ($collection): Collection {
                $this->filters[$field] = $condition;
                return $collection;
            }
        );
        $collection->method('setOrder')->willReturnCallback(
            function (string $field, string $direction) use ($collection): Collection {
                $this->orders[$field] = $direction;
                return $collection;
            }
        );
        $collection->method('getIterator')->willReturnCallback(
            fn (): \ArrayIterator => new \ArrayIterator($this->models())
        );

        return $collection;
    }

    /**
     * The set rows as models, the way a loaded collection hands them over.
     *
     * @return Faq[]
     */
    private function models(): array
    {
        return array_map(
            function (array $row): Faq {
                /** @var Faq $model */
                $model = (new \ReflectionClass(Faq::class))->newInstanceWithoutConstructor();
                $model->setData($row);

                return $model;
            },
            $this->rows
        );
    }
}
