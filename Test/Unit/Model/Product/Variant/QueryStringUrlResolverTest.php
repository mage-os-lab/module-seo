<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\Variant;

use Magento\Catalog\Model\Product;
use MageOS\Seo\Model\Product\Variant\QueryStringUrlResolver;
use MageOS\Seo\Model\Product\Variant\VariantAttribute;
use MageOS\Seo\Model\Product\Variant\VariantAttributes;
use PHPUnit\Framework\TestCase;

/**
 * A variant's URL: the product's URL with `?{attribute_code}={option_id}` for each configurable
 * attribute, the form Luma's swatch renderer preselects options from.
 */
class QueryStringUrlResolverTest extends TestCase
{
    public function testEachAttributeNamesTheVariantsOption(): void
    {
        $this->assertSame(
            'https://example.com/tee.html?color=49&size=167',
            $this->resolver()->getUrl(
                $this->product('https://example.com/tee.html'),
                $this->variant(['color' => '49', 'size' => '167'])
            )
        );
    }

    public function testAUrlWithAQueryAlreadyIsAddedTo(): void
    {
        $this->assertSame(
            'https://example.com/tee.html?___store=uk&color=49&size=167',
            $this->resolver()->getUrl(
                $this->product('https://example.com/tee.html?___store=uk'),
                $this->variant(['color' => '49', 'size' => '167'])
            )
        );
    }

    public function testAnAttributeTheVariantHasNoOptionForIsLeftOut(): void
    {
        $this->assertSame(
            'https://example.com/tee.html?size=167',
            $this->resolver()->getUrl($this->product('https://example.com/tee.html'), $this->variant(['size' => '167']))
        );
    }

    public function testNoOptionsIsTheProductsUrl(): void
    {
        $this->assertSame(
            'https://example.com/tee.html',
            $this->resolver()->getUrl($this->product('https://example.com/tee.html'), $this->variant([]))
        );
    }

    /**
     * @return QueryStringUrlResolver
     */
    private function resolver(): QueryStringUrlResolver
    {
        $variantAttributes = $this->createStub(VariantAttributes::class);
        $variantAttributes->method('forProduct')->willReturn([
            new VariantAttribute('color', 'Colour', 'color', []),
            new VariantAttribute('size', 'Size', 'size', []),
        ]);

        return new QueryStringUrlResolver($variantAttributes);
    }

    /**
     * @param string $url
     * @return Product
     */
    private function product(string $url): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getProductUrl')->willReturn($url);

        return $product;
    }

    /**
     * @param array<string, string> $values Attribute code => option ID
     * @return Product
     */
    private function variant(array $values): Product
    {
        $variant = $this->createStub(Product::class);
        $variant->method('getData')->willReturnCallback(static fn (string $key = '') => $values[$key] ?? null);

        return $variant;
    }
}
