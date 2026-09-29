<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\Variant;

use Magento\Catalog\Model\Product;
use Magento\Framework\DataObject;
use MageOS\Seo\Api\ProductVariantUrlResolverInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\GtinValidator;
use MageOS\Seo\Model\Product\OfferBuilder;
use MageOS\Seo\Model\Product\Variant\ChildProducts;
use MageOS\Seo\Model\Product\Variant\GtinReader;
use MageOS\Seo\Model\Product\Variant\ProductGroupBuilder;
use MageOS\Seo\Model\Product\Variant\VariantAttribute;
use MageOS\Seo\Model\Product\Variant\VariantAttributes;
use PHPUnit\Framework\TestCase;

/**
 * How a configurable's product node becomes a ProductGroup: which properties vary and how each is
 * written, what comes off the group, and when the node is left as the template built it.
 * ConfigurableProductOutputTest renders the same on a real product page.
 */
class ProductGroupBuilderTest extends TestCase
{
    private const NODE = [
        '@context' => 'https://schema.org',
        '@type'    => 'Product',
        '@id'      => 'https://example.com/tee.html#product',
        'name'     => 'Tee',
        'url'      => 'https://example.com/tee.html',
        'sku'      => 'TEE',
        'offers'   => ['@type' => 'AggregateOffer'],
        'image'    => ['https://example.com/tee-front.jpg', 'https://example.com/tee-back.jpg'],
    ];

    public function testEachOfGooglesDirectPropertiesIsWrittenOnTheVariantAndTakenOffTheGroup(): void
    {
        $attributes = [
            $this->attribute('color', 'Colour', 'color', ['1' => 'Red']),
            $this->attribute('material', 'Material', 'material', ['2' => 'Cotton']),
            $this->attribute('pattern', 'Pattern', 'pattern', ['3' => 'Striped']),
            $this->attribute('size', 'Size', 'size', ['4' => 'M']),
        ];
        $variant = $this->variant(7, 'TEE-RED-M', ['color' => '1', 'material' => '2', 'pattern' => '3', 'size' => '4']);
        $node    = self::NODE + ['color' => 'Red', 'material' => 'Cotton'];

        $group = $this->builder([$variant], $attributes)->build($node, $this->product(), []);

        $this->assertSame('ProductGroup', $group['@type']);
        $this->assertArrayNotHasKey('offers', $group, 'Offers belong to the variants.');
        $this->assertArrayNotHasKey('color', $group, 'What varies comes off the group.');
        $this->assertArrayNotHasKey('material', $group);
        $this->assertSame('TEE', $group['productGroupID']);
        $this->assertSame(
            [
                'https://schema.org/color',
                'https://schema.org/material',
                'https://schema.org/pattern',
                'https://schema.org/size',
            ],
            $group['variesBy']
        );
        $this->assertSame(
            [
                '@type'    => 'Product',
                'name'     => 'Variant 7',
                'sku'      => 'TEE-RED-M',
                'image'    => 'https://example.com/tee-front.jpg',
                'color'    => 'Red',
                'material' => 'Cotton',
                'pattern'  => 'Striped',
                'size'     => 'M',
                'offers'   => ['@type' => 'Offer', 'url' => 'variant-url:TEE-RED-M'],
            ],
            $group['hasVariant'][0]
        );
    }

    public function testGenderAndAnAgeInYearsGoUnderAudience(): void
    {
        $attributes = [
            $this->attribute('suggested_gender', 'Gender', 'suggestedGender', ['1' => 'female']),
            $this->attribute('suggested_age', 'Age', 'suggestedAge', ['2' => '13']),
        ];
        $variant = $this->variant(7, 'TEE-F-13', ['suggested_gender' => '1', 'suggested_age' => '2']);
        $node    = self::NODE + ['audience' => ['@type' => 'PeopleAudience', 'suggestedGender' => 'unisex']];

        $group = $this->builder([$variant], $attributes)->build($node, $this->product(), []);

        $this->assertArrayNotHasKey('audience', $group, 'An audience left with only its @type is dropped.');
        $this->assertSame(
            ['https://schema.org/suggestedGender', 'https://schema.org/suggestedAge'],
            $group['variesBy']
        );
        $this->assertSame(
            [
                '@type'           => 'PeopleAudience',
                'suggestedGender' => 'female',
                'suggestedAge'    => ['@type' => 'QuantitativeValue', 'value' => 13, 'unitCode' => 'ANN'],
            ],
            $group['hasVariant'][0]['audience']
        );
    }

