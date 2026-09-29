<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Review;

use Magento\Review\Model\ResourceModel\Review\Summary\CollectionFactory;
use Magento\Review\Model\Review\Summary;
use MageOS\Seo\Api\AggregateRatingProviderInterface;

/**
 * Aggregate rating from Magento's native, pre-aggregated review_entity_summary table.
 *
 * Low priority so any third-party review bridge overrides it. Returns null when a product has no
 * reviews so a zero-review AggregateRating node is never emitted.
 */
class NativeAggregateRatingProvider implements AggregateRatingProviderInterface
{
    /**
     * Review entity type id for products in review_entity / review_entity_summary.
     *
     * Resolving this from review_entity.entity_code would cost an uncached SELECT on every product
     * page for a value seeded once at install; core's own Summary collection defaults to the same
     * literal.
     */
    private const ENTITY_TYPE_PRODUCT = 1;

    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getRating(int $productId, int $storeId): ?array
    {
        $collection = $this->collectionFactory->create();
        $collection->addEntityFilter($productId, self::ENTITY_TYPE_PRODUCT);
        $collection->addStoreFilter($storeId);

        /** @var Summary $summary An empty model when this product has no summary row */
        $summary      = $collection->getFirstItem();
        $reviewsCount = (int) $summary->getReviewsCount();

        if ($reviewsCount < 1) {
            return null;
        }

        // rating_summary is a 0–100 percentage; convert to a 5-star scale.
        $ratingValue = round(((float) $summary->getRatingSummary()) / 20, 1);

        return [
            'ratingValue' => (string) $ratingValue,
            'reviewCount' => (string) $reviewsCount,
            'bestRating'  => '5',
            'worstRating' => '1',
        ];
    }

    /**
     * @inheritdoc
     */
    public function getPriority(): int
    {
        return 100;
    }
}
