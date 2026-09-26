<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Block;

use Magento\Framework\View\Asset\AssetInterface;
use Magento\Framework\View\Asset\GroupedCollection;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\Layout\ProcessorInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use MageOS\Seo\Block\Canonical;
use MageOS\Seo\Model\Cms\CmsPageResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The canonical fallback for CMS pages: the resolver's URL for the page, only on CMS pages, and
 * never a second canonical.
 */
class CanonicalTest extends TestCase
{
    /**
     * @var ProcessorInterface&MockObject
     */
    private ProcessorInterface&MockObject $layoutProcessor;

    /**
     * @var CmsPageResolver&MockObject
     */
    private CmsPageResolver&MockObject $cmsPageResolver;

    /**
     * @var Canonical
     */
    private Canonical $block;

    protected function setUp(): void
    {
        $this->layoutProcessor = $this->createMock(ProcessorInterface::class);
        $this->cmsPageResolver = $this->createMock(CmsPageResolver::class);
        $this->block           = $this->block([], $this->layoutProcessor, $this->cmsPageResolver);
    }

    private function withHandles(string ...$handles): void
    {
        $this->layoutProcessor->method('getHandles')->willReturn($handles);
    }

    public function testACmsPageIsTheResolversUrlForIt(): void
    {
        // The home page and every other CMS page carry cms_page_view; the resolver knows which is which.
        $this->withHandles('cms_index_index', 'cms_page_view');
        $this->cmsPageResolver->method('currentUrl')->willReturn('https://example.com/');

        $this->assertSame('https://example.com/', $this->block->getCanonicalUrl());
    }

    public function testACmsPageTheResolverCannotPlaceHasNone(): void
    {
        $this->withHandles('cms_page_view');
        $this->cmsPageResolver->method('currentUrl')->willReturn('');

        $this->assertSame('', $this->block->getCanonicalUrl());
    }

    public function testAPageWithoutTheCmsPageHandleHasNone(): void
    {
        // Product and category canonicals are core's job; search, cart and the rest get none —
        // and neither does `/` when web/default/front serves something other than a CMS page.
        $this->cmsPageResolver->expects($this->never())->method('currentUrl');

        foreach (['catalog_product_view', 'checkout_cart_index', 'catalog_category_view'] as $handle) {
            $processor = $this->createStub(ProcessorInterface::class);
            $processor->method('getHandles')->willReturn([$handle]);

            $this->assertSame('', $this->block([], $processor, $this->cmsPageResolver)->getCanonicalUrl(), $handle);
        }
    }

    public function testExistingCanonicalAssetShortCircuitsToEmpty(): void
    {
        // A canonical already added by core/another module must not be duplicated,
        // even on the home page.
        $asset = $this->createStub(AssetInterface::class);
        $asset->method('getContentType')->willReturn('canonical');
        $processor = $this->createStub(ProcessorInterface::class);
        $processor->method('getHandles')->willReturn(['cms_index_index', 'cms_page_view']);
        $resolver = $this->createStub(CmsPageResolver::class);
        $resolver->method('currentUrl')->willReturn('https://example.com/');

        $this->assertSame('', $this->block([$asset], $processor, $resolver)->getCanonicalUrl());
    }

    /**
     * @param AssetInterface[] $assets The page's assets already added
     * @param ProcessorInterface $processor
     * @param CmsPageResolver $resolver
     * @return Canonical
     */
    private function block(array $assets, ProcessorInterface $processor, CmsPageResolver $resolver): Canonical
    {
        $collection = $this->createStub(GroupedCollection::class);
        $collection->method('getAll')->willReturn($assets);
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('getAssetCollection')->willReturn($collection);
        $context = $this->createStub(Context::class);
        $context->method('getPageConfig')->willReturn($pageConfig);

        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('getUpdate')->willReturn($processor);

        $block = new Canonical($context, $resolver);
        $block->setLayout($layout);

        return $block;
    }
}
