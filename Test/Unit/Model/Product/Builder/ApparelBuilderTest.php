<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\Builder;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use MageOS\Seo\Model\Product\Builder\ApparelBuilder;
use MageOS\Seo\Model\Product\GtinValidator;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Review\AggregateRatingResolver;
use MageOS\Seo\Service\CurrencyService;
use MageOS\Seo\Test\Unit\Service\CurrencyServices;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ApparelBuilderTest extends TestCase
{
    use CurrencyServices;
    use OfferBuilders;

    /**
     * @var StoreManagerInterface&Stub
     */
    private StoreManagerInterface&Stub $storeManager;

    /**
     * @var Store&Stub
     */
    private Store&Stub $store;

    /**
     * @var CurrencyService
     */
    private CurrencyService $currencyService;

    /**
     * @var AvailabilityResolver&Stub
     */
    private AvailabilityResolver&Stub $availabilityResolver;

    /**
     * @var ImageHelper&Stub
     */
    private ImageHelper&Stub $imageHelper;

    /**
     * @var Config&Stub
     */
    private Config&Stub $seoConfig;

    /**
     * @var DateTime&Stub
     */
    private DateTime&Stub $dateTime;

    /**
     * @var Product&Stub
     */
    private Product&Stub $product;

    /**
     * @var PriceInfoInterface&MockObject
     */
    private PriceInfoInterface&MockObject $priceInfo;

    /**
     * @var PriceInterface&Stub
     */
    private PriceInterface&Stub $finalPrice;

    /**
     * @var ApparelBuilder
     */
    private ApparelBuilder $builder;

    protected function setUp(): void
    {
        $this->storeManager    = $this->createStub(StoreManagerInterface::class);
        $this->store           = $this->createStub(Store::class);
        $this->currencyService = $this->currencyService();
        $this->availabilityResolver = $this->createStub(AvailabilityResolver::class);
        $this->imageHelper     = $this->createStub(ImageHelper::class);
        $this->seoConfig       = $this->createStub(Config::class);
        $this->dateTime        = $this->createStub(DateTime::class);
        $this->product         = $this->createStub(Product::class);
        $this->priceInfo       = $this->createMock(PriceInfoInterface::class);
        $this->finalPrice      = $this->createStub(PriceInterface::class);

        $this->storeManager->method('getStore')->willReturn($this->store);
        $this->store->method('getBaseUrl')->willReturn('https://example.com/');
        $this->finalPrice->method('getValue')->willReturn(59.99);
        $this->priceInfo->method('getPrice')->with('final_price')->willReturn($this->finalPrice);
        $this->product->method('getPriceInfo')->willReturn($this->priceInfo);
        $this->product->method('getName')->willReturn('Blue T-Shirt');
        $this->product->method('getSku')->willReturn('TS-BLUE-L');
        $this->product->method('getId')->willReturn(10);
        $this->product->method('getProductUrl')->willReturn('https://example.com/blue-tshirt');
        $this->product->method('getMediaGalleryImages')->willReturn(null);
        $this->imageHelper->method('init')->willReturnSelf();
        $this->imageHelper->method('getUrl')->willReturn('');

        $this->builder = new ApparelBuilder(
            $this->storeManager,
            $this->imageHelper,
            $this->seoConfig,
            $this->offerBuilder(
                $this->storeManager,
                $this->currencyService,
                $this->availabilityResolver,
                $this->dateTime,
                new OfferEnricherPool()
            ),
            new AggregateRatingResolver(),
            new GtinValidator()
        );
    }

    private function withInStock(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
    }

    public function testGetTemplateCode(): void
    {
        $this->assertSame('Apparel', $this->builder->getTemplateCode());
    }

    public function testGetLabel(): void
    {
        $this->assertSame('Clothing & Apparel', $this->builder->getLabel());
    }

    public function testGetAvailableFieldsIncludesApparelSpecificFields(): void
    {
        $fields = $this->builder->getAvailableFields();
        $this->assertArrayHasKey('brand', $fields);
        $this->assertArrayHasKey('color', $fields);
        $this->assertArrayHasKey('size', $fields);
        $this->assertArrayHasKey('material', $fields);
        $this->assertArrayHasKey('gender', $fields);
        $this->assertArrayHasKey('pattern', $fields);
    }

    public function testBuildReturnsProductType(): void
    {
        // "Apparel" is not a schema.org type; apparel items are plain Products.
        $this->withInStock();
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('Product', $schema['@type']);
    }

    public function testBuildBrandFromManufacturerAttributeWhenEnabled(): void
    {
        $this->withInStock();
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'manufacturer' ? 'Nike' : null
        );
        $this->product->method('getAttributeText')->willReturnCallback(
            fn (string $key) => $key === 'manufacturer' ? 'Nike' : false
        );
        $schema = $this->builder->build($this->product, ['brand'], []);
        $this->assertSame('Brand', $schema['brand']['@type']);
        $this->assertSame('Nike', $schema['brand']['name']);
    }

    public function testBuildBrandNotIncludedWhenFieldNotEnabled(): void
    {
        $this->withInStock();
        $schema = $this->builder->build($this->product, [], []);
        $this->assertArrayNotHasKey('brand', $schema);
    }

    public function testBuildColorFromProductAttributeWhenEnabled(): void
    {
        $this->withInStock();
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'color' ? 'Blue' : null
        );
        $this->product->method('getAttributeText')->willReturnCallback(
            fn (string $key) => $key === 'color' ? 'Blue' : false
        );
        $schema = $this->builder->build($this->product, ['color'], []);
        $this->assertSame('Blue', $schema['color']);
    }

    public function testBuildColorNotAddedToOffersNode(): void
    {
        // schema.org defines color on Product, not on Offer.
        $this->withInStock();
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'color' ? 'Green' : null
        );
        $this->product->method('getAttributeText')->willReturnCallback(
            fn (string $key) => $key === 'color' ? 'Green' : false
        );
        $schema = $this->builder->build($this->product, ['color'], []);
        $this->assertSame('Green', $schema['color']);
        $this->assertArrayNotHasKey('color', $schema['offers']);
    }

    public function testBuildColorNotIncludedWhenFieldNotEnabled(): void
    {
        $this->withInStock();
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'color' ? 'Blue' : null
        );
        $this->product->method('getAttributeText')->willReturnCallback(
            fn (string $key) => $key === 'color' ? 'Blue' : false
        );
        $schema = $this->builder->build($this->product, [], []);
        $this->assertArrayNotHasKey('color', $schema);
    }

    public function testBuildSizeNotAddedToOffersNode(): void
    {
        // schema.org defines size on Product, not on Offer.
        $this->withInStock();
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'size' ? 'XL' : null
        );
        $this->product->method('getAttributeText')->willReturnCallback(
            fn (string $key) => $key === 'size' ? 'XL' : false
        );
        $schema = $this->builder->build($this->product, ['size'], []);
        $this->assertSame('XL', $schema['size']);
        $this->assertArrayNotHasKey('size', $schema['offers']);
    }

    public function testBuildSizeFromProductAttributeWhenEnabled(): void
    {
        $this->withInStock();
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'size' ? 'M' : null
        );
        $this->product->method('getAttributeText')->willReturnCallback(
            fn (string $key) => $key === 'size' ? 'M' : false
        );
        $schema = $this->builder->build($this->product, ['size'], []);
        $this->assertSame('M', $schema['size']);
    }

    public function testBuildSizeNotIncludedWhenFieldNotEnabled(): void
    {
        $this->withInStock();
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'size' ? 'L' : null
        );
        $this->product->method('getAttributeText')->willReturnCallback(
            fn (string $key) => $key === 'size' ? 'L' : false
        );
        $schema = $this->builder->build($this->product, [], []);
        $this->assertArrayNotHasKey('size', $schema);
    }

    public function testBuildWeightFromAttributeWhenEnabled(): void
    {
        $this->withInStock();
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'weight' ? '0.2' : null
        );
        $this->product->method('getAttributeText')->willReturn(false);
        $schema = $this->builder->build($this->product, ['weight'], []);
        $this->assertSame('0.2', $schema['weight'] ?? null);
    }

    public function testBuildWeightFromOverride(): void
    {
        $this->withInStock();
        $this->product->method('getAttributeText')->willReturn(false);
        $schema = $this->builder->build($this->product, ['weight'], ['weight' => '250 g']);
        $this->assertSame('250 g', $schema['weight'] ?? null);
    }

    public function testBuildMaterialFromAttributeWhenEnabled(): void
    {
        $this->withInStock();
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'material' ? 'Cotton' : null
        );
        $this->product->method('getAttributeText')->willReturnCallback(
            fn (string $key) => $key === 'material' ? 'Cotton' : false
        );
        $schema = $this->builder->build($this->product, ['material'], []);
        $this->assertSame('Cotton', $schema['material']);
    }

    public function testBuildGenderAddsAudienceNodeWhenEnabled(): void
    {
        $this->withInStock();
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'gender' ? 'Male' : null
        );
        $this->product->method('getAttributeText')->willReturnCallback(
            fn (string $key) => $key === 'gender' ? 'Male' : false
        );
        $schema = $this->builder->build($this->product, ['gender'], []);
        $this->assertArrayHasKey('audience', $schema);
        $this->assertSame('PeopleAudience', $schema['audience']['@type']);
        $this->assertSame('Male', $schema['audience']['suggestedGender']);
    }

    public function testBuildGenderNotIncludedWhenFieldNotEnabled(): void
    {
        $this->withInStock();
        $schema = $this->builder->build($this->product, [], []);
        $this->assertArrayNotHasKey('audience', $schema);
    }

    public function testBuildPatternFromAttributeWhenEnabled(): void
    {
        $this->withInStock();
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'pattern' ? 'Striped' : null
        );
        $this->product->method('getAttributeText')->willReturnCallback(
            fn (string $key) => $key === 'pattern' ? 'Striped' : false
        );
        $schema = $this->builder->build($this->product, ['pattern'], []);
        $this->assertSame('Striped', $schema['pattern']);
    }

    public function testBuildCountryOfOriginWhenEnabled(): void
    {
        $this->withInStock();
        // attr() only calls getAttributeText() for numeric values; 'GB' is non-numeric
        // so the raw getData() value is returned directly.
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'country_of_origin' ? 'GB' : null
        );
        $schema = $this->builder->build($this->product, ['countryOfOrigin'], []);
        $this->assertSame('GB', $schema['countryOfOrigin']);
    }

    public function testBuildAllApparelFieldsTogetherDoNotCollide(): void
    {
        $this->withInStock();
        $attrMap = [
            'manufacturer' => 'Nike',
            'color'        => 'Blue',
            'size'         => 'L',
            'material'     => 'Cotton',
            'gender'       => 'Male',
            'pattern'      => 'Plain',
        ];
        $this->product->method('getData')->willReturnCallback(fn (string $key) => $attrMap[$key] ?? null);
        $this->product->method('getAttributeText')->willReturnCallback(fn (string $key) => $attrMap[$key] ?? false);

        $schema = $this->builder->build(
            $this->product,
            ['brand', 'color', 'size', 'material', 'gender', 'pattern'],
            []
        );

        $this->assertSame('Nike', $schema['brand']['name']);
        $this->assertSame('Blue', $schema['color']);
        $this->assertSame('L', $schema['size']);
        $this->assertSame('Cotton', $schema['material']);
        $this->assertSame('Male', $schema['audience']['suggestedGender']);
        $this->assertSame('Plain', $schema['pattern']);
    }

    public function testBuildGtin13FromOverrideWhenEnabled(): void
    {
        $this->withInStock();
        $schema = $this->builder->build($this->product, ['gtin13'], ['gtin13' => '5901234123457']);
        $this->assertSame('5901234123457', $schema['gtin13']);
    }

    public function testBuildColorFallsBackToColourAttributeName(): void
    {
        $this->withInStock();
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'colour' ? 'Purple' : null
        );
        $this->product->method('getAttributeText')->willReturnCallback(
            fn (string $key) => $key === 'colour' ? 'Purple' : false
        );
        $schema = $this->builder->build($this->product, ['color'], []);
        $this->assertSame('Purple', $schema['color']);
    }
}
