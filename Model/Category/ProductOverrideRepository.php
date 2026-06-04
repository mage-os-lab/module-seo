<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Category;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class ProductOverrideRepository
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

        $table = $this->connection->getTableName('mage-os_seo_product_override');

        $rows = $this->connection->fetchAll(
            $this->connection->select()
                ->from($table)
                ->where('product_id = ?', $productId)
                ->where('store_id IN (?)', [0, $storeId])
                ->order('store_id ASC') // 0 first, store-specific second
        );

        $merged = ['override_fields' => [], 'robots_meta' => null];
        $allFields = [];

        foreach ($rows as $row) {
            $fields = !empty($row['override_fields'])
                ? (json_decode((string) $row['override_fields'], true) ?? [])
                : [];
            // Store-specific fields win over global (store_id=0)
            $allFields[] = $fields;
            if (!empty($row['robots_meta'])) {
                $merged['robots_meta'] = $row['robots_meta'];
            }
        }

        $merged['override_fields'] = array_merge([], ...$allFields);

        $this->cache[$cacheKey] = $merged;
        return $merged;
    }

    /**
     * Save per-product overrides for a given product + store.
     *
     * @param int $productId
     * @param int $storeId
     * @param mixed[] $data
     * @return void
     */
    public function save(int $productId, int $storeId, array $data): void
    {
        $table = $this->connection->getTableName('mage-os_seo_product_override');

        if (isset($data['override_fields']) && \is_array($data['override_fields'])) {
            $data['override_fields'] = json_encode($data['override_fields']);
        }

        $data['product_id'] = $productId;
        $data['store_id']   = $storeId;

        $this->connection->insertOnDuplicate($table, $data, array_keys($data));
        unset($this->cache["{$productId}_{$storeId}"]);
    }
}
