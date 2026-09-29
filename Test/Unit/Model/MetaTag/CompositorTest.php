<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\MetaTag;

use Magento\Framework\View\Layout;
use Magento\Framework\View\Layout\ProcessorInterface;
use MageOS\Seo\Api\MetaTagProviderInterface;
use MageOS\Seo\Model\MetaTag\Compositor;
use MageOS\Seo\Model\Pool\HandleMatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CompositorTest extends TestCase
{
    /**
     * @var Layout&MockObject
     */
    private Layout&MockObject $layout;

    /**
     * @var ProcessorInterface&MockObject
     */
    private ProcessorInterface&MockObject $layoutUpdate;

    protected function setUp(): void
    {
        $this->layout       = $this->createMock(Layout::class);
        $this->layoutUpdate = $this->createMock(ProcessorInterface::class);
        $this->layout->method('getUpdate')->willReturn($this->layoutUpdate);
    }

    /**
     * @param string[] $handles
     * @param array<int, array<string, mixed>> $tags
     */
    private function makeProvider(array $handles, array $tags): MetaTagProviderInterface&MockObject
    {
        $provider = $this->createMock(MetaTagProviderInterface::class);
        $provider->method('getHandles')->willReturn($handles);
        $provider->method('getMetaTags')->willReturn($tags);
        return $provider;
    }

    public function testReturnsEmptyArrayWhenNoProviders(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $compositor = new Compositor($this->layout, new HandleMatcher(), []);
        $this->assertSame([], $compositor->getMetaTags());
    }

    public function testMatchingHandleIncludesTags(): void
    {
        // og:type rather than og:title: a title also brings the X card tags (tested below).
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $provider = $this->makeProvider(
            ['catalog_product_view'],
            [['property' => 'og:type', 'content' => 'product']]
        );
        $compositor = new Compositor($this->layout, new HandleMatcher(), [$provider]);
        $tags = $compositor->getMetaTags();
        $this->assertCount(1, $tags);
        $this->assertSame('product', $tags[0]['content']);
    }

    public function testWildcardHandleMatchesAnyPage(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['cms_page_view']);
        $provider = $this->makeProvider(
            ['*'],
            [['property' => 'og:type', 'content' => 'website']]
        );
        $compositor = new Compositor($this->layout, new HandleMatcher(), [$provider]);
        $tags = $compositor->getMetaTags();
        $this->assertCount(1, $tags);
    }

    public function testNonMatchingHandleSkipsProvider(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['cms_page_view']);
        $provider = $this->makeProvider(
            ['catalog_product_view'],
            [['property' => 'og:title', 'content' => 'Product']]
        );
        $compositor = new Compositor($this->layout, new HandleMatcher(), [$provider]);
        $this->assertSame([], $compositor->getMetaTags());
    }

    public function testTagsWithEmptyContentAreFiltered(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $provider = $this->makeProvider(
            ['catalog_product_view'],
            [
                ['property' => 'og:title', 'content' => ''],
                ['property' => 'og:type',  'content' => 'product'],
                ['property' => 'og:url',   'content' => null],
            ]
        );
        $compositor = new Compositor($this->layout, new HandleMatcher(), [$provider]);
        $tags = $compositor->getMetaTags();
        $this->assertCount(1, $tags);
        $this->assertSame('product', $tags[0]['content']);
    }

    public function testNonProviderObjectsInArrayAreSkipped(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $compositor = new Compositor($this->layout, new HandleMatcher(), [new \stdClass(), 'not-a-provider']);
        $this->assertSame([], $compositor->getMetaTags());
    }

    public function testTagsFromMultipleProvidersAreAggregated(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $p1 = $this->makeProvider(
            ['catalog_product_view'],
            [['property' => 'og:url', 'content' => 'https://example.com/p.html']]
        );
        $p2 = $this->makeProvider(
            ['*'],
            [['property' => 'og:type', 'content' => 'product']]
        );
        $compositor = new Compositor($this->layout, new HandleMatcher(), [$p1, $p2]);
        $this->assertCount(2, $compositor->getMetaTags());
    }

    public function testPartialHandleOverlapMatchesProvider(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn([
            'default',
            'catalog_product_view',
            'catalog_product_view_id_42',
        ]);
        $provider = $this->makeProvider(
            ['catalog_product_view', 'catalog_category_view'],
            [['property' => 'og:type', 'content' => 'product']]
        );
        $compositor = new Compositor($this->layout, new HandleMatcher(), [$provider]);
        $tags = $compositor->getMetaTags();
        $this->assertCount(1, $tags);
    }

    public function testProviderWithNoActiveHandleMatchIsExcluded(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['default', 'cms_index_index']);
        $provider = $this->makeProvider(
            ['catalog_product_view', 'catalog_category_view'],
            [['property' => 'og:title', 'content' => 'Should not appear']]
        );
        $compositor = new Compositor($this->layout, new HandleMatcher(), [$provider]);
        $this->assertSame([], $compositor->getMetaTags());
    }

    public function testProviderTagsWithZeroStringContentAreFilteredBecauseEmptyConsidersZeroEmpty(): void
    {
        // The compositor uses !empty($tag['content']) — PHP considers '0' as empty,
        // so a tag with content='0' is intentionally excluded.
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $provider = $this->makeProvider(
            ['catalog_product_view'],
            [['property' => 'og:price:amount', 'content' => '0']]
        );
        $compositor = new Compositor($this->layout, new HandleMatcher(), [$provider]);
        $this->assertSame([], $compositor->getMetaTags());
    }

    public function testAPageWithAnImageGetsALargeImageCardAndTheOgValuesForX(): void
    {
        $tags = $this->composed([
            ['property' => 'og:title', 'content' => 'Tee'],
            ['property' => 'og:description', 'content' => 'A tee.'],
            ['property' => 'og:image', 'content' => 'https://example.com/tee.jpg'],
        ]);

        $this->assertSame('summary_large_image', $tags['twitter:card'] ?? null);
        $this->assertSame('Tee', $tags['twitter:title'] ?? null);
        $this->assertSame('A tee.', $tags['twitter:description'] ?? null);
        $this->assertSame('https://example.com/tee.jpg', $tags['twitter:image'] ?? null);
    }

    public function testAPageWithoutAnImageGetsASummaryCard(): void
    {
        $tags = $this->composed([['property' => 'og:title', 'content' => 'About us']]);

        $this->assertSame('summary', $tags['twitter:card'] ?? null);
        $this->assertSame('About us', $tags['twitter:title'] ?? null);
        $this->assertArrayNotHasKey('twitter:image', $tags);
        $this->assertArrayNotHasKey('twitter:description', $tags);
    }

    public function testAPageWithoutAnOgTitleGetsNoCard(): void
    {
        // Cart, checkout: only the site-wide tags, nothing to share.
        $tags = $this->composed([
            ['property' => 'og:site_name', 'content' => 'Store'],
            ['property' => 'og:locale', 'content' => 'en_GB'],
        ]);

        $this->assertSame(['og:site_name', 'og:locale'], array_keys($tags));
    }

    public function testTwitterTagsAProviderSetAreKept(): void
    {
        $tags = $this->composed([
            ['property' => 'og:title', 'content' => 'Tee'],
            ['property' => 'og:image', 'content' => 'https://example.com/tee.jpg'],
            ['name' => 'twitter:card', 'content' => 'player'],
            ['name' => 'twitter:title', 'content' => 'Tee on X'],
        ]);

        $this->assertSame('player', $tags['twitter:card'] ?? null);
        $this->assertSame('Tee on X', $tags['twitter:title'] ?? null);
        $this->assertSame('https://example.com/tee.jpg', $tags['twitter:image'] ?? null);
    }

    public function testTheFirstOgTitleIsTheOneRepeatedForX(): void
    {
        // Two providers each giving an og:title: the page's first is the one X gets too.
        $this->layoutUpdate->method('getHandles')->willReturn(['cms_page_view']);
        $compositor = new Compositor($this->layout, new HandleMatcher(), [
            $this->makeProvider(['*'], [['property' => 'og:title', 'content' => 'First']]),
            $this->makeProvider(['*'], [['property' => 'og:title', 'content' => 'Second']]),
        ]);

        $isTwitterTitle = static fn (array $tag): bool => ($tag['name'] ?? '') === 'twitter:title';
        $titles         = array_column(array_filter($compositor->getMetaTags(), $isTwitterTitle), 'content');
        $this->assertSame(['First'], array_values($titles));
    }

    public function testTheXTagsAreNameTagsAddedOnceAfterTheProvidersTags(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['cms_page_view']);
        $compositor = new Compositor($this->layout, new HandleMatcher(), [
            $this->makeProvider(['*'], [['property' => 'og:title', 'content' => 'About us']]),
        ]);

        $this->assertSame(
            [
                ['property' => 'og:title', 'content' => 'About us'],
                ['name' => 'twitter:card', 'content' => 'summary'],
                ['name' => 'twitter:title', 'content' => 'About us'],
            ],
            $compositor->getMetaTags()
        );
    }

    /**
     * The page's tags from one provider on every page, keyed by property or name.
     *
     * @param array<int,array<string,string>> $providerTags
     * @return array<string,string>
     */
    private function composed(array $providerTags): array
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $compositor = new Compositor($this->layout, new HandleMatcher(), [$this->makeProvider(['*'], $providerTags)]);

        $tags = [];
        foreach ($compositor->getMetaTags() as $tag) {
            $key = $tag['property'] ?? $tag['name'];
            $this->assertArrayNotHasKey($key, $tags, "{$key} appears twice.");
            $tags[$key] = $tag['content'];
        }

        return $tags;
    }
}
