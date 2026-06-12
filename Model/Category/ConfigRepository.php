<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Category;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class ConfigRepository
{
    /** @var \Magento\Framework\DB\Adapter\AdapterInterface */
    private AdapterInterface $connection;

    /** @var array<string, mixed[]> */
    private array $cache = [];

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
        $this->connection = $resourceConnection->getConnection();
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
     * $storeId.
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

        $row = $this->loadRow($categoryId, $storeId);

        // If no template configured, walk up the path to inherit from nearest ancestor
        if (empty($row['schema_template']) && !empty($categoryPath)) {
            $ancestors = array_reverse($categoryPath);
            foreach ($ancestors as $ancestorId) {
                $ancestorIdInt = (int) $ancestorId;
                if ($ancestorIdInt === $categoryId || $ancestorIdInt <= 2) {
                    // Skip self and root categories (1 = root, 2 = default category)
                    continue;
                }
                $ancestorRow = $this->loadRow($ancestorIdInt, $storeId);
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
     * Load a raw DB row for a category ID, with store-view fallback.
     *
     * When $storeId > 0, fetches both the store-specific row and the global row
     * (store_id = 0) ordered store_id ASC, then merges them so that store-specific
     * non-null/non-empty values take precedence over global values.
     *
     * @param int $categoryId
     * @param int $storeId
     * @return mixed[]
     */
    private function loadRow(int $categoryId, int $storeId = 0): array
    {
        $table  = $this->connection->getTableName('mage-os_seo_category_config');
        $select = $this->connection->select()
            ->from($table)
            ->where('category_id = ?', $categoryId);

        if ($storeId > 0) {
            $select->where('store_id IN (?)', [0, $storeId])
                   ->order('store_id ASC'); // global row first, store-specific row second

            $rows = $this->connection->fetchAll($select);
            if (empty($rows)) {
                return [];
            }

            // Merge: iterate rows in order (global then store-specific).
            // Later values win for non-null/non-empty, preserving global as base.
            $merged = [];
            foreach ($rows as $row) {
                foreach ($row as $key => $value) {
                    if ($value !== null && $value !== '') {
                        $merged[$key] = $value;
                    } elseif (!isset($merged[$key])) {
                        $merged[$key] = $value;
                    }
                }
            }
            return $merged;
        }

        $select->where('store_id = ?', 0);
        $row = $this->connection->fetchRow($select);
        return \is_array($row) ? $row : [];
    }

    /**
     * Save or update SEO config for a category and store view.
     *
     * @param int $categoryId
     * @param mixed[] $data
     * @param int $storeId Store view ID (0 = global default)
     * @return void
     */
    public function save(int $categoryId, array $data, int $storeId = 0): void
    {
        $table = $this->connection->getTableName('mage-os_seo_category_config');

        // JSON-encode array values before persistence
        if (isset($data['enabled_fields']) && \is_array($data['enabled_fields'])) {
            $data['enabled_fields'] = json_encode(array_values($data['enabled_fields']));
        }
        if (isset($data['override_fields']) && \is_array($data['override_fields'])) {
            $data['override_fields'] = json_encode($data['override_fields']);
        }

        $data['category_id'] = $categoryId;
        $data['store_id']    = $storeId;

        $this->connection->insertOnDuplicate($table, $data, array_keys($data));
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
}
