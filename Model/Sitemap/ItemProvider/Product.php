<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap\ItemProvider;

use Magento\Sitemap\Model\ItemProvider\ProductConfigReader;
use Magento\Sitemap\Model\ResourceModel\Catalog\ProductFactory;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;
use MageOS\Seo\Model\ResourceModel\Sitemap\ProductStream;
use MageOS\Seo\Model\Sitemap\SitemapItemFactory;

/**
 * Products, as core's `Magento\Sitemap\Model\ItemProvider\Product` lists them.
 *
 * The list for `getItems()` is read from core's resource model exactly as core's provider reads
 * it, so core's generator writes what it always wrote. `iterateItems()` reads the same products a
 * page at a time from this module's ProductStream — on every supported version — so a large
 * catalogue is never held at once.
 */
class Product extends AbstractEntityProvider
{
    /**
     * @param ProductConfigReader $configReader
     * @param SitemapItemFactory $itemFactory
     * @param ProductFactory $productFactory
     * @param ProductStream $productStream
     */
    public function __construct(
        ProductConfigReader             $configReader,
        SitemapItemFactory              $itemFactory,
        private readonly ProductFactory $productFactory,
        private readonly ProductStream  $productStream
    ) {
        parent::__construct($configReader, $itemFactory);
    }

    /**
     * @inheritdoc
     */
    public function getType(): string
    {
        return self::TYPE_PRODUCTS;
    }

    /**
     * @inheritdoc
     */
    protected function rows(int $storeId, bool $stream): iterable|false
    {
        return $stream
            ? $this->productStream->stream($storeId)
            : $this->productFactory->create()->getCollection($storeId);
    }

    /**
     * @inheritdoc
     */
    protected function entityType(): string
    {
        return SitemapItemInterface::ENTITY_PRODUCT;
    }
}
