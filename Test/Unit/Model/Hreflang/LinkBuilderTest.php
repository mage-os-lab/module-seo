<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Hreflang;

use MageOS\Seo\Model\Hreflang\LinkBuilder;
use MageOS\Seo\Model\Hreflang\StoreLocaleMap;
use MageOS\Seo\Model\Hreflang\UrlRewriteFetcher;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class LinkBuilderTest extends TestCase
{
    /**
     * @var UrlRewriteFetcher&Stub
     */
    private UrlRewriteFetcher&Stub $fetcher;

    /**
     * @var StoreLocaleMap&Stub
     */
    private StoreLocaleMap&Stub $storeLocaleMap;

    /**
     * @var LinkBuilder
     */
    private LinkBuilder $linkBuilder;

    protected function setUp(): void
    {
        $this->fetcher        = $this->createStub(UrlRewriteFetcher::class);
        $this->storeLocaleMap = $this->createStub(StoreLocaleMap::class);
        $this->linkBuilder    = $this->linkBuilder();
    }

    /**
     * The link builder under test, over the given rewrite fetcher or this test's stub.
     *
     * @param UrlRewriteFetcher|null $fetcher
     * @return LinkBuilder
     */
    private function linkBuilder(?UrlRewriteFetcher $fetcher = null): LinkBuilder
    {
        return new LinkBuilder($fetcher ?? $this->fetcher, $this->storeLocaleMap);
    }

    public function testBuildsAbsoluteLinksFromRewritesAndMap(): void
    {
        $fetcher = $this->createMock(UrlRewriteFetcher::class);
        $fetcher->method('fetchForEntity')->with('product', 5)
            ->willReturn([1 => 'blue-shirt.html', 2 => 'us/blue-shirt.html']);
        $this->storeLocaleMap->method('getMap')->willReturn([
            1 => ['base_url' => 'https://uk', 'codes' => ['en-GB']],
            2 => ['base_url' => 'https://us', 'codes' => ['en-US']],
        ]);

        $links = $this->linkBuilder($fetcher)->build('product', 5);

        $this->assertSame('en-GB', $links[0]['hreflang']);
        $this->assertSame('https://uk/blue-shirt.html', $links[0]['url']);
        $this->assertSame(1, $links[0]['store_id']);
        $this->assertSame('https://us/us/blue-shirt.html', $links[1]['url']);
    }

    public function testStoresMissingFromMapAreSkipped(): void
    {
        $this->fetcher->method('fetchForEntity')->willReturn([1 => 'p.html', 3 => 'excluded.html']);
        $this->storeLocaleMap->method('getMap')->willReturn([
            1 => ['base_url' => 'https://uk', 'codes' => ['en-GB']],
        ]);

        $links = $this->linkBuilder->build('product', 5);

        $this->assertCount(1, $links);
        $this->assertSame(1, $links[0]['store_id']);
    }

    public function testReturnsEmptyWhenNoRewrites(): void
    {
        $this->fetcher->method('fetchForEntity')->willReturn([]);
        $this->storeLocaleMap->method('getMap')->willReturn([
            1 => ['base_url' => 'https://uk', 'codes' => ['en-GB']],
        ]);
        $this->assertSame([], $this->linkBuilder->build('product', 5));
    }

    public function testAStoreWithSeveralCodesGetsOneLinkPerCodeAtTheSameUrl(): void
    {
        $this->storeLocaleMap->method('getMap')->willReturn([
            1 => ['base_url' => 'https://latam', 'codes' => ['es-MX', 'es-AR']],
        ]);

        $links = $this->linkBuilder->buildFromPaths([1 => 'p.html']);

        $this->assertSame(
            [
                ['hreflang' => 'es-MX', 'url' => 'https://latam/p.html', 'store_id' => 1],
                ['hreflang' => 'es-AR', 'url' => 'https://latam/p.html', 'store_id' => 1],
            ],
            $links
        );
    }

    public function testHomeLinksAreEveryStoresBaseUrlPerCode(): void
    {
        $this->storeLocaleMap->method('getMap')->willReturn([
            1 => ['base_url' => 'https://uk', 'codes' => ['en-GB', 'en-IE']],
            2 => ['base_url' => 'https://de', 'codes' => ['de-DE']],
        ]);
        $fetcher = $this->createMock(UrlRewriteFetcher::class);
        $fetcher->expects($this->never())->method('fetchForEntity');

        $this->assertSame(
            [
                ['hreflang' => 'en-GB', 'url' => 'https://uk/', 'store_id' => 1],
                ['hreflang' => 'en-IE', 'url' => 'https://uk/', 'store_id' => 1],
                ['hreflang' => 'de-DE', 'url' => 'https://de/', 'store_id' => 2],
            ],
            $this->linkBuilder($fetcher)->buildHome()
        );
    }
}
