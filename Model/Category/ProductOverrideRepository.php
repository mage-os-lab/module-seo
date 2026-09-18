<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Category;

use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use MageOS\Seo\Model\ProductOverride;
use MageOS\Seo\Model\ResourceModel\ProductOverride as ProductOverrideResource;
use MageOS\Seo\Model\ResourceModel\ProductOverride\CollectionFactory;

class ProductOverrideRepository implements ResetAfterRequestInterface
{
    /** @var array<string, mixed[]> */
    private array $cache = [];

    /**
     * @param CollectionFactory $collectionFactory
     * @param ProductOverrideResource $resource
     */
    public function __construct(
        private readonly CollectionFactory       $collectionFactory,
        private readonly ProductOverrideResource $resource
    ) {
    }

    /**
     * Load per-product field overrides.
     *
     * Merges store-specific overrides on top of the store-0 (all stores) row.
     *
     * @param int $productId
     * @param int $storeId
     * @return mixed[]
     */
    public function getForProduct(int $productId, int $storeId): array
    {
        $cacheKey = "{$productId}_{$storeId}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('product_id', $productId);
        $collection->addFieldToFilter('store_id', ['in' => [0, $storeId]]);
        // Global row first, store-view row second: store-specific fields win over global ones.
        $collection->setOrder('store_id', DataCollection::SORT_ORDER_ASC);

        $merged    = ['override_fields' => [], 'robots_meta' => null];
        $allFields = [];

        /** @var ProductOverride $override */
        foreach ($collection as $override) {
            $fields = $override->getData('override_fields');
            $fields = !empty($fields)
                ? (json_decode((string) $fields, true) ?? [])
                : [];
            // Store-specific fields win over global (store_id=0)
            $allFields[] = $fields;
            if (!empty($override->getData('robots_meta'))) {
                $merged['robots_meta'] = $override->getData('robots_meta');
            }
        }

        $merged['override_fields'] = array_merge([], ...$allFields);

        $this->cache[$cacheKey] = $merged;
        return $merged;
    }

    /**
     * Save per-product overrides for a given product + store.
     *
     * Only the given fields are written; anything already stored for that product and store
     * view is left as it is.
     *
     * @param int $productId
     * @param int $storeId
     * @param mixed[] $data
     * @return void
     */
    public function save(int $productId, int $storeId, array $data): void
    {
        if (isset($data['override_fields']) && \is_array($data['override_fields'])) {
            $data['override_fields'] = json_encode($data['override_fields']);
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('product_id', $productId);
        $collection->addFieldToFilter('store_id', $storeId);

        /** @var ProductOverride $override An empty model when this product has no row yet */
        $override = $collection->getFirstItem();
        $override->addData($data);
        $override->setData('product_id', $productId);
        $override->setData('store_id', $storeId);
        // Let the database set updated_at: a value in the UPDATE statement takes precedence over
        // the column's ON UPDATE CURRENT_TIMESTAMP, which would freeze it at the loaded value.
        $override->unsetData('updated_at');

        $this->resource->save($override);
        unset($this->cache["{$productId}_{$storeId}"]);
    }

    /**
     * Clear the memoised rows between worker-mode requests.
     *
     * The collections and the resource model resolve their connection themselves, so nothing
     * else is held here.
     *
     * @return void
     */
    public function _resetState(): void // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- framework interface
    {
        $this->cache = [];
    }
}
