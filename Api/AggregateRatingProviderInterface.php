<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * Supplies product review aggregate ratings for Product structured data.
 *
 * Providers form a priority pool: the highest-priority provider that returns data for a product
 * wins. The native Magento reviews provider ships as a low-priority fallback; a third-party review
 * system (Yotpo, Trustpilot, Okendo, …) registers a higher-priority provider in its own di.xml to
 * take over — MageOS_Seo is never modified.
 *
 * @api
 */
interface AggregateRatingProviderInterface
{
    /**
     * Return aggregate rating data for a product, or null when this provider has none.
     *
     * Shape: ['ratingValue' => '4.3', 'reviewCount' => '17', 'bestRating' => '5', 'worstRating' => '1'].
     *
     * @param int $productId
     * @param int $storeId
     * @return array<string, string>|null
     */
    public function getRating(int $productId, int $storeId): ?array;

    /**
     * Pool priority. Higher wins. The native provider uses 100; bridges use 200+.
     *
     * @return int
     */
    public function getPriority(): int;
}
