<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap\ItemProvider;

use Magento\Sitemap\Model\ItemProvider\ProductConfigReader;
use Magento\Sitemap\Model\ResourceModel\Catalog\Batch\ProductFactory as StreamingProductFactory;
use Magento\Sitemap\Model\ResourceModel\Catalog\ProductFactory;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;
use MageOS\Seo\Model\Sitemap\SitemapItemFactory;

/**
 * Products, as core's `Magento\Sitemap\Model\ItemProvider\Product` lists them.
 *
 * Two resource models, both core's: the list for `getItems()` is read exactly as core's standard
 * provider reads it, so core's generator writes what it always wrote; `iterateItems()` reads core's
 * batch resource model, which yields one product at a time — the one core's own memory-optimised
 * generator uses — so a large catalogue is never held at once.
 */
class Product extends AbstractEntityProvider
{
    /**
     * @param ProductConfigReader $configReader
     * @param SitemapItemFactory $itemFactory
     * @param ProductFactory $productFactory
     * @param StreamingProductFactory $streamingProductFactory
     */
    public function __construct(
        ProductConfigReader                      $configReader,
        SitemapItemFactory                       $itemFactory,
        private readonly ProductFactory          $productFactory,
        private readonly StreamingProductFactory $streamingProductFactory
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
            ? $this->streamingProductFactory->create()->getCollection($storeId)
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
