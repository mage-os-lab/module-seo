<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap\Hreflang;

use Magento\Framework\Escaper;
use MageOS\Seo\Api\Sitemap\SitemapItemInterface;
use MageOS\Seo\Model\Cms\ConfigRepository as CmsConfigRepository;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Hreflang\AlternateBuilder;
use MageOS\Seo\Model\Hreflang\LinkBuilder;
use MageOS\Seo\Model\Hreflang\SelfReference;
use MageOS\Seo\Model\Hreflang\UrlRewriteFetcher;
use MageOS\Seo\Model\Sitemap\Hreflang\Alternates;
use MageOS\Seo\Model\Sitemap\Hreflang\Enricher;
use MageOS\Seo\Model\Sitemap\Hreflang\Renderer;
use MageOS\Seo\Model\Sitemap\SitemapItem;
use MageOS\Seo\Model\Store\CanonicalBaseUrl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * How alternates are fetched for a chunk and filed, and how they are written. That the result is
 * the set the page's head declares, on a real install, is covered by
 * Test/Integration/Model/Sitemap/HreflangTest.
 */
class EnricherTest extends TestCase
{
    /**
     * @var UrlRewriteFetcher&MockObject
     */
    private UrlRewriteFetcher&MockObject $fetcher;

    /**
     * @var CmsConfigRepository&MockObject
     */
    private CmsConfigRepository&MockObject $cmsConfig;

    /**
     * @var bool
     */
    private bool $enabled = true;

    protected function setUp(): void
    {
        $this->fetcher   = $this->createMock(UrlRewriteFetcher::class);
        $this->cmsConfig = $this->createMock(CmsConfigRepository::class);
        $this->enabled   = true;
    }

    public function testAChunkIsFetchedWithOneQueryPerEntityType(): void
    {
        $this->fetcher->expects($this->exactly(2))->method('fetchForEntities')->willReturnCallback(
            static fn (string $type, array $ids): array => match ($type) {
                'product'  => [1 => [1 => 'a.html', 2 => 'de/a.html'], 2 => [1 => 'b.html', 2 => 'de/b.html']],
                'category' => [7 => [1 => 'shirts.html', 2 => 'de/hemden.html']],
                default    => throw new \UnexpectedValueException('Not fetched by entity: ' . $type),
            }
        );

        $items = [
            $this->item('a.html', 'product', 1),
            $this->item('b.html', 'product', 2),
            $this->item('shirts.html', 'category', 7),
        ];
        $this->enricher()->enrich($items, 1);

        $this->assertSame(
            ['en-GB' => 'https://uk/shirts.html', 'de-DE' => 'https://de/de/hemden.html'],
            $this->alternatesOf($items[2])
        );
    }

    public function testACmsPageInAGroupGetsItsGroupsAlternates(): void
    {
        $this->cmsConfig->method('getHreflangGroups')->with([3, 4])->willReturn([3 => 'about-us']);
        $this->fetcher->method('fetchForCmsGroups')->with(['about-us'])
            ->willReturn(['about-us' => [1 => 'about-us', 2 => 'ueber-uns']]);
        $this->fetcher->method('fetchForEntities')->with('cms-page', [4])
            ->willReturn([4 => [1 => 'contact', 2 => 'kontakt']]);

        $items = [$this->item('about-us', 'cms-page', 3), $this->item('contact', 'cms-page', 4)];
        $this->enricher()->enrich($items, 1);

        $this->assertSame('https://de/ueber-uns', $this->alternatesOf($items[0])['de-DE'] ?? null);
        $this->assertSame('https://de/kontakt', $this->alternatesOf($items[1])['de-DE'] ?? null);
    }

    public function testAnItemWhoseOwnUrlIsNotInTheSetGetsNone(): void
    {
        // Two translations assigned to store view 1: the group's page there is the other one, so
        // listing this page's alternates would give it a set that does not include itself.
        $this->cmsConfig->method('getHreflangGroups')->willReturn([5 => 'about-us']);
        $this->fetcher->method('fetchForCmsGroups')
            ->willReturn(['about-us' => [1 => 'about-us', 2 => 'ueber-uns']]);
        $this->fetcher->method('fetchForEntities')->willReturn([]);

        $item = $this->item('about-us-again', 'cms-page', 5);
        $this->enricher()->enrich([$item], 1);

        $this->assertSame([], $item->getDataBag());
    }

    public function testTheHomePageGetsTheStoreViewsHomes(): void
    {
        $this->fetcher->expects($this->never())->method('fetchForEntities');

        $item = $this->item('', 'store', null);
        $this->enricher()->enrich([$item], 1);

        $this->assertSame(['en-GB' => 'https://uk/', 'de-DE' => 'https://de/'], $this->alternatesOf($item));
    }

