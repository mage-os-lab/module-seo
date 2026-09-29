<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\StructuredData\Provider;

use Magento\Catalog\Model\Product;
use MageOS\Seo\Model\Catalog\CurrentEntity;
use MageOS\Seo\Model\StructuredData\Provider\SpeakableProvider;
use MageOS\Seo\Model\StructuredData\SpeakableSpecification;
use PHPUnit\Framework\TestCase;

/**
 * A product page's WebPage node for the speakable spec: the product can't carry it, so the page
 * gets a node of its own whose mainEntity is the product.
 */
class SpeakableProviderTest extends TestCase
{
    private const SPEC = ['@type' => 'SpeakableSpecification', 'cssSelector' => ['.page-title']];

    public function testHandlesProductPagesOnly(): void
    {
        // Every other page type's own node carries the spec.
        $this->assertSame(['catalog_product_view'], $this->provider(self::SPEC, $this->product())->getHandles());
    }

    public function testNothingWhenSpeakableIsOff(): void
    {
        $this->assertSame([], $this->provider(null, $this->product())->getSchemas());
    }

    public function testNothingWithoutAProduct(): void
    {
        $this->assertSame([], $this->provider(self::SPEC, null)->getSchemas());
    }

    public function testTheProductPagesWebPageNode(): void
    {
        $this->assertSame(
            [[
                '@context'   => 'https://schema.org',
                '@type'      => 'WebPage',
                '@id'        => 'https://example.com/tee.html#webpage',
                'url'        => 'https://example.com/tee.html',
                'name'       => 'Tee',
                'mainEntity' => ['@id' => 'https://example.com/tee.html#product'],
                'speakable'  => self::SPEC,
            ]],
            $this->provider(self::SPEC, $this->product())->getSchemas()
        );
    }

    /**
     * @param array<string, mixed>|null $spec
     * @param Product|null $product
     * @return SpeakableProvider
     */
    private function provider(?array $spec, ?Product $product): SpeakableProvider
    {
        $speakable = $this->createStub(SpeakableSpecification::class);
        $speakable->method('get')->willReturn($spec);
        $currentEntity = $this->createStub(CurrentEntity::class);
        $currentEntity->method('getProduct')->willReturn($product);

        return new SpeakableProvider($currentEntity, $speakable);
    }

    /**
     * @return Product
     */
    private function product(): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getProductUrl')->willReturn('https://example.com/tee.html');
        $product->method('getName')->willReturn('Tee');

        return $product;
    }
}
