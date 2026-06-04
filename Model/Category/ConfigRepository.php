<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Category;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

class ConfigRepository
{
    /** @var \Magento\Framework\DB\Adapter\AdapterInterface */
    private AdapterInterface $connection;

    /** @var array<int, mixed[]> */
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
     * Load SEO config for a category.
     *
     * Walks up the path to find the nearest ancestor with a configured template
     * if the category itself has none.
     *
     * @param int $categoryId
     * @param string[] $categoryPath Array of ancestor IDs from root to leaf (e.g. ['1','2','3','14'])
     * @return mixed[]
     */
    public function getForCategory(int $categoryId, array $categoryPath = []): array
    {
        if (isset($this->cache[$categoryId])) {
            return $this->cache[$categoryId];
        }

        // Try the category itself first
        $row = $this->loadRow($categoryId);

        // If no template configured, walk up the path to inherit from nearest ancestor
        if (empty($row['schema_template']) && !empty($categoryPath)) {
            $ancestors = array_reverse($categoryPath);
            foreach ($ancestors as $ancestorId) {
                $ancestorIdInt = (int) $ancestorId;
                if ($ancestorIdInt === $categoryId || $ancestorIdInt <= 2) {
                    // Skip self and root categories (1 = root, 2 = default category)
                    continue;
                }
                $ancestorRow = $this->loadRow($ancestorIdInt);
                if (!empty($ancestorRow['schema_template'])) {
                    // Inherit template and enabled fields from ancestor,
                    // but use the actual category's own overrides if present.
                    $ownValues = array_filter($row);
                    $row = $ownValues + $ancestorRow;
                    break;
                }
            }
        }

        $this->cache[$categoryId] = $row;
        return $row;
    }

    /**
     * Load a raw DB row for a category ID.
     *
     * @param int $categoryId
     * @return mixed[]
     */
    private function loadRow(int $categoryId): array
    {
        $table = $this->connection->getTableName('mage-os_seo_category_config');
        $row   = $this->connection->fetchRow(
            $this->connection->select()
                ->from($table)
                ->where('category_id = ?', $categoryId)
        );
        return \is_array($row) ? $row : [];
    }

    /**
     * Save or update SEO config for a category.
     *
     * @param int $categoryId
     * @param mixed[] $data
     * @return void
     */
    public function save(int $categoryId, array $data): void
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

        $this->connection->insertOnDuplicate($table, $data, array_keys($data));
        unset($this->cache[$categoryId]);
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
