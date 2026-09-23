<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap\ItemProvider;

use Magento\Sitemap\Model\ItemProvider\CategoryConfigReader;
use Magento\Sitemap\Model\ResourceModel\Catalog\CategoryFactory;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;
use MageOS\Seo\Model\Sitemap\SitemapItemFactory;

/**
 * Categories, as core's `Magento\Sitemap\Model\ItemProvider\Category` lists them.
 */
class Category extends AbstractEntityProvider
{
    /**
     * @param CategoryConfigReader $configReader
     * @param SitemapItemFactory $itemFactory
     * @param CategoryFactory $categoryFactory
     */
    public function __construct(
        CategoryConfigReader             $configReader,
        SitemapItemFactory               $itemFactory,
        private readonly CategoryFactory $categoryFactory
    ) {
        parent::__construct($configReader, $itemFactory);
    }

    /**
     * @inheritdoc
     */
    public function getType(): string
    {
        return self::TYPE_CATEGORIES;
    }

    /**
     * @inheritdoc
     */
    protected function rows(int $storeId, bool $stream): iterable|false
    {
        return $this->categoryFactory->create()->getCollection($storeId);
    }

    /**
     * @inheritdoc
     */
    protected function entityType(): string
    {
        return SitemapItemInterface::ENTITY_CATEGORY;
    }
}
