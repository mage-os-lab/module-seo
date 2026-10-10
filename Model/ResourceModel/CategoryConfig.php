<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;

/**
 * A category's SEO settings, per store view.
 *
 * A change purges the pages tagged with the category's SEO tag: its own, those of every category
 * beneath it, which inherit from it, and those of products whose schema reads it.
 */
class CategoryConfig extends AbstractSeoRowResource
{
    /**
     * Initialize the main table and primary key.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('mageos_seo_category_config', 'entity_id');
    }

    /**
     * @inheritDoc
     */
    protected function pageTags(AbstractModel $row, bool $removed): array
    {
        return [$this->cacheTags->category((int) $row->getData('category_id'))];
    }
}
