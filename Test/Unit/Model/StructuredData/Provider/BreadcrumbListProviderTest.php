<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\StructuredData\Provider;

use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\BlockInterface;
use Magento\Framework\View\Layout\ProcessorInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\StructuredData\Provider\BreadcrumbListProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BreadcrumbListProviderTest extends TestCase
{
    /**
     * @var LayoutInterface&MockObject
     */
    private LayoutInterface&MockObject $layout;

    /**
     * @var ProcessorInterface&MockObject
     */
    private ProcessorInterface&MockObject $layoutProcessor;

    /**
     * @var CatalogHelper&MockObject
     */
    private CatalogHelper&MockObject $catalogHelper;

    /**
     * @var StoreManagerInterface&MockObject
     */
    private StoreManagerInterface&MockObject $storeManager;

    protected function setUp(): void
    {
        $this->layoutProcessor = $this->createMock(ProcessorInterface::class);
        $this->layoutProcessor->method('getHandles')->willReturn([]);
        $this->layout = $this->createMock(LayoutInterface::class);
        $this->layout->method('getUpdate')->willReturn($this->layoutProcessor);

        $this->catalogHelper = $this->createMock(CatalogHelper::class);

        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);
    }

    /**
     * @param string[] $excludedHandles
     * @param string|null $requestCategory The request's `category` param: set by a category-path URL
     */
    private function makeProvider(array $excludedHandles = [], ?string $requestCategory = null): BreadcrumbListProvider
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnMap([['category', null, $requestCategory]]);

        return new BreadcrumbListProvider(
            $this->layout,
            $this->catalogHelper,
            $this->storeManager,
            $request,
            $excludedHandles
        );
    }

    public function testGetHandlesReturnsWildcard(): void
    {
        $this->assertSame(['*'], $this->makeProvider()->getHandles());
    }

    public function testExcludedHandleSuppressesSchema(): void
    {
        $this->layoutProcessor = $this->createMock(ProcessorInterface::class);
        $this->layoutProcessor->method('getHandles')->willReturn(['catalog_product_view', 'makers_landing']);
        $this->layout = $this->createMock(LayoutInterface::class);
        $this->layout->method('getUpdate')->willReturn($this->layoutProcessor);

        $this->assertSame([], $this->makeProvider(['makers_landing'])->getSchemas());
    }

    public function testHyvaBlockCrumbsBuildBreadcrumbList(): void
    {
        // A breadcrumbs block exposing getCrumbs() like Hyvä's does. An anonymous
        // class avoids the deprecated MockBuilder::addMethods() and a fixture file.
        $breadcrumbBlock = new class () implements BlockInterface {
            public function toHtml()
            {
                return '';
            }

            /**
             * @return array<int, array{label?: string, link?: string}>
             */
            public function getCrumbs(): array
            {
                return [
                    ['label' => 'Home', 'link' => 'https://example.com/'],
                    ['label' => 'Shoes', 'link' => 'https://example.com/shoes'],
                    ['label' => 'Sneaker'],
                ];
            }
        };
        $this->layout->method('getBlock')->with('breadcrumbs')->willReturn($breadcrumbBlock);

        $schemas = $this->makeProvider()->getSchemas();

        $this->assertCount(1, $schemas);
        $list = $schemas[0];
        $this->assertSame('BreadcrumbList', $list['@type']);
        $this->assertCount(3, $list['itemListElement']);
        $this->assertSame(1, $list['itemListElement'][0]['position']);
        $this->assertSame('Home', $list['itemListElement'][0]['name']);
        $this->assertSame('https://example.com/shoes', $list['itemListElement'][1]['item']);
        $this->assertSame(3, $list['itemListElement'][2]['position']);
        // The last crumb has no link, so no 'item' key.
        $this->assertArrayNotHasKey('item', $list['itemListElement'][2]);
    }

    public function testLumaFallbackPrependsHomeCrumbFromCatalogPath(): void
    {
        // No getCrumbs()-capable block (Luma): fall back to the catalog path. The product was
        // requested at a category-path URL, so the request carries its category.
        $this->layout->method('getBlock')->with('breadcrumbs')->willReturn(false);
        $this->catalogHelper->method('getBreadcrumbPath')->willReturn([
            'category-1' => ['label' => 'Shoes', 'link' => 'https://example.com/shoes'],
            'product'    => ['label' => 'Sneaker'],
        ]);

        $schemas = $this->makeProvider([], '1')->getSchemas();

        $list = $schemas[0]['itemListElement'];
        $this->assertSame('Home', $list[0]['name']);
        $this->assertSame('https://example.com/', $list[0]['item']);
        $this->assertSame('Shoes', $list[1]['name']);
        $this->assertSame('Sneaker', $list[2]['name']);
        $this->assertArrayNotHasKey('item', $list[2]);
    }

    public function testAProductAtAPlainUrlIsHomeThenTheProduct(): void
    {
        // The category in the path came from the visitor's session, not the URL: left out, as
        // Luma's own trail leaves it out, and as the page cache must not keep one visitor's trail.
        $this->layout->method('getBlock')->with('breadcrumbs')->willReturn(false);
        $this->catalogHelper->method('getBreadcrumbPath')->willReturn([
            'category-1' => ['label' => 'Shoes', 'link' => 'https://example.com/shoes'],
            'product'    => ['label' => 'Sneaker'],
        ]);

        $list = $this->makeProvider()->getSchemas()[0]['itemListElement'];

        $this->assertSame(['Home', 'Sneaker'], array_column($list, 'name'));
        $this->assertSame([1, 2], array_column($list, 'position'));
    }

    public function testACategoryPageKeepsItsPath(): void
    {
        // On a category page the category is the page itself, not a guess.
        $this->layout->method('getBlock')->with('breadcrumbs')->willReturn(false);
        $this->catalogHelper->method('getBreadcrumbPath')->willReturn([
            'category-1' => ['label' => 'Shoes', 'link' => 'https://example.com/shoes'],
            'category-2' => ['label' => 'Sneakers'],
        ]);

        $list = $this->makeProvider()->getSchemas()[0]['itemListElement'];

        $this->assertSame(['Home', 'Shoes', 'Sneakers'], array_column($list, 'name'));
    }

    public function testEmptyWhenNoBlockAndNoCatalogPath(): void
    {
        $this->layout->method('getBlock')->with('breadcrumbs')->willReturn(false);
        $this->catalogHelper->method('getBreadcrumbPath')->willReturn([]);

        $this->assertSame([], $this->makeProvider()->getSchemas());
    }
}
