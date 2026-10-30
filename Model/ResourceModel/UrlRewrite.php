<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Cms\Model\Page;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Model\ResourceModel\Db\Context;

/**
 * Reads canonical URL rewrites, restricted to entities a visitor can actually reach.
 *
 * A rewrite row outlives the state of the thing it points at: disabling a product, deactivating a
 * category or unpublishing a CMS page leaves its url_rewrite row exactly where it was. Listing
 * those rows as hreflang alternates advertises pages that are not public, so every query here
 * joins the entity's own published state at the row's store view.
 *
 * CMS pages are read from the page side instead (see cmsSelect()), because core serves a page
 * by its identifier whether or not it has a rewrite.
 *
 * Products and categories keep that state in EAV, one row per store view with store 0 as the
 * fallback, so each attribute needs a pair of joins and COALESCE between them. The join to the
 * entity table looks redundant — url_rewrite.entity_id is the entity ID — but it is what supplies
 * the link field: on installations with content staging the EAV tables key on row_id rather than
 * entity_id, and MetadataPool is what knows the difference.
 */
class UrlRewrite extends AbstractConnectedResource
{
    public const TYPE_PRODUCT  = 'product';
    public const TYPE_CATEGORY = 'category';
    public const TYPE_CMS_PAGE = 'cms-page';

    /**
     * @param EavConfig $eavConfig
     * @param MetadataPool $metadataPool
     * @param Context $context
     * @param string|null $connectionName
     */
    public function __construct(
        private readonly EavConfig $eavConfig,
        private readonly MetadataPool $metadataPool,
        Context $context,
        ?string $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }

    /**
     * Initialize the main table and primary key.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('url_rewrite', 'url_rewrite_id');
    }

    /**
     * Canonical request paths of one entity, ordered so the first row per store view wins.
     *
     * @param string $entityType One of the TYPE_* constants
     * @param int $entityId
     * @return array<int, array{store_id: string, request_path: string}>
     */
    public function getPathsForEntity(string $entityType, int $entityId): array
    {
        return $this->getPathsForEntities($entityType, [$entityId]);
    }

    /**
     * Canonical request paths of several entities of one type, in one query.
     *
     * Ordered by entity, then so the first row per store view wins — the sitemap asks for a chunk
     * of entities at a time rather than one query each.
     *
     * @param string $entityType One of the TYPE_* constants
     * @param int[] $entityIds
     * @return array<int, array{entity_id: string, store_id: string, request_path: string}>
     */
    public function getPathsForEntities(string $entityType, array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }

        if ($entityType === self::TYPE_CMS_PAGE) {
            $select = $this->cmsSelect()
                ->where('cms_page.page_id IN (?)', array_map('intval', $entityIds))
                ->order('cms_page.page_id ASC')
                ->order('main_table.url_rewrite_id ASC');

            return $this->connection()->fetchAll($select);
        }

        $select = $this->canonicalSelect($entityType, ['entity_id', 'store_id', 'request_path'])
            ->where('main_table.entity_id IN (?)', array_map('intval', $entityIds))
            ->order('main_table.entity_id ASC')
            ->order('main_table.url_rewrite_id ASC');

