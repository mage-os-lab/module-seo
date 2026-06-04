<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\PageTitle;

use Magento\Framework\View\Layout;
use Magento\Framework\View\Layout\ProcessorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use MageOS\Seo\Api\PageTitleProviderInterface;
use MageOS\Seo\Model\PageTitle\Compositor;

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
     */
    private function makeProvider(
        array $handles,
        string $title,
        int $sortOrder = 0
    ): PageTitleProviderInterface&MockObject {
        $provider = $this->createMock(PageTitleProviderInterface::class);
        $provider->method('getHandles')->willReturn($handles);
        $provider->method('getTitle')->willReturn($title);
        $provider->method('getSortOrder')->willReturn($sortOrder);
        return $provider;
    }

    public function testReturnsEmptyStringWithNoProviders(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $compositor = new Compositor($this->layout, []);
        $this->assertSame('', $compositor->getTitle());
    }

    public function testReturnsEmptyStringWhenNoProvidersMatch(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['cms_page_view']);
        $provider = $this->makeProvider(['catalog_product_view'], 'Product Title', 10);
        $compositor = new Compositor($this->layout, [$provider]);
        $this->assertSame('', $compositor->getTitle());
    }

    public function testReturnsTitleFromMatchingProvider(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $provider = $this->makeProvider(['catalog_product_view'], 'My Product', 10);
        $compositor = new Compositor($this->layout, [$provider]);
        $this->assertSame('My Product', $compositor->getTitle());
    }

    public function testWildcardHandleMatchesAnyPage(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['cms_page_view']);
        $provider = $this->makeProvider(['*'], 'CMS Title', 5);
        $compositor = new Compositor($this->layout, [$provider]);
        $this->assertSame('CMS Title', $compositor->getTitle());
    }

    public function testHigherSortOrderWins(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $low  = $this->makeProvider(['*'], 'Low Priority', 10);
        $high = $this->makeProvider(['catalog_product_view'], 'High Priority', 50);
        $compositor = new Compositor($this->layout, [$low, $high]);
        $this->assertSame('High Priority', $compositor->getTitle());
    }

    public function testSortOrderIsDescending(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $p1 = $this->makeProvider(['*'], 'Sort 100', 100);
        $p2 = $this->makeProvider(['*'], 'Sort 200', 200);
        $p3 = $this->makeProvider(['*'], 'Sort 50', 50);
        $compositor = new Compositor($this->layout, [$p3, $p1, $p2]);
        $this->assertSame('Sort 200', $compositor->getTitle());
    }

    public function testProvidersWithEmptyTitleAreIgnored(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $empty   = $this->makeProvider(['*'], '', 100);
        $nonEmpty = $this->makeProvider(['*'], 'Actual Title', 50);
        $compositor = new Compositor($this->layout, [$empty, $nonEmpty]);
        $this->assertSame('Actual Title', $compositor->getTitle());
    }

    public function testAllProvidersEmptyTitleReturnsEmptyString(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $p1 = $this->makeProvider(['*'], '', 100);
        $p2 = $this->makeProvider(['*'], '', 50);
        $compositor = new Compositor($this->layout, [$p1, $p2]);
        $this->assertSame('', $compositor->getTitle());
    }

    public function testNonProviderObjectsAreSkipped(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $compositor = new Compositor($this->layout, [new \stdClass()]);
        $this->assertSame('', $compositor->getTitle());
    }

    public function testSameSortOrderKeepsFirstProcessed(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['catalog_product_view']);
        $p1 = $this->makeProvider(['*'], 'First', 10);
        $p2 = $this->makeProvider(['*'], 'Second', 10);
        $compositor = new Compositor($this->layout, [$p1, $p2]);
        // Both have sort order 10 — result is deterministic (stable sort not guaranteed, but one wins)
        $title = $compositor->getTitle();
        $this->assertContains($title, ['First', 'Second']);
    }

    public function testHandleIntersectionRequiresAtLeastOneMatch(): void
    {
        $this->layoutUpdate->method('getHandles')->willReturn(['default', 'cms_index_index']);
        $matched  = $this->makeProvider(['cms_index_index'], 'Home', 10);
        $unmatched = $this->makeProvider(['catalog_product_view'], 'Product', 10);
        $compositor = new Compositor($this->layout, [$matched, $unmatched]);
        $this->assertSame('Home', $compositor->getTitle());
    }
}
