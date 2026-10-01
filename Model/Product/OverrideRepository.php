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
        return $this->cache["{$productId}_{$storeId}"] ??= $this->load([$productId], $storeId)[$productId];
    }

    /**
     * Load several products' overrides in one query, each merged as getForProduct() merges one.
     *
     * Not memoised: the sitemap reads a whole catalogue through this a chunk at a time, and keeping
     * every product's row for the rest of the process would undo its streaming.
     *
     * @param int[] $productIds
     * @param int $storeId
     * @return array<int,mixed[]> product ID => merged overrides, for every ID asked for
     */
    public function getForProducts(array $productIds, int $storeId): array
    {
        return $this->load($productIds, $storeId);
    }

    /**
     * Read and merge the rows of the given products for a store view.
     *
     * @param int[] $productIds
     * @param int $storeId
     * @return array<int,mixed[]>
     */
    private function load(array $productIds, int $storeId): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        $fields     = array_fill_keys($productIds, []);
        $robotsMeta = array_fill_keys($productIds, null);
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('product_id', ['in' => $productIds]);
        $collection->addFieldToFilter('store_id', ['in' => [0, $storeId]]);
        // Global row first, store-view row second: store-specific fields win over global ones.
        $collection->setOrder('store_id', DataCollection::SORT_ORDER_ASC);

        /** @var ProductOverride $override */
        foreach ($collection as $override) {
            $productId = (int) $override->getData('product_id');
            $decoded   = $override->getData('override_fields');
            // Store-specific fields win over global (store_id=0)
            $fields[$productId][] = !empty($decoded) ? (json_decode((string) $decoded, true) ?? []) : [];
            if (!empty($override->getData('robots_meta'))) {
                $robotsMeta[$productId] = $override->getData('robots_meta');
            }
        }

        // One merge per product, of its (at most two) rows.
        $overrideFields = array_map(static fn (array $rows): array => array_merge([], ...$rows), $fields);

        $merged = [];
        foreach ($productIds as $productId) {
            $merged[$productId] = [
                'override_fields' => $overrideFields[$productId],
                'robots_meta'     => $robotsMeta[$productId],
            ];
        }

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
        $collection->addFieldToFilter('product_id', ['eq' => $productId]);
        $collection->addFieldToFilter('store_id', ['eq' => $storeId]);

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
