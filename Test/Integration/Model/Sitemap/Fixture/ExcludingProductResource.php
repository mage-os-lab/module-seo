<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Sitemap\Fixture;

use Magento\Sitemap\Model\ResourceModel\Catalog\Product;

/**
 * Core's sitemap product resource model with its select hook used as a third party would use it:
 * to leave a product out of the sitemap.
 *
 * It also orders the select, newest product first, as a careless plugin might — the opposite of the
 * order paging resumes in — so a test can see that paging still reads every other product.
 */
class ExcludingProductResource extends Product
{
    /**
     * The product the hook leaves out.
     *
     * @var int
     */
    public static int $excludedId = 0;

    /**
     * @inheritdoc
     */
    public function prepareSelectStatement(\Magento\Framework\DB\Select $select)
    {
        return $select->where('e.entity_id <> ?', self::$excludedId)
            ->order('e.entity_id DESC');
    }
}
