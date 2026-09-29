<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel\Organization;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use MageOS\Seo\Model\Organization;
use MageOS\Seo\Model\ResourceModel\Organization as OrganizationResource;

class Collection extends AbstractCollection
{
    /**
     * Initialize collection model and resource model.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(Organization::class, OrganizationResource::class);
    }
}
