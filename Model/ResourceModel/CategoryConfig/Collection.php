<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel\CategoryConfig;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use MageOS\Seo\Model\CategoryConfig;
use MageOS\Seo\Model\ResourceModel\CategoryConfig as CategoryConfigResource;

class Collection extends AbstractCollection
{
    /**
     * Initialize collection model and resource model.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(CategoryConfig::class, CategoryConfigResource::class);
    }
}
