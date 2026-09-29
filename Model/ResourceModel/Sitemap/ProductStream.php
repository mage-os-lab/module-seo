<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel\Sitemap;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Gallery\ReadHandler as GalleryReadHandler;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product as CatalogProductResource;
use Magento\Catalog\Model\ResourceModel\Product\Gallery;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Sitemap\Helper\Data as SitemapHelper;
use Magento\Sitemap\Model\ResourceModel\Catalog\Product as CoreProductResource;
use Magento\Sitemap\Model\Source\Product\Image\IncludeImage;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\ResourceModel\AbstractConnectedResource;
use MageOS\Seo\Model\Sitemap\ItemProvider\ProductRowMapper;

/**
 * The products a store view's sitemap lists, read a page at a time.
 *
 * Magento's own sitemap product resource model reads the whole catalogue in one query and builds
 * every product before the first is written. From 2.4.9 its batch variant builds them one at a time,
 * but still from one query, which the database client buffers whole; before 2.4.9 there is no
 * variant at all. This reads the same products with the same query — the one core builds, from
 * 2.4.8 in `ProductSelectBuilder` and before that inline, identical in both — one page of rows at a
 * time, ordered by entity ID and resumed after the last one, so memory is bounded by the page on
 * every supported version. Images, where the store includes them, are read for a whole page at once
 * rather than a query per product.
 *
 * Core's `prepareSelectStatement()` — the hook it offers for changing the product list, on every
 * version — shapes this query too: the select is passed through it on core's own resource model, so
 * plugins on it apply here. Paging is added afterwards, and any ordering or limit a plugin adds is
 * replaced by it. Plugins on core's other methods — `getCollection()`, or 2.4.8's
 * `ProductSelectBuilder::execute()` — do not reach this query.
 */
class ProductStream extends AbstractConnectedResource
{
    /**
     * Products per page: the sitemap generator's chunk size.
     */
    public const PAGE_SIZE = 1000;

    /**
     * Attribute metadata, by attribute code. Schema, not data, so it holds for the process.
     *
     * @var array<string,array{attribute_id:int|string,table:string,is_global:bool,backend_type:string}>
     */
    private array $attributes = [];

    /**
     * @param CatalogProductResource $productResource
     * @param StoreManagerInterface $storeManager
     * @param Visibility $productVisibility
     * @param Status $productStatus
     * @param Gallery $galleryResource
     * @param GalleryReadHandler $galleryReadHandler
     * @param SitemapHelper $sitemapHelper
     * @param CoreProductResource $coreProductResource
     * @param ProductRowMapper $rowMapper
     * @param Context $context
     * @param string|null $connectionName
     */
    public function __construct(
        private readonly CatalogProductResource $productResource,
        private readonly StoreManagerInterface $storeManager,
        private readonly Visibility $productVisibility,
        private readonly Status $productStatus,
        private readonly Gallery $galleryResource,
        private readonly GalleryReadHandler $galleryReadHandler,
        private readonly SitemapHelper $sitemapHelper,
        private readonly CoreProductResource $coreProductResource,
        private readonly ProductRowMapper $rowMapper,
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
        $this->_init('catalog_product_entity', 'entity_id');
    }

    /**
     * The store view's sitemap products, one at a time, as core's resource model builds them.
     *
     * @param int $storeId
     * @param int $pageSize Rows read per query
     * @return \Generator<DataObject>
     */
    public function stream(int $storeId, int $pageSize = self::PAGE_SIZE): \Generator
    {
        $store       = $this->storeManager->getStore($storeId);
        $storeId     = (int) $store->getId();
        $imagePolicy = (string) $this->sitemapHelper->getProductImageIncludePolicy($storeId);
        $linkField   = $this->productResource->getLinkField();
        $idField     = $this->getIdFieldName();
        $lastId      = 0;

        do {
            $rows = $this->connection()->fetchAll($this->pageSelect($store, $imagePolicy, $lastId, $pageSize));
            if ($rows === []) {
                return;
            }
            $lastId = (int) $rows[array_key_last($rows)][$idField];

            // Core sets the current store before building each product's image URLs, and the rest
            // of generation has run with it set ever since. Kept, so nothing downstream sees a
            // different store than it did with core's resource model.
            $this->storeManager->setCurrentStore($storeId);

            $gallery = $imagePolicy === IncludeImage::INCLUDE_ALL
                ? $this->gallery($rows, $storeId, $linkField)
                : [];

            yield from $this->rowMapper->map($rows, $gallery, $idField, $linkField, $imagePolicy);
        } while (\count($rows) === $pageSize);
    }

