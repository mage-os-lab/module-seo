<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Category\Inheritance;

use Magento\Framework\Phrase;
use MageOS\Seo\Api\CategoryConfigSourceOrderInterface;

/**
 * Exhaust the store view across the whole tree before falling back to the global scope.
 *
 * own@store, parent@store, grandparent@store, …, then own@global, parent@global, …
 *
 * The one to want when store views carry their own SEO policy: anything said for this store
 * view, anywhere up the tree, outranks anything said globally — so a rule set for one market on
 * a top-level category is not quietly undone by a global setting on a subcategory. With a single
 * store view it behaves identically to category first, because there is only one scope to
 * exhaust.
 */
class StoreFirstOrder implements CategoryConfigSourceOrderInterface
{
    /**
     * @inheritdoc
     */
    public function order(array $categoryChain, int $storeId): array
    {
        $sources = [];

        if ($storeId > 0) {
            foreach ($categoryChain as $categoryId) {
                $sources[] = ['category_id' => (int) $categoryId, 'store_id' => $storeId];
            }
        }

        foreach ($categoryChain as $categoryId) {
            $sources[] = ['category_id' => (int) $categoryId, 'store_id' => 0];
        }

        return $sources;
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): Phrase
    {
        return __('Store view first — any setting for this store view wins over any global one');
    }
}