        return $this->connection()->fetchAll($select);
    }

    /**
     * Canonical request paths of the CMS pages in a translation group, best candidate first.
     *
     * Several pages of one group can be live in the same store view — a translation assigned to
     * it, and another assigned to all store views. Rows come ordered so the first per store view is
     * the one to use: see orderCmsCandidates().
     *
     * @param string $group A normalised translation group
     * @return array<int, array{store_id: string, request_path: string}>
     */
    public function getPathsForCmsGroup(string $group): array
    {
        return $this->getPathsForCmsGroups([$group]);
    }

    /**
     * Canonical request paths of the CMS pages in several translation groups, in one query.
     *
     * Ordered by group, then best candidate first, as getPathsForCmsGroup().
     *
     * @param string[] $groups Normalised translation groups
     * @return array<int, array{hreflang_group: string, store_id: string, request_path: string}>
     */
    public function getPathsForCmsGroups(array $groups): array
    {
        if ($groups === []) {
            return [];
        }

        $select = $this->cmsSelect();
        $this->joinCmsTranslationGroup($select);
        $select->where('cms_config.hreflang_group IN (?)', array_values($groups))
            ->order('hreflang_group ASC');
        $this->orderCmsCandidates($select);
        $select->order('main_table.url_rewrite_id ASC');

        return $this->connection()->fetchAll($select);
    }

    /**
     * Join each CMS page's translation group, which lives on the page's global row.
     *
     * @param Select $select A cmsSelect()
     * @return void
     */
    private function joinCmsTranslationGroup(Select $select): void
    {
        $select->joinLeft(
            ['cms_config' => $this->getTable('mageos_seo_cms_page_config')],
            'cms_config.page_id = cms_page.page_id AND cms_config.store_id = 0',
            ['hreflang_group' => 'cms_config.hreflang_group']
        );
    }

    /**
     * Order CMS candidates so the first row per store view is the page to link.
     *
     * A page assigned to the store view itself beats one assigned to all store views — it is the
     * translation made for that store — and between equals the lowest page ID wins, so the answer
     * never depends on the order rows happen to come back in.
     *
     * @param Select $select A cmsSelect()
     * @return void
     */
    private function orderCmsCandidates(Select $select): void
    {
        $select->columns(['assigned_store_id' => 'cms_page_store.store_id'])
            ->order('assigned_store_id DESC')
            ->order('cms_page.page_id ASC');
    }

    /**
     * Select over the published CMS pages, one row per page and store view it is served in.
     *
     * Core serves a CMS page by its identifier, with or without a URL rewrite: the CMS router
     * matches the identifier and the core sitemap lists it. Pages imported by the Data Migration
     * Tool or by SQL have no cms-page rewrite, and saving them later does not add one unless the
     * identifier or the store views change. So the query starts from the page and its store views,
     * and takes the canonical rewrite's path where there is one, the identifier where there is not.
     * Store 0 in cms_page_store means every store view, so the store table expands it.
     *
     * @return Select
     */
    private function cmsSelect(): Select
    {
        return $this->connection()->select()
            ->from(
                ['cms_page' => $this->getTable('cms_page')],
                ['entity_id' => 'cms_page.page_id']
            )
            ->join(
                ['cms_page_store' => $this->getTable('cms_page_store')],
                'cms_page_store.page_id = cms_page.page_id',
                []
            )
            ->join(
                ['store' => $this->getTable('store')],
                'store.store_id > 0 AND cms_page_store.store_id IN (0, store.store_id)',
                ['store_id' => 'store.store_id']
            )
            ->joinLeft(
                ['main_table' => $this->getMainTable()],
                implode(' AND ', [
                    'main_table.entity_id = cms_page.page_id',
                    'main_table.store_id = store.store_id',
                    $this->connection()->quoteInto('main_table.entity_type = ?', self::TYPE_CMS_PAGE),
                    'main_table.redirect_type = 0',
                    'main_table.is_autogenerated = 1',
                    'main_table.metadata IS NULL',
                ]),
                ['request_path' => new Expression('COALESCE(main_table.request_path, cms_page.identifier)')]
            )
            ->where('cms_page.is_active = ?', 1)
            // The 404 page is served at any unknown URL, never at its identifier.
            ->where('cms_page.identifier <> ?', Page::NOROUTE_PAGE_ID)
            ->distinct(true);
    }

    /**
     * Select over the canonical rewrites of one entity type, filtered to published entities.
     *
     * @param string $entityType
     * @param string[] $columns
     * @return Select
     */
    private function canonicalSelect(string $entityType, array $columns): Select
    {
        $select = $this->connection()->select()
            ->from(['main_table' => $this->getMainTable()], $columns)
            ->where('main_table.entity_type = ?', $entityType)
            ->where('main_table.redirect_type = ?', 0)
            ->where('main_table.is_autogenerated = ?', 1)
            // Rows with metadata carry a category context (category-nested product URLs);
            // hreflang alternates must point at the canonical root rewrite.
            ->where('main_table.metadata IS NULL');

        $this->applyPublishedFilter($select, $entityType);

        return $select;
    }

    /**
     * Restrict a select to entities that are actually published at the row's store view.
     *
     * @param Select $select
     * @param string $entityType
     * @return void
     */
    private function applyPublishedFilter(Select $select, string $entityType): void
    {
        switch ($entityType) {
            case self::TYPE_PRODUCT:
                $linkField = $this->linkField(ProductInterface::class);
                $select->join(
                    ['product' => $this->getTable('catalog_product_entity')],
                    'product.entity_id = main_table.entity_id',
                    []
                );
                $this->joinEavCondition(
                    $select,
                    'catalog_product_entity_int',
                    'product.' . $linkField,
                    $this->attributeId(ProductInterface::class, 'status'),
                    'status',
                    [Status::STATUS_ENABLED]
                );
                $this->joinEavCondition(
                    $select,
                    'catalog_product_entity_int',
                    'product.' . $linkField,
                    $this->attributeId(ProductInterface::class, 'visibility'),
                    'visibility',
                    [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH]
                );
                break;

            case self::TYPE_CATEGORY:
                $linkField = $this->linkField(CategoryInterface::class);
                $select->join(
                    ['category' => $this->getTable('catalog_category_entity')],
                    'category.entity_id = main_table.entity_id',
                    []
                );
                $this->joinEavCondition(
                    $select,
                    'catalog_category_entity_int',
                    'category.' . $linkField,
                    $this->attributeId(CategoryInterface::class, 'is_active'),
                    'is_active',
                    [1]
                );
                break;

        }
    }

    /**
     * Join an EAV attribute for the row's store view and require one of the accepted values.
     *
     * Two joins per attribute: the store-view row overrides the store 0 row when it exists, which
     * is the same fallback the storefront applies.
     *
     * @param Select $select
     * @param string $table EAV value table
     * @param string $linkColumn Qualified column holding the entity's link field
     * @param int $attributeId
     * @param string $alias Unique per attribute within the select
     * @param int[] $acceptedValues
     * @return void
     */
    private function joinEavCondition(
        Select $select,
        string $table,
        string $linkColumn,
        int $attributeId,
        string $alias,
        array $acceptedValues
    ): void {
        $connection   = $this->connection();
        $defaultAlias = $alias . '_default';
        $storeAlias   = $alias . '_store';

        $select->joinLeft(
            [$defaultAlias => $this->getTable($table)],
            $connection->quoteInto(
                $defaultAlias . '.' . $this->eavLinkColumn($table) . ' = ' . $linkColumn
                . ' AND ' . $defaultAlias . '.attribute_id = ?'
                . ' AND ' . $defaultAlias . '.store_id = 0',
                $attributeId
            ),
            []
        )->joinLeft(
            [$storeAlias => $this->getTable($table)],
            $connection->quoteInto(
                $storeAlias . '.' . $this->eavLinkColumn($table) . ' = ' . $linkColumn
                . ' AND ' . $storeAlias . '.attribute_id = ?',
                $attributeId
            ) . ' AND ' . $storeAlias . '.store_id = main_table.store_id',
            []
        )->where(
            $connection->quoteInto(
                'COALESCE(' . $storeAlias . '.value, ' . $defaultAlias . '.value) IN (?)',
                $acceptedValues
            )
        );
    }

    /**
     * The column an EAV value table uses to point back at its entity.
     *
     * @param string $table
     * @return string
     */
    private function eavLinkColumn(string $table): string
    {
        return str_contains($table, 'catalog_product') ? $this->linkField(ProductInterface::class)
            : $this->linkField(CategoryInterface::class);
    }

    /**
     * The column the EAV tables key on: entity_id, or row_id where content staging is installed.
     *
     * @param string $entityInterface
     * @return string
     */
    private function linkField(string $entityInterface): string
    {
        return $this->metadataPool->getMetadata($entityInterface)->getLinkField();
    }

    /**
     * The numeric ID of an attribute, which is what the EAV value tables are keyed by.
     *
     * @param string $entityInterface
     * @param string $attributeCode
     * @return int
     */
    private function attributeId(string $entityInterface, string $attributeCode): int
    {
        $entityType = $entityInterface === ProductInterface::class
            ? \Magento\Catalog\Model\Product::ENTITY
            : \Magento\Catalog\Model\Category::ENTITY;

        return (int) $this->eavConfig->getAttribute($entityType, $attributeCode)->getAttributeId();
    }
}
