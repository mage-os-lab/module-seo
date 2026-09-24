<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Category;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * The ancestor path of a category, in the form ConfigRepository expects.
 *
 * Category SEO settings are inherited from the nearest configured ancestor, and the path is what
 * makes that walk possible: given no path, ConfigRepository reads a category's own row and
 * nothing else. The admin form has always passed one — which is why it shows inherited values —
 * and the storefront providers did not, so what a merchant saw in the admin never reached a page.
 *
 * One class rather than the expression repeated at each provider, because the two ways of
 * getting there differ: a category page has the category in hand, a product page has only an ID
 * and has to load it.
 */
class PathResolver
{
    /**
     * @param CategoryRepositoryInterface $categoryRepository
     * @param CategoryCollectionFactory $categoryCollectionFactory
     */
    public function __construct(
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly CategoryCollectionFactory   $categoryCollectionFactory
    ) {
    }

    /**
     * The paths of several categories, read in one query.
     *
     * For the sitemap, which has only IDs and lists every category. A path is the same in every
     * store view, so none is asked for. An ID that no longer resolves is absent from the result.
     *
     * @param int[] $categoryIds
     * @return array<int,string[]> category ID => ancestor IDs from root to leaf
     */
    public function forCategoryIds(array $categoryIds): array
    {
        $categoryIds = array_values(array_unique(array_map('intval', $categoryIds)));
        if ($categoryIds === []) {
            return [];
        }

        $collection = $this->categoryCollectionFactory->create();
        $collection->addFieldToFilter('entity_id', ['in' => $categoryIds]);

        $paths = [];
        foreach ($collection as $category) {
            $paths[(int) $category->getId()] = $this->split((string) $category->getPath());
        }

        return $paths;
    }

    /**
     * The path of a category already loaded, as ancestor IDs from root to leaf.
     *
     * @param CategoryInterface $category
     * @return string[]
     */
    public function forCategory(CategoryInterface $category): array
    {
        return $this->split((string) $category->getPath());
    }

    /**
     * The path of a category known only by ID, loading it if necessary.
     *
     * CategoryRepositoryInterface keeps the categories it has loaded for the rest of the request,
     * and the pages calling this are cacheable, so the read lands on a cache miss only.
     *
     * An ID that no longer resolves yields no path rather than an error: a product assigned to a
     * deleted category still has its own schema to emit.
     *
     * @param int $categoryId
     * @param int $storeId
     * @return string[]
     */
    public function forCategoryId(int $categoryId, int $storeId): array
    {
        if ($categoryId === 0) {
            return [];
        }

        try {
            return $this->forCategory($this->categoryRepository->get($categoryId, $storeId));
        } catch (NoSuchEntityException) {
            return [];
        }
    }

    /**
     * Split a stored path, treating an unset one as no ancestors rather than one empty ancestor.
     *
     * @param string $path
     * @return string[]
     */
    private function split(string $path): array
    {
        return $path === '' ? [] : explode('/', $path);
    }
}