    /**
     * One page of the product query: core's select, through core's hook, resumed after the last ID.
     *
     * @param StoreInterface $store
     * @param string $imagePolicy
     * @param int $afterId
     * @param int $pageSize
     * @return Select
     */
    private function pageSelect(StoreInterface $store, string $imagePolicy, int $afterId, int $pageSize): Select
    {
        $select = $this->coreProductResource->prepareSelectStatement($this->productSelect($store, $imagePolicy));

        // A plugin's ordering would break resuming after the last ID; limit() below replaces any
        // limit and offset of its own.
        return $select->reset(Select::ORDER)
            ->where('e.' . $this->getIdFieldName() . ' > ?', $afterId)
            ->order('e.' . $this->getIdFieldName() . ' ' . Select::SQL_ASC)
            ->limit($pageSize);
    }

    /**
     * The product query core's sitemap product resource model runs.
     *
     * Enabled products visible in the catalogue or search, in the store's website, with their
     * canonical URL rewrite for the store where they have one; name and image columns as the image
     * policy needs them.
     *
     * @param StoreInterface $store
     * @param string $imagePolicy
     * @return Select
     */
    private function productSelect(StoreInterface $store, string $imagePolicy): Select
    {
        $connection = $this->connection();
        $storeId    = (int) $store->getId();

        $select = $connection->select()->from(
            ['e' => $this->getMainTable()],
            [$this->getIdFieldName(), $this->productResource->getLinkField(), 'updated_at']
        )->joinInner(
            ['w' => $this->getTable('catalog_product_website')],
            'e.entity_id = w.product_id',
            []
        )->joinLeft(
            ['url_rewrite' => $this->getTable('url_rewrite')],
            'e.entity_id = url_rewrite.entity_id AND url_rewrite.is_autogenerated = 1'
            . ' AND url_rewrite.metadata IS NULL'
            . $connection->quoteInto(' AND url_rewrite.store_id = ?', $storeId)
            . $connection->quoteInto(' AND url_rewrite.entity_type = ?', ProductUrlRewriteGenerator::ENTITY_TYPE),
            ['url' => 'request_path']
        )->where(
            'w.website_id = ?',
            $store->getWebsiteId()
        );

        $this->addInFilter($select, $storeId, 'visibility', $this->productVisibility->getVisibleInSiteIds());
        $this->addInFilter($select, $storeId, 'status', $this->productStatus->getVisibleStatusIds());

        if ($imagePolicy !== IncludeImage::INCLUDE_NONE) {
            $this->joinAttribute($select, $storeId, 'name', 'name');
            if ($imagePolicy === IncludeImage::INCLUDE_ALL) {
                $this->joinAttribute($select, $storeId, 'thumbnail', 'thumbnail');
            } elseif ($imagePolicy === IncludeImage::INCLUDE_BASE) {
                $this->joinAttribute($select, $storeId, 'image', 'image');
            }
        }

        return $select;
    }

