<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Organisation extends AbstractDb
{
    /**
     * Initialize resource model table and primary key.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('mage-os_seo_organisation', 'entity_id');
    }
}
