<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Robots;

/**
 * The robots directive each page gets, for whole sets of products and categories at once.
 *
 * As the page's own head has it: this module's value (the entity's own, an ancestor category's, or
 * the page type's default), else core's Design → Search Engine Robots default. A directive another
 * module changes while the page renders is not seen: only what is stored can be read for a whole
 * catalogue.
 *
 * @api
 */
interface PageDirectivesInterface
{
    /**
     * Each product page's directive.
     *
     * @param int[] $productIds
     * @param int $storeId
     * @return array<int, string> product ID => directive
     */
    public function forProducts(array $productIds, int $storeId): array;

    /**
     * Each category page's directive, inherited through the category's ancestors.
     *
     * A category that no longer exists has no page, and is left out.
     *
     * @param int[] $categoryIds
     * @param int $storeId
     * @return array<int, string> category ID => directive
     */
    public function forCategories(array $categoryIds, int $storeId): array;

    /**
     * Whether a directive keeps the page out of search results: `noindex`, or `none`.
     *
     * @param string $directive
     * @return bool
     */
    public function isNoindex(string $directive): bool;
}
