<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Category;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use MageOS\Seo\Model\CategoryConfig;
use MageOS\Seo\Model\Category\Inheritance\OrderPool;
use MageOS\Seo\Model\Config as SeoConfig;
use MageOS\Seo\Model\ResourceModel\CategoryConfig as CategoryConfigResource;
use MageOS\Seo\Model\ResourceModel\CategoryConfig\CollectionFactory;

class ConfigRepository implements ResetAfterRequestInterface
{
    /** @var array<string, mixed[]> */
    private array $cache = [];

    /**
     * @param CollectionFactory $collectionFactory
     * @param CategoryConfigResource $resource
     * @param InheritanceResolver $resolver
     * @param OrderPool $orderPool
     * @param SeoConfig $seoConfig
     */
    public function __construct(
        private readonly CollectionFactory      $collectionFactory,
        private readonly CategoryConfigResource $resource,
        private readonly InheritanceResolver    $resolver,
        private readonly OrderPool              $orderPool,
        private readonly SeoConfig              $seoConfig
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
        $ancestorIds   = $this->ancestorIds($categoryId, $categoryPath);
        $categoryChain = [$categoryId, ...$ancestorIds];
        $strategy      = $this->seoConfig->getCategoryInheritanceStrategy();

        // The ancestors belong in the key. A caller that passes no path gets the category's own
        // row and nothing inherited, and keying on the category alone would then hand that
        // un-inherited row to every later caller in the request — including the ones that did
        // pass a path. The strategy belongs in it for the same reason: it changes the answer.
        $cacheKey = $categoryId . '_' . $storeId . '_' . $strategy . '_' . implode('.', $ancestorIds);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $rows    = $this->loadRows($categoryChain, $storeId);
        $sources = [];

        foreach ($this->orderPool->get($strategy)->order($categoryChain, $storeId) as $source) {
            $sources[] = $rows[$source['category_id']][$source['store_id']] ?? [];
        }

        $row = $this->resolver->resolve($sources);

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
     * Load the rows of several categories in one query, keyed by category ID and store view.
     *
     * Kept unmerged, unlike before: which of a category's two scopes outranks an ancestor's is
     * the configured strategy's decision, and merging them here would settle it in advance.
     *
     * @param int[] $categoryIds
     * @param int $storeId
     * @return array<int, array<int, mixed[]>>
     */
    private function loadRows(array $categoryIds, int $storeId = 0): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('category_id', ['in' => $categoryIds]);
        $collection->addFieldToFilter('store_id', $storeId > 0 ? ['in' => [0, $storeId]] : ['eq' => 0]);

        $rows = [];
        /** @var CategoryConfig $config */
        foreach ($collection as $config) {
            $row = $config->getData();
            $rows[(int) ($row['category_id'] ?? 0)][(int) ($row['store_id'] ?? 0)] = $row;
        }

        return $rows;
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
        // The whole memo, not this category's entry. Descendants inherit from the nearest
        // configured ancestor, so saving one category changes what every category below it
        // resolves to — and since the path is part of the key, a single category can hold
        // several entries anyway.
        $this->cache = [];
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
