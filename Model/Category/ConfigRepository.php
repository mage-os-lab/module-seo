<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Category;

use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use MageOS\Seo\Model\CategoryConfig;
use MageOS\Seo\Model\ResourceModel\CategoryConfig as CategoryConfigResource;
use MageOS\Seo\Model\ResourceModel\CategoryConfig\CollectionFactory;

class ConfigRepository implements ResetAfterRequestInterface
{
    /** @var array<string, mixed[]> */
    private array $cache = [];

    /**
     * @param CollectionFactory $collectionFactory
     * @param CategoryConfigResource $resource
     */
    public function __construct(
        private readonly CollectionFactory      $collectionFactory,
        private readonly CategoryConfigResource $resource
    ) {
    }

    /**
     * Load SEO config for a category, with store-view fallback.
     *
     * When $storeId > 0, loads both the store-specific row and the global row
     * (store_id = 0) and merges them: store-specific values win. Falls back
     * gracefully to the global row when no store-specific row exists.
     *
     * Walks up the category path to find the nearest ancestor with a configured
     * template if the category itself has none. Ancestor lookup also respects
     * $storeId, and the whole path is read in one query.
     *
     * @param int $categoryId
     * @param string[] $categoryPath Array of ancestor IDs from root to leaf (e.g. ['1','2','3','14'])
     * @param int $storeId Store view ID (0 = global default)
     * @return mixed[]
     */
    public function getForCategory(int $categoryId, array $categoryPath = [], int $storeId = 0): array
    {
        $cacheKey = "{$categoryId}_{$storeId}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $ancestorIds = $this->ancestorIds($categoryId, $categoryPath);
        $rows        = $this->loadRows([$categoryId, ...$ancestorIds], $storeId);
        $row         = $rows[$categoryId] ?? [];

        // If no template configured, walk up the path to inherit from nearest ancestor
        if (empty($row['schema_template'])) {
            foreach ($ancestorIds as $ancestorId) {
                $ancestorRow = $rows[$ancestorId] ?? [];
                if (!empty($ancestorRow['schema_template'])) {
                    // Inherit template from ancestor, but keep non-null/non-empty own values.
                    // Use explicit check rather than array_filter to preserve legitimate 0 values
                    // (e.g. item_list_enabled = 0 must not be silently discarded).
                    $ownValues = array_filter($row, fn ($v) => $v !== null && $v !== '');
                    $row       = $ownValues + $ancestorRow;
                    break;
                }
            }
        }

        $this->cache[$cacheKey] = $row;
        return $row;
    }

    /**
     * The category's ancestors, nearest first, as the inheritance walk needs them.
     *
     * Self and the root categories (1 = root, 2 = default category) carry no inheritable
     * configuration of their own.
     *
     * @param int $categoryId
     * @param string[] $categoryPath Ancestor IDs from root to leaf
     * @return int[]
     */
    private function ancestorIds(int $categoryId, array $categoryPath): array
    {
        $ancestorIds = [];
        foreach (array_reverse($categoryPath) as $pathId) {
            $ancestorId = (int) $pathId;
            if ($ancestorId === $categoryId || $ancestorId <= 2) {
                continue;
            }
            $ancestorIds[] = $ancestorId;
        }

        return array_values(array_unique($ancestorIds));
    }

    /**
     * Load the rows of several categories in one query, keyed by category ID.
     *
     * When $storeId > 0 each category's global row (store_id = 0) and store-view row are
     * merged, so that store-specific non-null/non-empty values take precedence.
     *
     * @param int[] $categoryIds
     * @param int $storeId
     * @return array<int, mixed[]>
     */
    private function loadRows(array $categoryIds, int $storeId = 0): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('category_id', ['in' => $categoryIds]);
        $collection->addFieldToFilter('store_id', $storeId > 0 ? ['in' => [0, $storeId]] : ['eq' => 0]);
        // Global row first, store-view row second, so the store view's values are applied last.
        $collection->setOrder('store_id', DataCollection::SORT_ORDER_ASC);

        $rows = [];
        /** @var CategoryConfig $config */
        foreach ($collection as $config) {
            $row        = $config->getData();
            $categoryId = (int) ($row['category_id'] ?? 0);
            $rows[$categoryId] = isset($rows[$categoryId]) ? $this->merge($rows[$categoryId], $row) : $row;
        }

        return $rows;
    }

    /**
     * Apply a row over a base row: a value that says something wins, otherwise the base stands.
     *
     * @param mixed[] $base
     * @param mixed[] $row
     * @return mixed[]
     */
    private function merge(array $base, array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value !== null && $value !== '') {
                $base[$key] = $value;
            } elseif (!\array_key_exists($key, $base)) {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    /**
     * Save or update SEO config for a category and store view.
     *
     * Only the given fields are written; anything already stored for that category and store
     * view is left as it is.
     *
     * @param int $categoryId
     * @param mixed[] $data
     * @param int $storeId Store view ID (0 = global default)
     * @return void
     */
    public function save(int $categoryId, array $data, int $storeId = 0): void
    {
        // JSON-encode array values before persistence
        if (isset($data['enabled_fields']) && \is_array($data['enabled_fields'])) {
            $data['enabled_fields'] = json_encode(array_values($data['enabled_fields']));
        }
        if (isset($data['override_fields']) && \is_array($data['override_fields'])) {
            $data['override_fields'] = json_encode($data['override_fields']);
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('category_id', ['eq' => $categoryId]);
        $collection->addFieldToFilter('store_id', ['eq' => $storeId]);

        /** @var CategoryConfig $config An empty model when this category has no row yet */
        $config = $collection->getFirstItem();
        $config->addData($data);
        $config->setData('category_id', $categoryId);
        $config->setData('store_id', $storeId);
        // Let the database set updated_at: a value in the UPDATE statement takes precedence over
        // the column's ON UPDATE CURRENT_TIMESTAMP, which would freeze it at the loaded value.
        $config->unsetData('updated_at');

        $this->resource->save($config);
        unset($this->cache["{$categoryId}_{$storeId}"]);
    }

    /**
     * Decode JSON fields from a config row into arrays.
     *
     * @param mixed[] $row
     * @return mixed[]
     */
    public function decode(array $row): array
    {
        if (!empty($row['enabled_fields'])) {
            $decoded = json_decode((string) $row['enabled_fields'], true);
            $row['enabled_fields'] = \is_array($decoded) ? $decoded : [];
        } else {
            $row['enabled_fields'] = [];
        }

        if (!empty($row['override_fields'])) {
            $decoded = json_decode((string) $row['override_fields'], true);
            $row['override_fields'] = \is_array($decoded) ? $decoded : [];
        } else {
            $row['override_fields'] = [];
        }

        return $row;
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
