<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Model\ResourceModel\Db\Context;

/**
 * Configurables' links to their variants, for several configurables in one query.
 *
 * Variant\ChildProducts reads a page of configurables' variants in one collection, and a collection
 * refuses a second item with the same ID: configurables that share a variant must be loaded apart,
 * which these links tell it. The link table holds the configurable's link field, which is its row
 * ID where rows are versioned; the entity IDs are returned.
 */
class VariantLinks extends AbstractConnectedResource
{
    /**
     * @param Context $context
     * @param MetadataPool $metadataPool
     * @param string|null $connectionName
     */
    public function __construct(
        Context $context,
        private readonly MetadataPool $metadataPool,
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
        $this->_init('catalog_product_super_link', 'link_id');
    }

    /**
     * The variants of each configurable, by entity ID.
     *
     * @param int[] $parentIds The configurables' entity IDs
     * @return array<int, int[]> configurable entity ID => its variants' entity IDs
     */
    public function forParents(array $parentIds): array
    {
        if ($parentIds === []) {
            return [];
        }

        $linkField  = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
        $connection = $this->connection();
        $select     = $connection->select()
            ->from(['link' => $this->getMainTable()], ['product_id'])
            ->join(
                ['parent' => $this->getTable('catalog_product_entity')],
                'parent.' . $linkField . ' = link.parent_id',
                ['parent_entity_id' => 'entity_id']
            )
            ->where('parent.entity_id IN (?)', array_values($parentIds), \Zend_Db::INT_TYPE);

        $links = [];
        foreach ($connection->fetchAll($select) as $row) {
            $links[(int) $row['parent_entity_id']][] = (int) $row['product_id'];
        }

        return $links;
    }
}
