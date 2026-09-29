<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\PageTitle\Provider;

use Magento\Catalog\Model\Product;
use MageOS\Seo\Model\Catalog\CurrentEntity;
use MageOS\Seo\Model\PageTitle\Provider\ProductTitleProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProductTitleProviderTest extends TestCase
{
    /**
     * @var CurrentEntity&MockObject
     */
    private CurrentEntity&MockObject $currentEntity;

    /**
     * @var ProductTitleProvider
     */
    private ProductTitleProvider $provider;

    protected function setUp(): void
    {
        $this->currentEntity = $this->createMock(CurrentEntity::class);
        $this->provider      = new ProductTitleProvider($this->currentEntity);
    }

    public function testGetHandlesTargetsProductView(): void
    {
        $this->assertSame(['catalog_product_view'], $this->provider->getHandles());
    }

    public function testReturnsProductMetaTitle(): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->with('meta_title')->willReturn('SEO Meta Title');
        $this->currentEntity->method('getProduct')->willReturn($product);

        $this->assertSame('SEO Meta Title', $this->provider->getTitle());
    }

    public function testEmptyWhenNoProduct(): void
    {
        $this->currentEntity->method('getProduct')->willReturn(null);

        $this->assertSame('', $this->provider->getTitle());
    }

    public function testEmptyWhenProductHasNoMetaTitle(): void
    {
        // Core already applies meta_title (with the product name as its own
        // fallback); the provider must stay silent so it does not override that.
        $product = $this->createMock(Product::class);
        $product->method('getData')->with('meta_title')->willReturn(null);
        $this->currentEntity->method('getProduct')->willReturn($product);

        $this->assertSame('', $this->provider->getTitle());
    }
}
