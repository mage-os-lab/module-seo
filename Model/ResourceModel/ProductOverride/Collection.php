<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel\ProductOverride;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use MageOS\Seo\Model\ProductOverride;
use MageOS\Seo\Model\ResourceModel\ProductOverride as ProductOverrideResource;

class Collection extends AbstractCollection
{
    /**
     * Initialize collection model and resource model.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(ProductOverride::class, ProductOverrideResource::class);
    }
}
