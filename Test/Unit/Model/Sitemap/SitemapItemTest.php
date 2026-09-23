<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap;

use Magento\Framework\DataObject;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;
use MageOS\Seo\Model\Sitemap\SitemapItem;
use PHPUnit\Framework\TestCase;

class SitemapItemTest extends TestCase
{
    public function testCoreFieldsAreCoresOwn(): void
    {
        $images = new DataObject(['title' => 'Shirt']);
        $item   = new SitemapItem('shirt.html', '0.5', 'daily', '2026-09-01 10:00:00', $images);

        $this->assertSame('shirt.html', $item->getUrl());
        $this->assertSame('0.5', $item->getPriority());
        $this->assertSame('daily', $item->getChangeFrequency());
        $this->assertSame('2026-09-01 10:00:00', $item->getUpdatedAt());
        $this->assertSame($images, $item->getImages());
    }

    public function testTheEntityIsKept(): void
    {
        $item = new SitemapItem('shirt.html', '0.5', 'daily', null, null, SitemapItemInterface::ENTITY_PRODUCT, 42);

        $this->assertSame(SitemapItemInterface::ENTITY_PRODUCT, $item->getEntityType());
        $this->assertSame(42, $item->getEntityId());
    }

    public function testAnItemFromAProviderThatDoesNotKnowHasNoEntity(): void
    {
        $item = new SitemapItem('elsewhere.html', '0.5', 'daily');

        $this->assertNull($item->getEntityType());
        $this->assertNull($item->getEntityId());
    }

    public function testTheBagStartsEmpty(): void
    {
        $this->assertSame([], (new SitemapItem('a.html', '0.5', 'daily'))->getDataBag());
    }

    public function testUpdatingFilesOneEntryAndLeavesTheOthers(): void
    {
        $alternates = new DataObject(['codes' => ['en-GB']]);
        $robots     = new DataObject(['directive' => 'NOINDEX']);
        $replaced   = new DataObject(['directive' => 'INDEX']);

        $item = new SitemapItem('a.html', '0.5', 'daily');
        $item->updateDataBag('hreflang', $alternates);
        $item->updateDataBag('robots', $robots);
        $item->updateDataBag('robots', $replaced);

        $this->assertSame(['hreflang' => $alternates, 'robots' => $replaced], $item->getDataBag());
    }

    public function testSettingReplacesTheWholeBag(): void
    {
        $kept = new DataObject();
        $item = new SitemapItem('a.html', '0.5', 'daily', null, null, null, null, ['old' => new DataObject()]);

        $item->setDataBag(['kept' => $kept]);

        $this->assertSame(['kept' => $kept], $item->getDataBag());
    }

    public function testAnEntryThatIsNotAnObjectIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SitemapItem('a.html', '0.5', 'daily'))->setDataBag(['robots' => 'NOINDEX']);
    }

    public function testAnEntryWithoutAnExtensionCodeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SitemapItem('a.html', '0.5', 'daily'))->setDataBag([new DataObject()]);
    }
}
