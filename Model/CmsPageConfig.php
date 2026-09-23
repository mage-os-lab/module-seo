<?php

declare(strict_types=1);

namespace MageOS\Seo\Model;

use Magento\Framework\Model\AbstractModel;
use MageOS\Seo\Model\ResourceModel\CmsPageConfig as CmsPageConfigResource;

/**
 * Per-CMS-page SEO configuration.
 *
 * store_id 0 holds the value every store view inherits; a row with a store view's own ID
 * overrides it for that store view only.
 */
class CmsPageConfig extends AbstractModel
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'mageos_seo_cms_page_config';

    /**
     * @var string
     */
    protected $_eventObject = 'cms_page_config';

    /**
     * Initialize the resource model.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(CmsPageConfigResource::class);
    }
}
