<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Cms;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use MageOS\Seo\Model\CmsPageConfig;
use MageOS\Seo\Model\ResourceModel\CmsPageConfig as CmsPageConfigResource;
use MageOS\Seo\Model\ResourceModel\CmsPageConfig\CollectionFactory;

/**
 * Per-CMS-page SEO configuration, with store-view fallback.
 *
 * Deliberately simpler than the category equivalent: CMS pages form no tree, so there is nothing
 * to inherit from and no source-order strategy to apply. The only fallback is scope — a store
 * view's own row, then the global (store 0) row.
 */
class ConfigRepository implements ResetAfterRequestInterface
{
    /**
     * Rows already read this request, keyed "{pageId}_{storeId}".
     *
     * @var array<string, mixed[]>
     */
    private array $cache = [];

    /**
     * @param CollectionFactory $collectionFactory
     * @param CmsPageConfigResource $resource
     */
    public function __construct(
        private readonly CollectionFactory     $collectionFactory,
        private readonly CmsPageConfigResource $resource
    ) {
    }

    /**
     * Load the configuration for a CMS page, the store view's row winning over the global one.
     *
     * @param int $pageId
     * @param int $storeId Store view ID (0 = global default)
     * @return mixed[]
     */
    public function getForPage(int $pageId, int $storeId = 0): array
    {
        $cacheKey = "{$pageId}_{$storeId}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('page_id', ['eq' => $pageId]);
        $collection->addFieldToFilter('store_id', $storeId > 0 ? ['in' => [0, $storeId]] : ['eq' => 0]);

        $row = [];

        /** @var CmsPageConfig $config */
        foreach ($collection as $config) {
            $candidate = $config->getData();

            // The store view's own row wins, whichever order the rows arrive in; a row that sets
            // nothing does not blank out the global one.
            if ($row === [] || ((int) ($candidate['store_id'] ?? 0) > 0 && $this->saysSomething($candidate))) {
                $row = $candidate;
            }
        }

        $this->cache[$cacheKey] = $row;

        return $row;
    }

    /**
     * The translation group a CMS page belongs to, or null when it has none.
     *
     * Read from the global row only: the group identifies the page, not a store view's presentation
     * of it (see the column's comment in db_schema.xml).
     *
     * @param int $pageId
     * @return string|null
     */
    public function getHreflangGroup(int $pageId): ?string
    {
        $group = (string) ($this->getForPage($pageId)['hreflang_group'] ?? '');

        return $group === '' ? null : $group;
    }

    /**
     * Save or update the configuration for a CMS page and store view.
     *
     * @param int $pageId
     * @param mixed[] $data
     * @param int $storeId Store view ID (0 = global default)
     * @return void
     */
    public function save(int $pageId, array $data, int $storeId = 0): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('page_id', ['eq' => $pageId]);
        $collection->addFieldToFilter('store_id', ['eq' => $storeId]);

        /** @var CmsPageConfig $config An empty model when this page has no row yet */
        $config = $collection->getFirstItem();
        $config->addData($data);
        $config->setData('page_id', $pageId);
        $config->setData('store_id', $storeId);
        // Let the database set updated_at: a value in the UPDATE statement takes precedence over
        // the column's ON UPDATE CURRENT_TIMESTAMP, which would freeze it at the loaded value.
        $config->unsetData('updated_at');

        $this->resource->save($config);

        $this->cache = [];
    }

    /**
     * Remove every row of the given pages, across all store views.
     *
     * The table carries no foreign key to cms_page — see the schema comment — so deleting a page
     * has to take its configuration with it here.
     *
     * @param int[] $pageIds
     * @return int Number of rows removed
     */
    public function deleteForPages(array $pageIds): int
    {
        $pageIds = array_values(array_unique(array_map('intval', $pageIds)));
        if ($pageIds === []) {
            return 0;
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('page_id', ['in' => $pageIds]);

        $deleted = 0;
        foreach ($collection as $config) {
            $this->resource->delete($config);
            $deleted++;
        }

        if ($deleted > 0) {
            $this->cache = [];
        }

        return $deleted;
    }

    /**
     * Whether a row configures anything at all.
     *
     * @param mixed[] $row
     * @return bool
     */
    private function saysSomething(array $row): bool
    {
        return ($row['robots_meta'] ?? null) !== null && $row['robots_meta'] !== '';
    }

    /**
     * Clear the memoised rows between worker-mode requests.
     *
     * @return void
     */
    public function _resetState(): void // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- framework interface
    {
        $this->cache = [];
    }
}
