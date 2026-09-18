<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ProductOverride extends AbstractDb
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
}
