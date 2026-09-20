<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

/**
 * Decides the order in which a category's SEO settings are looked for.
 *
 * A category's configuration can come from two places at once: from an ancestor category, and
 * from a different store-view scope. Both are "fall back to something less specific", and which
 * of the two counts as less specific is a property of the shop rather than of the code — a
 * single-brand catalogue treats the category tree as the primary axis, a multi-site retailer
 * whose store views carry their own SEO policy treats the scope as the primary one. Both are
 * legitimate, so the order is chosen in Stores → Configuration rather than settled here.
 *
 * An implementation returns the places to look, most specific first; it performs no queries and
 * makes no decision about values. What is found there is resolved field by field by
 * MageOS\Seo\Model\Category\InheritanceResolver.
 *
 * Register an implementation with MageOS\Seo\Model\Category\Inheritance\OrderPool in di.xml and
 * it appears in the admin dropdown automatically.
 *
 * @api
 */
interface CategoryConfigSourceOrderInterface
{
    /**
     * The places to look for a category's settings, most specific first.
     *
     * @param int[] $categoryChain The category itself, then its ancestors, nearest first
     * @param int $storeId The store view being rendered; 0 when there is only the global scope
     * @return array<int, array{category_id: int, store_id: int}>
     */
    public function order(array $categoryChain, int $storeId): array;

    /**
     * A short sentence describing the order, shown beside the option in the admin.
     *
     * @return \Magento\Framework\Phrase
     */
    public function getLabel(): \Magento\Framework\Phrase;
}