    public function testAnAgeThatIsNotANumberIsAnAdditionalPropertyAndDoesNotVary(): void
    {
        $attributes = [$this->attribute('suggested_age', 'Age group', 'suggestedAge', ['2' => 'Adult'])];
        $variant    = $this->variant(7, 'TEE-ADULT', ['suggested_age' => '2']);

        $group = $this->builder([$variant], $attributes)->build(self::NODE, $this->product(), []);

        $this->assertArrayNotHasKey('variesBy', $group);
        $this->assertSame(
            [['@type' => 'PropertyValue', 'name' => 'Age group', 'value' => 'Adult']],
            $group['hasVariant'][0]['additionalProperty']
        );
    }

    public function testAnyOtherAttributeIsAnAdditionalPropertyAndDoesNotVary(): void
    {
        $attributes = [$this->attribute('fit', 'Fit', null, ['5' => 'Slim'])];
        $variant    = $this->variant(7, 'TEE-SLIM', ['fit' => '5']);

        $group = $this->builder([$variant], $attributes)->build(self::NODE, $this->product(), []);

        $this->assertArrayNotHasKey('variesBy', $group);
        $this->assertSame(
            [['@type' => 'PropertyValue', 'name' => 'Fit', 'value' => 'Slim']],
            $group['hasVariant'][0]['additionalProperty']
        );
    }

    public function testATemplatesOwnTypeStaysBesideProductGroup(): void
    {
        $node = ['@type' => ['Product', 'Book']] + self::NODE;

        $group = $this->builder([$this->variant(7, 'B-1', [])], [])->build($node, $this->product(), []);

        $this->assertSame(['ProductGroup', 'Book'], $group['@type']);
    }

    public function testEachVariantCarriesTheGroupsDescription(): void
    {
        $node = self::NODE + ['description' => 'A soft cotton tee.'];

        $group = $this->builder([$this->variant(7, 'TEE-1', []), $this->variant(8, 'TEE-2', [])], [])
            ->build($node, $this->product(), []);

        $this->assertSame('A soft cotton tee.', $group['description']);
        $this->assertSame('A soft cotton tee.', $group['hasVariant'][0]['description']);
        $this->assertSame('A soft cotton tee.', $group['hasVariant'][1]['description']);
    }

    public function testAGroupWithoutADescriptionGivesItsVariantsNone(): void
    {
        $group = $this->builder([$this->variant(7, 'TEE-1', [])], [])->build(self::NODE, $this->product(), []);

        $this->assertArrayNotHasKey('description', $group['hasVariant'][0]);
    }

    public function testAVariantWithImagesOfItsOwnUsesTheFirst(): void
    {
        $variant = $this->variant(7, 'TEE-RED', [], ['https://example.com/red.jpg', 'https://example.com/red-2.jpg']);

        $group = $this->builder([$variant], [])->build(self::NODE, $this->product(), []);

        $this->assertSame('https://example.com/red.jpg', $group['hasVariant'][0]['image']);
    }

    public function testVariantsCarryAValidGtinWhenTheTemplatesFieldIsEnabled(): void
    {
        $variants = [$this->variant(7, 'TEE-1', []), $this->variant(8, 'TEE-2', [])];
        $gtins    = [7 => '4006381333931', 8 => '5901234123450'];

        $group = $this->builder($variants, [], gtins: $gtins)->build(self::NODE, $this->product(), ['gtin13']);

        $this->assertSame('4006381333931', $group['hasVariant'][0]['gtin13']);
        $this->assertArrayNotHasKey('gtin13', $group['hasVariant'][1], 'A GTIN failing its check digit is left out.');
    }

