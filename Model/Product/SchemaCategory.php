<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The category whose settings choose a product's schema template on its page (review low item,
 * decision D9b).
 *
 * Only the product's categories in the store's own tree count; a category in another website's
 * tree never does. Of those:
 * - the category in the URL: the `category` parameter a category-path product URL carries, when it
 *   is one of them;
 * - otherwise the deepest, ties to the lowest ID, so the choice is the same however the page is
 *   reached.
 *
 * Never the `current_category` registry: on a product page core fills it from the category the
 * visitor last browsed (Catalog\Helper\Product::initProduct()), and the page cache would then serve
 * one visitor's choice to everyone. BreadcrumbListProvider reads the URL's category for the same
 * reason.
 */
class SchemaCategory
{
    /**
     * @param RequestInterface $request
     * @param StoreManagerInterface $storeManager
     * @param CategoryCollectionFactory $categoryCollectionFactory
     */
    public function __construct(
        private readonly RequestInterface          $request,
        private readonly StoreManagerInterface     $storeManager,
        private readonly CategoryCollectionFactory $categoryCollectionFactory
    ) {
    }

    /**
     * The category ID, or 0 when the product is in no category of the store's tree.
     *
     * @param ProductInterface $product
     * @param int $storeId
     * @return int
     */
    public function forProduct(ProductInterface $product, int $storeId): int
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $assigned = array_map('intval', (array) $product->getCategoryIds());
        if ($assigned === []) {
            return 0;
        }

        $levels = $this->levelsUnderRoot($assigned, $storeId);
        if ($levels === []) {
            return 0;
        }

        $inUrl = (int) $this->request->getParam('category');
        if ($inUrl > 0 && isset($levels[$inUrl])) {
            return $inUrl;
        }

        // Deepest first, then the lowest ID.
        uksort(
            $levels,
            static fn (int $a, int $b): int => [$levels[$b], $a] <=> [$levels[$a], $b]
        );

        return (int) array_key_first($levels);
    }

    /**
     * The level of each of the categories that is in the store's tree, in one query.
     *
     * @param int[] $categoryIds
     * @param int $storeId
     * @return array<int, int> category ID => level
     */
    private function levelsUnderRoot(array $categoryIds, int $storeId): array
    {
        /** @var Store $store */
        $store  = $this->storeManager->getStore($storeId);
        $rootId = (int) $store->getRootCategoryId();

        $collection = $this->categoryCollectionFactory->create();
        $collection->addFieldToFilter('entity_id', ['in' => $categoryIds]);
        $collection->addFieldToFilter('path', ['like' => '1/' . $rootId . '/%']);

        $levels = [];
        foreach ($collection as $category) {
            $levels[(int) $category->getId()] = (int) $category->getLevel();
        }

        return $levels;
    }
}
