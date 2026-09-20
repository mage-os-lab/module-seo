<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Model\ResourceModel\Db\Context;

/**
 * Reads canonical URL rewrites, restricted to entities a visitor can actually reach.
 *
 * A rewrite row outlives the state of the thing it points at: disabling a product, deactivating a
 * category or unpublishing a CMS page leaves its url_rewrite row exactly where it was. Listing
 * those rows as hreflang alternates advertises pages that are not public, so every query here
 * joins the entity's own published state at the row's store view.
 *
 * Products and categories keep that state in EAV, one row per store view with store 0 as the
 * fallback, so each attribute needs a pair of joins and COALESCE between them. The join to the
 * entity table looks redundant — url_rewrite.entity_id is the entity ID — but it is what supplies
 * the link field: on installations with content staging the EAV tables key on row_id rather than
 * entity_id, and MetadataPool is what knows the difference.
 */
class UrlRewrite extends AbstractDb
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
     * The read connection, or a failure that says which connection is missing.
     *
     * AbstractDb::getConnection() returns false when the resource's connection name is not
     * configured in env.php. Every query in this class is a read against the default connection,
     * so false is a deployment fault, not a case to fall back from: reporting it here names the
     * cause, where letting it through produces "call to a member function on bool" further down.
     *
     * @return AdapterInterface
     * @throws \RuntimeException
     */
    private function connection(): AdapterInterface
    {
        $connection = $this->getConnection();

        if (!$connection instanceof AdapterInterface) {
            throw new \RuntimeException(
                sprintf(
                    'MageOS_Seo: no database connection named "%s" is configured;'
                    . ' url_rewrite cannot be read.',
                    $this->connectionName
                )
            );
        }

        return $connection;
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
        $select = $this->canonicalSelect($entityType, ['store_id', 'request_path'])
            ->where('main_table.entity_id = ?', $entityId)
            ->order('main_table.url_rewrite_id ASC');

        return $this->connection()->fetchAll($select);
    }

    /**
     * Every entity of a type, as a statement to be walked row by row.
     *
     * Deliberately not a collection or fetchAll(): the caller groups consecutive rows per entity
     * and yields them one entity at a time, so a 100k-product catalogue never lands in memory.
     * Ordering by entity_id first is what makes that grouping correct.
     *
     * @param string $entityType One of the TYPE_* constants
     * @param int[] $storeIds
     * @return \Zend_Db_Statement_Interface
     */
    public function queryPathsForType(string $entityType, array $storeIds): \Zend_Db_Statement_Interface
    {
        $select = $this->canonicalSelect($entityType, ['entity_id', 'store_id', 'request_path'])
            ->where('main_table.store_id IN (?)', $storeIds)
            ->order('main_table.entity_id ASC')
            ->order('main_table.url_rewrite_id ASC');

        return $this->connection()->query($select);
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

            case self::TYPE_CMS_PAGE:
                // CMS keeps its state in flat columns, and its own store assignment table, where
                // store 0 means every store view.
                $select->join(
                    ['cms_page' => $this->getTable('cms_page')],
                    'cms_page.page_id = main_table.entity_id',
                    []
                )->join(
                    ['cms_page_store' => $this->getTable('cms_page_store')],
                    'cms_page_store.page_id = cms_page.page_id'
                    . ' AND cms_page_store.store_id IN (0, main_table.store_id)',
                    []
                )->where('cms_page.is_active = ?', 1);
                $select->distinct(true);
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
