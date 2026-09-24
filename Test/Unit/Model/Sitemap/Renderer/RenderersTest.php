<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap\Renderer;

use Magento\Framework\DataObject;
use Magento\Framework\Escaper;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Sitemap\Renderer\CoreFields;
use MageOS\Seo\Model\Sitemap\Renderer\Images;
use MageOS\Seo\Model\Sitemap\SitemapItem;
use MageOS\Seo\Model\Store\CanonicalBaseUrl;
use PHPUnit\Framework\TestCase;

/**
 * The markup of core's row fields and images. That it is byte-for-byte what core writes — its
 * escaping included — is covered against core's own generator by
 * Test/Integration/Model/Sitemap/GeneratorTest.
 *
 * Core's Escaper fetches its helpers from the application's object manager, which a unit test does
 * not have; it is stood in for with htmlspecialchars(), which is what it does for these values.
 */
class RenderersTest extends TestCase
{
    public function testCoreFieldsAreWrittenInCoresOrderAndFormat(): void
    {
        $item = new SitemapItem('/shirt.html', '0.5', 'daily', '2026-09-01 10:00:00');

        $this->assertSame(
            '<loc>https://shop.test/shirt.html</loc>'
            . '<lastmod>' . date('c', (int) strtotime('2026-09-01 10:00:00')) . '</lastmod>'
            . '<changefreq>daily</changefreq><priority>0.5</priority>',
            $this->coreFields()->render($item, 1)
        );
    }

    public function testTheHomePageIsTheBaseUrl(): void
    {
        $this->assertStringStartsWith(
            '<loc>https://shop.test/</loc>',
            $this->coreFields()->render(new SitemapItem('', '1.0', 'daily'), 1)
        );
    }

    public function testFieldsWithoutAValueAreLeftOut(): void
    {
        // Core's config readers answer an empty string for a setting with no value.
        $this->assertSame(
            '<loc>https://shop.test/a.html</loc>',
            $this->coreFields()->render(new SitemapItem('a.html', '', ''), 1)
        );
    }

    public function testImagesAreWrittenWithTheirPageMapThumbnail(): void
    {
        $images = new DataObject([
            'title'      => 'Blue & white shirt',
            'thumbnail'  => 'https://shop.test/media/thumb.jpg',
            'collection' => [
                new DataObject(['url' => 'https://shop.test/media/a.jpg', 'caption' => 'Front <view>']),
                new DataObject(['url' => 'https://shop.test/media/b.jpg']),
            ],
        ]);

        $this->assertSame(
            '<image:image><image:loc>https://shop.test/media/a.jpg</image:loc>'
            . '<image:title>Blue &amp; white shirt</image:title>'
            . '<image:caption>Front &lt;view&gt;</image:caption></image:image>'
            . '<image:image><image:loc>https://shop.test/media/b.jpg</image:loc>'
            . '<image:title>Blue &amp; white shirt</image:title></image:image>'
            . '<PageMap xmlns="http://www.google.com/schemas/sitemap-pagemap/1.0"><DataObject type="thumbnail">'
            . '<Attribute name="name" value="Blue &amp; white shirt"/>'
            . '<Attribute name="src" value="https://shop.test/media/thumb.jpg"/>'
            . '</DataObject></PageMap>',
            (new Images($this->escaper()))->render(new SitemapItem('a.html', '0.5', 'daily', null, $images), 1)
        );
    }

    public function testAnItemWithoutImagesWritesNone(): void
    {
        $this->assertSame('', (new Images($this->escaper()))->render(new SitemapItem('a.html', '0.5', 'daily'), 1));
    }

    public function testTheImageNamespaceIsDeclared(): void
    {
        $this->assertSame(
            ['image' => 'http://www.google.com/schemas/sitemap-image/1.1'],
            (new Images($this->escaper()))->getNamespaces()
        );
    }

    /**
     * @return CoreFields
     */
    private function coreFields(): CoreFields
    {
        $store = $this->createStub(Store::class);
        $store->method('isUrlSecure')->willReturn(true);
        $store->method('getBaseUrl')->willReturnMap([[UrlInterface::URL_TYPE_LINK, true, 'https://shop.test/']]);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        // The real one: the link base URL with the store view's own secure flag is what is under test.
        return new CoreFields(new CanonicalBaseUrl($storeManager), $this->escaper());
    }

    /**
     * An escaper doing what core's does for plain URLs and text.
     *
     * @return Escaper
     */
    private function escaper(): Escaper
    {
        $escape  = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE);
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeUrl')->willReturnCallback($escape);
        $escaper->method('escapeHtml')->willReturnCallback($escape);
        $escaper->method('escapeHtmlAttr')->willReturnCallback($escape);

        return $escaper;
    }
}
