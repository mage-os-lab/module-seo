<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class CmsPageConfig extends AbstractDb
{
    /**
     * Initialize the main table and primary key.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('mageos_seo_cms_page_config', 'entity_id');
    }
}
