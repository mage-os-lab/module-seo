<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap\ItemProvider;

use Magento\Sitemap\Model\ItemProvider\CmsPageConfigReader;
use Magento\Sitemap\Model\ResourceModel\Cms\PageFactory;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;
use MageOS\Seo\Model\Sitemap\SitemapItemFactory;

/**
 * CMS pages, as core's `Magento\Sitemap\Model\ItemProvider\CmsPage` lists them.
 */
class CmsPage extends AbstractEntityProvider
{
    /**
     * @param CmsPageConfigReader $configReader
     * @param SitemapItemFactory $itemFactory
     * @param PageFactory $pageFactory
     */
    public function __construct(
        CmsPageConfigReader          $configReader,
        SitemapItemFactory           $itemFactory,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($configReader, $itemFactory);
    }

    /**
     * @inheritdoc
     */
    public function getType(): string
    {
        return self::TYPE_PAGES;
    }

    /**
     * @inheritdoc
     */
    protected function rows(int $storeId, bool $stream): iterable|false
    {
        return $this->pageFactory->create()->getCollection($storeId);
    }

    /**
     * @inheritdoc
     */
    protected function entityType(): string
    {
        return SitemapItemInterface::ENTITY_CMS_PAGE;
    }
}
