<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Hreflang;

use MageOS\Seo\Model\Hreflang\AlternateBuilder;
use MageOS\Seo\Model\Hreflang\LinkBuilder;
use MageOS\Seo\Model\Hreflang\SitemapGenerator;
use MageOS\Seo\Model\Hreflang\StoreLocaleMap;
use MageOS\Seo\Model\Hreflang\UrlRewriteFetcher;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class SitemapGeneratorTest extends TestCase
{
    /**
     * @var StoreLocaleMap&Stub
     */
    private StoreLocaleMap&Stub $storeLocaleMap;

    /**
     * @var UrlRewriteFetcher&Stub
     */
    private UrlRewriteFetcher&Stub $urlRewriteFetcher;

    /**
     * @var LinkBuilder&Stub
     */
    private LinkBuilder&Stub $linkBuilder;

    /**
     * @var AlternateBuilder&Stub
     */
    private AlternateBuilder&Stub $alternateBuilder;

    /**
     * @var SitemapGenerator
     */
    private SitemapGenerator $generator;

    protected function setUp(): void
    {
        $this->storeLocaleMap    = $this->createStub(StoreLocaleMap::class);
        $this->urlRewriteFetcher = $this->createStub(UrlRewriteFetcher::class);
        $this->linkBuilder       = $this->createStub(LinkBuilder::class);
        $this->alternateBuilder  = $this->createStub(AlternateBuilder::class);
        $this->generator         = new SitemapGenerator(
            $this->storeLocaleMap,
            $this->urlRewriteFetcher,
            $this->linkBuilder,
            $this->alternateBuilder
        );

        $this->storeLocaleMap->method('getMap')->willReturn([
            1 => ['base_url' => 'https://uk', 'codes' => ['en-GB']],
            2 => ['base_url' => 'https://de', 'codes' => ['de-DE']],
        ]);

        // Region links → alternate set ([] when fewer than 2 distinct locales).
        $this->alternateBuilder->method('build')->willReturnCallback(
            static function (array $regionLinks): array {
                if (\count($regionLinks) < 2) {
                    return [];
                }
                return array_map(
                    static fn (array $l) => ['hreflang' => $l['hreflang'], 'url' => $l['url']],
                    $regionLinks
                );
            }
        );
    }

    public function testDocumentFramingIsAValidUrlset(): void
    {
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $this->generator->documentHeader());
        $this->assertStringContainsString(
            'xmlns:xhtml="http://www.w3.org/1999/xhtml"',
            $this->generator->documentHeader()
        );
        $this->assertSame("</urlset>\n", $this->generator->documentFooter());
    }

    public function testHomePagesEmittedPerStore(): void
    {
        $this->noEntities();
        $this->linkBuilder->method('buildHome')->willReturn([
            ['hreflang' => 'en-GB', 'url' => 'https://uk/', 'store_id' => 1],
            ['hreflang' => 'de-DE', 'url' => 'https://de/', 'store_id' => 2],
        ]);

        $xml = $this->blocks();

        $this->assertStringContainsString('<loc>https://uk/</loc>', $xml);
        $this->assertStringContainsString('<loc>https://de/</loc>', $xml);
        // Each url block carries both alternates.
        $this->assertStringContainsString('hreflang="en-GB" href="https://uk/"', $xml);
        $this->assertStringContainsString('hreflang="de-DE" href="https://de/"', $xml);
    }

    public function testEntityEmitsOneUrlBlockPerStoreWithAlternates(): void
    {
        $this->givenProduct([1 => 'p.html', 2 => 'p-de.html'], [
            ['hreflang' => 'en-GB', 'url' => 'https://uk/p.html', 'store_id' => 1],
            ['hreflang' => 'de-DE', 'url' => 'https://de/p-de.html', 'store_id' => 2],
        ]);

        $xml = $this->blocks();

        $this->assertSame(1, substr_count($xml, '<loc>https://uk/p.html</loc>'));
        $this->assertSame(1, substr_count($xml, '<loc>https://de/p-de.html</loc>'));
    }

    public function testAStoreServingSeveralCodesIsListedOnceWithEveryCode(): void
    {
        $this->givenProduct([1 => 'p.html', 2 => 'p-es.html'], [
            ['hreflang' => 'es-MX', 'url' => 'https://latam/p.html', 'store_id' => 1],
            ['hreflang' => 'es-AR', 'url' => 'https://latam/p.html', 'store_id' => 1],
            ['hreflang' => 'es-ES', 'url' => 'https://es/p-es.html', 'store_id' => 2],
        ]);

        $xml = $this->blocks();

        $this->assertSame(1, substr_count($xml, '<loc>https://latam/p.html</loc>'));
        $this->assertSame(2, substr_count($xml, 'hreflang="es-MX" href="https://latam/p.html"'));
        $this->assertSame(2, substr_count($xml, 'hreflang="es-AR" href="https://latam/p.html"'));
    }

    public function testSingleStoreEntityProducesNoBlocks(): void
    {
        $this->givenProduct([1 => 'p.html'], [
            ['hreflang' => 'en-GB', 'url' => 'https://uk/p.html', 'store_id' => 1],
        ]);

        $this->assertStringNotContainsString('p.html', $this->blocks());
    }

    public function testXmlSpecialCharactersAreEscaped(): void
    {
        $this->givenProduct([1 => 'a', 2 => 'b'], [
            ['hreflang' => 'en-GB', 'url' => 'https://uk/p?a=1&b=2', 'store_id' => 1],
            ['hreflang' => 'de-DE', 'url' => 'https://de/p', 'store_id' => 2],
        ]);

        $xml = $this->blocks();

        $this->assertStringContainsString('a=1&amp;b=2', $xml);
        $this->assertStringNotContainsString('a=1&b=2', $xml);
    }

    public function testIndexDocumentListsTheChunksUnderTheStoreBaseUrl(): void
    {
        $xml = $this->generator->indexDocument('https://uk/', ['hreflang-sitemap-1.xml', 'hreflang-sitemap-2.xml']);

        $this->assertStringContainsString('<sitemapindex', $xml);
        $this->assertStringContainsString('<loc>https://uk/hreflang-sitemap-1.xml</loc>', $xml);
        $this->assertStringContainsString('<loc>https://uk/hreflang-sitemap-2.xml</loc>', $xml);
    }

    /**
     * No entity of any type has rewrites.
     *
     * @return void
     */
    private function noEntities(): void
    {
        $this->urlRewriteFetcher->method('streamAllForType')->willReturnCallback(
            static function (): \Generator {
                yield from [];
            }
        );
    }

    /**
     * One product with the given store paths, resolving to the given region links.
     *
     * @param array<int, string> $paths
     * @param array<int, array{hreflang:string,url:string,store_id:int}> $regionLinks
     * @return void
     */
    private function givenProduct(array $paths, array $regionLinks): void
    {
        $this->urlRewriteFetcher->method('streamAllForType')->willReturnCallback(
            static function (string $entityType) use ($paths): \Generator {
                if ($entityType === 'product') {
                    yield $paths;
                }
            }
        );
        $this->linkBuilder->method('buildFromPaths')->willReturn($regionLinks);
    }

    /**
     * The streamed blocks as one string.
     *
     * @return string
     */
    private function blocks(): string
    {
        return implode("\n", iterator_to_array($this->generator->streamBlocks(), false));
    }
}
