<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\Variant;

use Magento\Catalog\Model\Product;
use MageOS\Seo\Model\Product\Variant\ChildProducts;
use MageOS\Seo\Model\Product\Variant\VariantImage;
use PHPUnit\Framework\TestCase;

/**
 * A configurable without a base image lends its first sold variant's, from the variants already read
 * (PLAN-G, "G2.3 follow-up"). Core's `getImage()` fallback loaded all of them for it, one
 * configurable at a time.
 */
class VariantImageTest extends TestCase
{
    /**
     * @return void
     */
    public function testAProductsOwnImageComesFirst(): void
    {
        $childProducts = $this->createMock(ChildProducts::class);
        $childProducts->expects($this->never())->method('get');

        $image = (new VariantImage($childProducts))->forProduct($this->product('/o/w/own.jpg'));

        $this->assertSame('/o/w/own.jpg', $image);
    }

    /**
     * @return void
     */
    public function testWithoutOneTheFirstVariantWithAnImageLendsIt(): void
    {
        $childProducts = $this->createStub(ChildProducts::class);
        $childProducts->method('get')->willReturn([
            $this->product('no_selection'),
            $this->product('/v/a/variant.jpg'),
            $this->product('/l/a/later.jpg'),
        ]);

        $this->assertSame('/v/a/variant.jpg', (new VariantImage($childProducts))->forProduct($this->product('')));
    }

    /**
     * @return void
     */
    public function testWithNoImageAnywhereThereIsNone(): void
    {
        $childProducts = $this->createStub(ChildProducts::class);
        $childProducts->method('get')->willReturn([$this->product(null)]);

        $this->assertNull((new VariantImage($childProducts))->forProduct($this->product('no_selection')));
    }

    /**
     * A product with the given stored base image. getImage() is never asked: its fallback is the cost
     * this avoids.
     *
     * @param string|null $image
     * @return Product
     */
    private function product(?string $image): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getData')->willReturnCallback(static fn (string $key) => $key === 'image' ? $image : null);
        $product->expects($this->never())->method('getImage');

        return $product;
    }
}
