<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap\ItemProvider;

use Magento\Framework\DataObject;
use Magento\Sitemap\Model\ItemProvider\StoreUrlConfigReader;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;
use MageOS\Seo\Model\Sitemap\SitemapItemFactory;

/**
 * The store view's home page, as core's `Magento\Sitemap\Model\ItemProvider\StoreUrl` lists it.
 *
 * One item with an empty URL — the store's base URL — and no entity of its own; it is filed with
 * the pages.
 */
class StoreUrl extends AbstractEntityProvider
{
    /**
     * Names core's store URL config reader, which is what dependency injection supplies.
     *
     * @param StoreUrlConfigReader $configReader
     * @param SitemapItemFactory $itemFactory
     */
    public function __construct( // phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod -- narrows the type
        StoreUrlConfigReader $configReader,
        SitemapItemFactory   $itemFactory
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
        return [new DataObject(['url' => ''])];
    }

    /**
     * @inheritdoc
     */
    protected function entityType(): string
    {
        return SitemapItemInterface::ENTITY_STORE;
    }
}
