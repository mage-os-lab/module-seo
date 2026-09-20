<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Category\Inheritance;

use Magento\Framework\Phrase;
use MageOS\Seo\Api\CategoryConfigSourceOrderInterface;

/**
 * Exhaust a category's own scopes before moving up the tree.
 *
 * own@store, own@global, parent@store, parent@global, grandparent@store, …
 *
 * The default, and the one to want when the category tree is what carries meaning: a setting
 * made on a category applies to that category, whether it was made globally or for one store
 * view, and only a category that says nothing at all defers to its parent. A parent's
 * store-specific setting never overrules a child's global one, because the child is the more
 * specific subject.
 */
class CategoryFirstOrder implements CategoryConfigSourceOrderInterface
{
    /**
     * @inheritdoc
     */
    public function order(array $categoryChain, int $storeId): array
    {
        $sources = [];

        foreach ($categoryChain as $categoryId) {
            if ($storeId > 0) {
                $sources[] = ['category_id' => (int) $categoryId, 'store_id' => $storeId];
            }
            $sources[] = ['category_id' => (int) $categoryId, 'store_id' => 0];
        }

        return $sources;
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): Phrase
    {
        return __('Category first — a category\'s own settings win, then its nearest ancestor\'s');
    }
}
