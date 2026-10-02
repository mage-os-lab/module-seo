<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\PageTitle\Provider;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Layer;
use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use MageOS\Seo\Model\PageTitle\Provider\CategoryTitleProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CategoryTitleProviderTest extends TestCase
{
    /**
     * @var LayerResolver&Stub
     */
    private LayerResolver&Stub $layerResolver;

    /**
     * @var Layer&Stub
     */
    private Layer&Stub $layer;

    /**
     * @var CategoryTitleProvider
     */
    private CategoryTitleProvider $provider;

    protected function setUp(): void
    {
        $this->layerResolver = $this->createStub(LayerResolver::class);
        $this->layer         = $this->createStub(Layer::class);
        $this->layerResolver->method('get')->willReturn($this->layer);
        $this->provider      = new CategoryTitleProvider($this->layerResolver);
    }

    public function testGetHandlesTargetsCategoryView(): void
    {
        $this->assertSame(['catalog_category_view'], $this->provider->getHandles());
    }

    public function testReturnsCategoryMetaTitle(): void
    {
        $category = $this->createMock(Category::class);
        $category->method('getData')->with('meta_title')->willReturn('Shoes | Meta');
        $this->layer->method('getCurrentCategory')->willReturn($category);

        $this->assertSame('Shoes | Meta', $this->provider->getTitle());
    }

    public function testEmptyWhenCategoryHasNoMetaTitle(): void
    {
        $category = $this->createMock(Category::class);
        $category->method('getData')->with('meta_title')->willReturn(null);
        $this->layer->method('getCurrentCategory')->willReturn($category);

        $this->assertSame('', $this->provider->getTitle());
    }
}
