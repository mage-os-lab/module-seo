<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel\CmsPageConfig;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use MageOS\Seo\Model\CmsPageConfig as CmsPageConfigModel;
use MageOS\Seo\Model\ResourceModel\CmsPageConfig as CmsPageConfigResource;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'entity_id';

    /**
     * Initialize the collection model and resource model.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(CmsPageConfigModel::class, CmsPageConfigResource::class);
    }
}
