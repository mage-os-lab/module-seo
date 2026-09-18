<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CategoryConfig extends AbstractDb
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
}
