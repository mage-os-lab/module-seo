<?php

declare(strict_types=1);

namespace MageOS\Seo\Model;

use Magento\Framework\Model\AbstractModel;
use MageOS\Seo\Model\ResourceModel\ProductOverride as ProductOverrideResource;

/**
 * One row of per-product SEO field overrides, for one store view.
 *
 * store_id 0 holds the values every store view inherits; a row with a store view's own ID
 * overrides them field by field. Reading and merging those two is
 * Category\ProductOverrideRepository's job — this model is the single row.
 */
class ProductOverride extends AbstractModel
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'mageos_seo_product_override';

    /**
     * @var string
     */
    protected $_eventObject = 'product_override';

    /**
     * Initialize resource model.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(ProductOverrideResource::class);
    }
}