    /**
     * Keep rows whose attribute value — the store's own, else the default — is one of the values.
     *
     * @param Select $select
     * @param int $storeId
     * @param string $attributeCode
     * @param array<int|string> $values
     * @return void
     */
    private function addInFilter(Select $select, int $storeId, string $attributeCode, array $values): void
    {
        $attribute = $this->attribute($attributeCode);
        if ($attribute['backend_type'] === 'static') {
            $select->where('e.' . $attributeCode . ' IN(?)', $values);
            return;
        }

        $this->joinAttribute($select, $storeId, $attributeCode);
        if ($attribute['is_global']) {
            $select->where('t1_' . $attributeCode . '.value IN(?)', $values);
            return;
        }

        $value = $this->connection()->getCheckSql(
            't2_' . $attributeCode . '.value_id > 0',
            't2_' . $attributeCode . '.value',
            't1_' . $attributeCode . '.value'
        );
        $select->where('(' . $value . ') IN(?)', $values);
    }

    /**
     * Join an attribute's default value and, unless global, the store's; optionally select it.
     *
     * @param Select $select
     * @param int $storeId
     * @param string $attributeCode
     * @param string|null $column Select the store's value, else the default, under this name
     * @return void
     */
    private function joinAttribute(Select $select, int $storeId, string $attributeCode, ?string $column = null): void
    {
        $connection = $this->connection();
        $attribute  = $this->attribute($attributeCode);
        $linkField  = $this->productResource->getLinkField();
        $default    = 't1_' . $attributeCode;

        $select->joinLeft(
            [$default => $attribute['table']],
            "e.{$linkField} = {$default}.{$linkField}"
            . ' AND ' . $connection->quoteInto($default . '.store_id = ?', Store::DEFAULT_STORE_ID)
            . ' AND ' . $connection->quoteInto($default . '.attribute_id = ?', $attribute['attribute_id']),
            []
        );
        $value = $default . '.value';

        if (!$attribute['is_global']) {
            $scoped = 't2_' . $attributeCode;
            $select->joinLeft(
                [$scoped => $attribute['table']],
                "{$default}.{$linkField} = {$scoped}.{$linkField}"
                . ' AND ' . $default . '.attribute_id = ' . $scoped . '.attribute_id'
                . ' AND ' . $connection->quoteInto($scoped . '.store_id = ?', $storeId),
                []
            );
            $value = $connection->getIfNullSql($scoped . '.value', $value);
        }

        if ($column !== null) {
            $select->columns([$column => $value]);
        }
    }

    /**
     * The gallery rows of a page's products, in one query, grouped by product.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param int $storeId
     * @param string $linkField
     * @return array<int|string,array<int,array<string,mixed>>> link field value => rows in position order
     */
    private function gallery(array $rows, int $storeId, string $linkField): array
    {
        $select = $this->galleryResource->createBatchBaseSelect(
            $storeId,
            (int) $this->galleryReadHandler->getAttribute()->getAttributeId()
        );
        $select->where(
            'entity.' . $linkField . ' IN (?)',
            array_values(array_unique(array_column($rows, $linkField)))
        );

        $gallery = [];
        foreach ($this->connection()->fetchAll($select) as $image) {
            $gallery[(string) $image[$linkField]][] = $image;
        }

        return $gallery;
    }

    /**
     * Metadata of a product attribute, as core's resource model reads it.
     *
     * @param string $attributeCode
     * @return array{attribute_id:int|string,table:string,is_global:bool,backend_type:string}
     */
    private function attribute(string $attributeCode): array
    {
        if (!isset($this->attributes[$attributeCode])) {
            $attribute = $this->productResource->getAttribute($attributeCode);
            if ($attribute === false) {
                // Core's product attributes; an installation without one is broken, not a variant.
                throw new \RuntimeException(sprintf('Product attribute "%s" does not exist.', $attributeCode));
            }

            $this->attributes[$attributeCode] = [
                'attribute_id' => $attribute->getId(),
                'table'        => $attribute->getBackend()->getTable(),
                'is_global'    => $attribute->getIsGlobal() == ScopedAttributeInterface::SCOPE_GLOBAL,
                'backend_type' => (string) $attribute->getBackendType(),
            ];
        }

        return $this->attributes[$attributeCode];
    }
}
