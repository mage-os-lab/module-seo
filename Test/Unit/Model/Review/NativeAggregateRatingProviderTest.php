<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Review;

use Magento\Review\Model\ResourceModel\Review\Summary\Collection;
use Magento\Review\Model\ResourceModel\Review\Summary\CollectionFactory;
use Magento\Review\Model\Review\Summary;
use MageOS\Seo\Model\Review\NativeAggregateRatingProvider;
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
class NativeAggregateRatingProviderTest extends TestCase
{
    /**
     * The summary row the collection yields; an empty array means no row at all.
     *
     * @var mixed[]
     */
    private array $row = [];

    /**
     * Entity filter arguments, as [entityId, entityType].
     *
     * @var mixed[]
     */
    private array $entityFilter = [];

    /**
     * The store the collection was filtered to.
     *
     * @var int|null
     */
    private ?int $storeFilter = null;

    protected function setUp(): void
    {
        $this->row          = [];
        $this->entityFilter = [];
        $this->storeFilter  = null;
    }

    public function testPriorityIsLowFallback(): void
    {
        $this->assertSame(100, $this->provider()->getPriority());
    }

    public function testReturnsNullWhenTheProductHasNoSummaryRow(): void
    {
        $this->assertNull($this->provider()->getRating(5, 1));
    }

    public function testReturnsNullWhenZeroReviews(): void
    {
        $this->row = ['rating_summary' => '100', 'reviews_count' => '0'];

        $this->assertNull($this->provider()->getRating(5, 1));
    }

    public function testConvertsPercentageToFiveStarScale(): void
    {
        $this->row = ['rating_summary' => '90', 'reviews_count' => '17'];

        $rating = $this->provider()->getRating(5, 1);

        $this->assertNotNull($rating);
        $this->assertSame('4.5', $rating['ratingValue']);
        $this->assertSame('17', $rating['reviewCount']);
        $this->assertSame('5', $rating['bestRating']);
        $this->assertSame('1', $rating['worstRating']);
    }

    public function testRoundsRatingToOneDecimal(): void
    {
        // 73/20 = 3.65 → rounded to 3.7
        $this->row = ['rating_summary' => '73', 'reviews_count' => '4'];

        $rating = $this->provider()->getRating(5, 1);

        $this->assertNotNull($rating);
        $this->assertSame('3.7', $rating['ratingValue']);
    }

    public function testAsksForTheProductSummaryAtThatStoreView(): void
    {
        $this->row = ['rating_summary' => '90', 'reviews_count' => '2'];

        $this->provider()->getRating(42, 3);

        // Entity type 1 is the product row in review_entity; summaries are per store view.
        $this->assertSame([42, 1], $this->entityFilter);
        $this->assertSame(3, $this->storeFilter);
    }

    /**
     * The provider over a collection factory that records its filters and yields the set row.
     *
     * @return NativeAggregateRatingProvider
     */
    private function provider(): NativeAggregateRatingProvider
    {
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturnCallback(fn (): Collection => $this->collection());

        return new NativeAggregateRatingProvider($collectionFactory);
    }

    /**
     * A collection that records its filters and hands back the set row as a summary model.
     *
     * @return Collection
     */
    private function collection(): Collection
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('addEntityFilter')->willReturnCallback(
            function (mixed $entityId, mixed $entityType) use ($collection): Collection {
                $this->entityFilter = [$entityId, $entityType];
                return $collection;
            }
        );
        $collection->method('addStoreFilter')->willReturnCallback(
            function (mixed $storeId) use ($collection): Collection {
                $this->storeFilter = (int) $storeId;
                return $collection;
            }
        );
        $collection->method('getFirstItem')->willReturnCallback(fn (): Summary => $this->summary());

        return $collection;
    }

    /**
     * The set row as a summary model, the way getFirstItem() hands it over. An empty model — which
     * is what a product with no reviews gets — carries no data at all.
     *
     * @return Summary
     */
    private function summary(): Summary
    {
        /** @var Summary $summary */
        $summary = (new \ReflectionClass(Summary::class))->newInstanceWithoutConstructor();
        $summary->setData($this->row);

        return $summary;
    }
}
