<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Review;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Review\Model\ResourceModel\Review\Summary\CollectionFactory;
use Magento\Review\Model\Review;
use Magento\Review\Test\Fixture\Review as ReviewFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Review\NativeAggregateRatingProvider;
use PHPUnit\Framework\TestCase;

/**
 * The native provider against the summary row core's own aggregation writes.
 *
 * @magentoDbIsolation enabled
 */
class NativeAggregateRatingProviderTest extends TestCase
{
    /**
     * An approved review without rating votes: core still writes the store's summary row, counting
     * the review with a rating_summary of 0. That is no rating, not a zero-star one (#47).
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    #[DataFixture(ReviewFixture::class, ['entity_pk_value' => '$product.id$'], as: 'review')]
    public function testNoRatingWhenTheReviewsCarryNoVotes(): void
    {
        /** @var Review $review */
        $review = DataFixtureStorageManager::getStorage()->get('review');
        $review->aggregate();

        $productId = (int) $review->getEntityPkValue();
        $storeId   = (int) $review->getStoreId();

        // The state the fix is for; if core stops writing it, this test no longer covers anything.
        $summary = Bootstrap::getObjectManager()->get(CollectionFactory::class)->create()
            ->addEntityFilter($productId, (int) $review->getEntityId())
            ->addStoreFilter($storeId)
            ->getFirstItem();
        $this->assertSame(1, (int) $summary->getReviewsCount(), 'Core should count the review.');
        $this->assertSame(0, (int) $summary->getRatingSummary(), 'Core should write no rating for it.');

        $this->assertNull(
            Bootstrap::getObjectManager()->get(NativeAggregateRatingProvider::class)->getRating($productId, $storeId)
        );
    }
}
