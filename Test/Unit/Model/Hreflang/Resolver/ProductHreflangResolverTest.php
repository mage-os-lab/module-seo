<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Hreflang\Resolver;

use Magento\Catalog\Api\Data\ProductInterface;
use MageOS\Seo\Model\Catalog\CurrentEntity;
use MageOS\Seo\Model\Hreflang\LinkBuilder;
use MageOS\Seo\Model\Hreflang\Resolver\ProductHreflangResolver;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ProductHreflangResolverTest extends TestCase
{
    /**
     * @var CurrentEntity&Stub
     */
    private CurrentEntity&Stub $currentEntity;

    /**
     * @var LinkBuilder&Stub
     */
    private LinkBuilder&Stub $linkBuilder;

    /**
     * @var ProductHreflangResolver
     */
    private ProductHreflangResolver $resolver;

    protected function setUp(): void
    {
        $this->currentEntity    = $this->createStub(CurrentEntity::class);
        $this->linkBuilder = $this->createStub(LinkBuilder::class);
        $this->resolver    = $this->resolver();
    }

    /**
     * The resolver under test, over the given link builder or this test's stub.
     *
     * @param LinkBuilder|null $linkBuilder
     * @return ProductHreflangResolver
     */
    private function resolver(?LinkBuilder $linkBuilder = null): ProductHreflangResolver
    {
        return new ProductHreflangResolver($this->currentEntity, $linkBuilder ?? $this->linkBuilder);
    }

    public function testHandlesProductView(): void
    {
        $this->assertSame(['catalog_product_view'], $this->resolver->getHandles());
    }

    public function testReturnsEmptyWhenNoCurrentProduct(): void
    {
        $this->currentEntity->method('getProduct')->willReturn(null);
        $this->assertSame([], $this->resolver->getLinks());
    }

    public function testDelegatesToLinkBuilderWithProductId(): void
    {
        $product = $this->createStub(ProductInterface::class);
        $product->method('getId')->willReturn(42);
        $this->currentEntity->method('getProduct')->willReturn($product);

        $links = [['hreflang' => 'en-GB', 'url' => 'https://uk/p', 'store_id' => 1]];
        $linkBuilder = $this->createMock(LinkBuilder::class);
        $linkBuilder->method('build')->with('product', 42)->willReturn($links);

        $this->assertSame($links, $this->resolver($linkBuilder)->getLinks());
    }
}
