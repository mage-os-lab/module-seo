<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel\Organisation;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use MageOS\Seo\Model\Organisation;
use MageOS\Seo\Model\ResourceModel\Organisation as OrganisationResource;

class Collection extends AbstractCollection
{
    /**
     * Initialize collection model and resource model.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(Organisation::class, OrganisationResource::class);
    }
}
