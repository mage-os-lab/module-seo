<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\MetaTag;

use Magento\Framework\View\Layout;
use Magento\Framework\View\Layout\ProcessorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use MageOS\Seo\Api\MetaTagProviderInterface;
use MageOS\Seo\Model\MetaTag\Compositor;

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
        $compositor = new Compositor($this->layout, []);
        $this->assertSame([], $compositor->getMetaTags());
    }

    public function testMatchingHandleIncludesTags(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $provider = $this->makeProvider(
            ['catalog_product_view'],
            [['property' => 'og:title', 'content' => 'My Product']]
        );
        $compositor = new Compositor($this->layout, [$provider]);
        $tags = $compositor->getMetaTags();
        $this->assertCount(1, $tags);
        $this->assertSame('My Product', $tags[0]['content']);
    }

    public function testWildcardHandleMatchesAnyPage(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['cms_page_view']);
        $provider = $this->makeProvider(
            ['*'],
            [['property' => 'og:type', 'content' => 'website']]
        );
        $compositor = new Compositor($this->layout, [$provider]);
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
        $compositor = new Compositor($this->layout, [$provider]);
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
        $compositor = new Compositor($this->layout, [$provider]);
        $tags = $compositor->getMetaTags();
        $this->assertCount(1, $tags);
        $this->assertSame('product', $tags[0]['content']);
    }

    public function testNonProviderObjectsInArrayAreSkipped(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $compositor = new Compositor($this->layout, [new \stdClass(), 'not-a-provider']);
        $this->assertSame([], $compositor->getMetaTags());
    }

    public function testTagsFromMultipleProvidersAreAggregated(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $p1 = $this->makeProvider(
            ['catalog_product_view'],
            [['property' => 'og:title', 'content' => 'Title']]
        );
        $p2 = $this->makeProvider(
            ['*'],
            [['property' => 'og:type', 'content' => 'product']]
        );
        $compositor = new Compositor($this->layout, [$p1, $p2]);
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
            [['property' => 'og:title', 'content' => 'Matched']]
        );
        $compositor = new Compositor($this->layout, [$provider]);
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
        $compositor = new Compositor($this->layout, [$provider]);
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
        $compositor = new Compositor($this->layout, [$provider]);
        $this->assertSame([], $compositor->getMetaTags());
    }
}