    public function testAnItemWithoutAnEntityIsLeftAlone(): void
    {
        $item = $this->item('elsewhere.html', null, null);
        $this->enricher()->enrich([$item], 1);

        $this->assertSame([], $item->getDataBag());
    }

    public function testNothingIsFetchedWhenTheSettingIsOff(): void
    {
        $this->enabled = false;
        $this->fetcher->expects($this->never())->method('fetchForEntities');

        $item = $this->item('a.html', 'product', 1);
        $this->enricher()->enrich([$item], 1);

        $this->assertSame([], $item->getDataBag());
    }

    public function testTheRendererWritesEachAlternateEscaped(): void
    {
        $item = $this->item('a.html', 'product', 1);
        $item->updateDataBag(Enricher::BAG_KEY, new Alternates([
            ['hreflang' => 'en-GB', 'url' => 'https://uk/a.html?x=1&y=2'],
            ['hreflang' => 'x-default', 'url' => 'https://uk/a.html'],
        ]));

        $this->assertSame(
            '<xhtml:link rel="alternate" hreflang="en-GB" href="https://uk/a.html?x=1&amp;y=2"/>'
            . '<xhtml:link rel="alternate" hreflang="x-default" href="https://uk/a.html"/>',
            $this->renderer()->render($item, 1)
        );
    }

    public function testTheRendererWritesNothingForAnItemWithoutAlternates(): void
    {
        $this->assertSame('', $this->renderer()->render($this->item('a.html', 'product', 1), 1));
    }

    public function testTheRendererSkipsAnEntryAnObserverLeftIncomplete(): void
    {
        $item = $this->item('a.html', 'product', 1);
        $item->updateDataBag(Enricher::BAG_KEY, new Alternates([['hreflang' => 'fr-FR']]));

        $this->assertSame('', $this->renderer()->render($item, 1));
    }

    /**
     * The enricher over two store views, uk (1, en-GB) and de (2, de-DE); the alternate builder
     * passes the region links through.
     *
     * @return Enricher
     */
    private function enricher(): Enricher
    {
        $config = $this->createStub(Config::class);
        $config->method('isHreflangSitemapEnabled')->willReturnCallback(fn (): bool => $this->enabled);
        $config->method('isHreflangEnabled')->willReturn(true);

        $linkBuilder = $this->createStub(LinkBuilder::class);
        $linkBuilder->method('buildFromPaths')->willReturnCallback(
            static function (array $paths): array {
                $links = [];
                foreach ($paths as $storeId => $path) {
                    $links[] = [
                        'hreflang' => $storeId === 1 ? 'en-GB' : 'de-DE',
                        'url'      => ($storeId === 1 ? 'https://uk/' : 'https://de/') . $path,
                        'store_id' => $storeId,
                    ];
                }
                return $links;
            }
        );
        $linkBuilder->method('buildHome')->willReturn([
            ['hreflang' => 'en-GB', 'url' => 'https://uk/', 'store_id' => 1],
            ['hreflang' => 'de-DE', 'url' => 'https://de/', 'store_id' => 2],
        ]);

        $alternateBuilder = $this->createStub(AlternateBuilder::class);
        $alternateBuilder->method('build')->willReturnCallback(
            static fn (array $links): array => array_map(
                static fn (array $link): array => ['hreflang' => $link['hreflang'], 'url' => $link['url']],
                $links
            )
        );

        $baseUrl = $this->createStub(CanonicalBaseUrl::class);
        $baseUrl->method('forStore')->willReturnMap([[1, 'https://uk'], [2, 'https://de']]);

        return new Enricher(
            $config,
            $this->fetcher,
            $this->cmsConfig,
            $linkBuilder,
            $alternateBuilder,
            $baseUrl,
            new SelfReference()
        );
    }

    /**
     * @return Renderer
     */
    private function renderer(): Renderer
    {
        $escape  = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE);
        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeUrl')->willReturnCallback($escape);
        $escaper->method('escapeHtmlAttr')->willReturnCallback($escape);

        return new Renderer($escaper);
    }

    /**
     * @param string $url
     * @param string|null $entityType
     * @param int|null $entityId
     * @return SitemapItemInterface
     */
    private function item(string $url, ?string $entityType, ?int $entityId): SitemapItemInterface
    {
        return new SitemapItem($url, '0.5', 'daily', null, null, $entityType, $entityId);
    }

    /**
     * The item's filed alternates, hreflang => URL.
     *
     * @param SitemapItemInterface $item
     * @return array<string,string>
     */
    private function alternatesOf(SitemapItemInterface $item): array
    {
        $alternates = $item->getDataBag()[Enricher::BAG_KEY] ?? null;
        $this->assertInstanceOf(Alternates::class, $alternates, $item->getUrl() . ' has no alternates.');

        return array_column($alternates->getAlternates(), 'url', 'hreflang');
    }
}