    public function testGtinsAreNotReadWhenTheTemplatesFieldIsOff(): void
    {
        $gtinReader = $this->createMock(GtinReader::class);
        $gtinReader->expects($this->never())->method('read');

        $this->builder([$this->variant(7, 'TEE-1', [])], [], gtinReader: $gtinReader)
            ->build(self::NODE, $this->product(), []);
    }

    public function testMoreChildrenThanTheMaximumLeaveTheNodeAsItWas(): void
    {
        $variants = [$this->variant(7, 'TEE-1', []), $this->variant(8, 'TEE-2', []), $this->variant(9, 'TEE-3', [])];

        $this->assertSame(self::NODE, $this->builder($variants, [], max: 2)->build(self::NODE, $this->product(), []));
    }

    public function testZeroLeavesTheNodeAsItWas(): void
    {
        $variants = [$this->variant(7, 'TEE-1', [])];

        $this->assertSame(self::NODE, $this->builder($variants, [], max: 0)->build(self::NODE, $this->product(), []));
    }

    public function testAProductWithoutChildrenIsLeftAsItWas(): void
    {
        $this->assertSame(self::NODE, $this->builder([], [])->build(self::NODE, $this->product(), []));
    }

    /**
     * @param Product[] $variants
     * @param VariantAttribute[] $attributes
     * @param array<int, string> $gtins
     * @param int $max
     * @param GtinReader|null $gtinReader
     * @return ProductGroupBuilder
     */
    private function builder(
        array $variants,
        array $attributes,
        array $gtins = [],
        int $max = 50,
        ?GtinReader $gtinReader = null
    ): ProductGroupBuilder {
        $childProducts = $this->createStub(ChildProducts::class);
        $childProducts->method('get')->willReturn($variants);

        $variantAttributes = $this->createStub(VariantAttributes::class);
        $variantAttributes->method('forProduct')->willReturn($attributes);

        $urlResolver = $this->createStub(ProductVariantUrlResolverInterface::class);
        $urlResolver->method('getUrl')->willReturnCallback(
            static fn ($product, Product $variant): string => 'variant-url:' . $variant->getSku()
        );

        $offerBuilder = $this->createStub(OfferBuilder::class);
        $offerBuilder->method('build')->willReturnCallback(
            static fn ($product, string $url): array => ['@type' => 'Offer', 'url' => $url]
        );

        if ($gtinReader === null) {
            $gtinReader = $this->createStub(GtinReader::class);
            $gtinReader->method('read')->willReturn($gtins);
        }

        $seoConfig = $this->createStub(Config::class);
        $seoConfig->method('getHasVariantMax')->willReturn($max);

        return new ProductGroupBuilder(
            $childProducts,
            $variantAttributes,
            $urlResolver,
            $offerBuilder,
            $gtinReader,
            new GtinValidator(),
            $seoConfig
        );
    }

    /**
     * @param string $code
     * @param string $label
     * @param string|null $property
     * @param array<int|string, string> $options
     * @return VariantAttribute
     */
    private function attribute(string $code, string $label, ?string $property, array $options): VariantAttribute
    {
        return new VariantAttribute($code, $label, $property, $options);
    }

    /**
     * @param int $id
     * @param string $sku
     * @param array<string, string> $values Attribute code => option ID
     * @param string[] $images
     * @return Product
     */
    private function variant(int $id, string $sku, array $values, array $images = []): Product
    {
        $variant = $this->createStub(Product::class);
        $variant->method('getId')->willReturn($id);
        $variant->method('getSku')->willReturn($sku);
        $variant->method('getName')->willReturn('Variant ' . $id);
        $variant->method('getData')->willReturnCallback(static fn (string $key = '') => $values[$key] ?? null);
        $gallery = array_map(static fn (string $url): DataObject => new DataObject(['url' => $url]), $images);
        $variant->method('getMediaGalleryImages')->willReturn(new \ArrayIterator($gallery));

        return $variant;
    }

    /**
     * @return Product
     */
    private function product(): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getSku')->willReturn('TEE');

        return $product;
    }
}
