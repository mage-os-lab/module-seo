<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel;

use Magento\Framework\Model\AbstractModel;

/**
 * A product's SEO overrides, per store view.
 *
 * A change purges the product's pages.
 */
class ProductOverride extends AbstractSeoRowResource
{
    /**
     * Initialize the main table and primary key.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('mageos_seo_product_override', 'entity_id');
    }

    /**
     * @inheritDoc
     */
    protected function pageTags(AbstractModel $row, bool $removed): array
    {
        return [$this->cacheTags->product((int) $row->getData('product_id'))];
    }
}
