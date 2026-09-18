<?php

declare(strict_types=1);

namespace MageOS\Seo\Model;

use Magento\Framework\Model\AbstractModel;
use MageOS\Seo\Model\ResourceModel\CategoryConfig as CategoryConfigResource;

/**
 * One row of per-category SEO configuration, for one store view.
 *
 * store_id 0 holds the values every store view inherits; a row with a store view's own ID
 * overrides them field by field. Reading and merging those two is Category\ConfigRepository's
 * job — this model is the single row.
 */
class CategoryConfig extends AbstractModel
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'mageos_seo_category_config';

    /**
     * @var string
     */
    protected $_eventObject = 'category_config';

    /**
     * Initialize resource model.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(CategoryConfigResource::class);
    }
}
