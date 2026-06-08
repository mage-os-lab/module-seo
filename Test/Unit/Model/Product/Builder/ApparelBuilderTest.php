<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\Builder;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use MageOS\Seo\Service\CurrencyService;
use MageOS\Seo\Model\Product\Builder\ApparelBuilder;

class ApparelBuilderTest extends TestCase
{
    /**
     * @var StoreManagerInterface&MockObject
     */
    private StoreManagerInterface&MockObject $storeManager;

    /**
     * @var Store&MockObject
     */
    private Store&MockObject $store;

    /**
     * @var CurrencyService&MockObject
     */
    private CurrencyService&MockObject $currencyService;

    /**
     * @var StockRegistryInterface&MockObject
     */
    private StockRegistryInterface&MockObject $stockRegistry;

    /**
     * @var ImageHelper&MockObject
     */
    private ImageHelper&MockObject $imageHelper;

    /**
     * @var ScopeConfigInterface&MockObject
     */
    private ScopeConfigInterface&MockObject $scopeConfig;

    /**
     * @var DateTime&MockObject
     */
    private DateTime&MockObject $dateTime;

    /**
     * @var Product&MockObject
     */
    private Product&MockObject $product;

    /**
     * @var PriceInfoInterface&MockObject
     */
    private PriceInfoInterface&MockObject $priceInfo;

    /**
     * @var PriceInterface&MockObject
     */
    private PriceInterface&MockObject $finalPrice;

    /**
     * @var ApparelBuilder
     */
    private ApparelBuilder $builder;

    protected function setUp(): void
    {
        $this->storeManager    = $this->createMock(StoreManagerInterface::class);
        $this->store           = $this->createMock(Store::class);
        $this->currencyService = $this->createMock(CurrencyService::class);
        $this->stockRegistry   = $this->createMock(StockRegistryInterface::class);
        $this->imageHelper     = $this->createMock(ImageHelper::class);
        $this->scopeConfig     = $this->createMock(ScopeConfigInterface::class);
        $this->dateTime        = $this->createMock(DateTime::class);
        $this->product         = $this->createMock(Product::class);
        $this->priceInfo       = $this->createMock(PriceInfoInterface::class);
        $this->finalPrice      = $this->createMock(PriceInterface::class);

        $this->storeManager->method('getStore')->willReturn($this->store);
        $this->store->method('getBaseUrl')->willReturn('https://example.com/');
        $this->currencyService->method('getCurrentCurrencyCode')->willReturn('GBP');
        $this->finalPrice->method('getValue')->willReturn(59.99);
        $this->priceInfo->method('getPrice')->with('final_price')->willReturn($this->finalPrice);
        $this->product->method('getPriceInfo')->willReturn($this->priceInfo);
        $this->product->method('getName')->willReturn('Blue T-Shirt');
        $this->product->method('getSku')->willReturn('TS-BLUE-L');
        $this->product->method('getId')->willReturn(10);
        $this->product->method('getProductUrl')->willReturn('https://example.com/blue-tshirt');
        $this->product->method('getMediaGalleryImages')->willReturn(null);
        $this->scopeConfig->method('getValue')->willReturn(3);
        $this->imageHelper->method('init')->willReturnSelf();
        $this->imageHelper->method('getUrl')->willReturn('');

        $this->builder = new ApparelBuilder(
            $this->storeManager,
            $this->currencyService,
            $this->stockRegistry,
            $this->imageHelper,
            $this->scopeConfig,
            $this->dateTime
        );
    }

    private function makeStockItem(bool $inStock): StockItemInterface&MockObject
    {
        $item = $this->createMock(StockItemInterface::class);
        $item->method('getIsInStock')->willReturn($inStock);
        return $item;
    }

    private function withInStock(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
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

    public function testBuildReturnsApparelType(): void
    {
        $this->withInStock();
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertSame('Apparel', $schema['@type']);
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
        $schema = $this->builder->build($this->product, ['brand'], [], []);
        $this->assertSame('Brand', $schema['brand']['@type']);
        $this->assertSame('Nike', $schema['brand']['name']);
    }

    public function testBuildBrandNotIncludedWhenFieldNotEnabled(): void
    {
        $this->withInStock();
        $schema = $this->builder->build($this->product, [], [], []);
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
        $schema = $this->builder->build($this->product, ['color'], [], []);
        $this->assertSame('Blue', $schema['color']);
    }

    public function testBuildColorFromVariantDataPreferredOverAttribute(): void
    {
        $this->withInStock();
        $schema = $this->builder->build($this->product, ['color'], [], ['color' => 'Red']);
        $this->assertSame('Red', $schema['color']);
    }

    public function testBuildColorAlsoAddedToOffersNode(): void
    {
        $this->withInStock();
        $schema = $this->builder->build($this->product, ['color'], [], ['color' => 'Green']);
        $this->assertSame('Green', $schema['offers']['color']);
    }

    public function testBuildColorNotIncludedWhenFieldNotEnabled(): void
    {
        $this->withInStock();
        $schema = $this->builder->build($this->product, [], [], ['color' => 'Blue']);
        $this->assertArrayNotHasKey('color', $schema);
    }

    public function testBuildSizeFromVariantDataWhenEnabled(): void
    {
        $this->withInStock();
        $schema = $this->builder->build($this->product, ['size'], [], ['size' => 'XL']);
        $this->assertSame('XL', $schema['size']);
        $this->assertSame('XL', $schema['offers']['size']);
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
        $schema = $this->builder->build($this->product, ['size'], [], []);
        $this->assertSame('M', $schema['size']);
    }

    public function testBuildSizeNotIncludedWhenFieldNotEnabled(): void
    {
        $this->withInStock();
        $schema = $this->builder->build($this->product, [], [], ['size' => 'L']);
        $this->assertArrayNotHasKey('size', $schema);
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
        $schema = $this->builder->build($this->product, ['material'], [], []);
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
        $schema = $this->builder->build($this->product, ['gender'], [], []);
        $this->assertArrayHasKey('audience', $schema);
        $this->assertSame('PeopleAudience', $schema['audience']['@type']);
        $this->assertSame('Male', $schema['audience']['suggestedGender']);
    }

    public function testBuildGenderNotIncludedWhenFieldNotEnabled(): void
    {
        $this->withInStock();
        $schema = $this->builder->build($this->product, [], [], []);
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
        $schema = $this->builder->build($this->product, ['pattern'], [], []);
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
        $schema = $this->builder->build($this->product, ['countryOfOrigin'], [], []);
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
            [],
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
        $schema = $this->builder->build($this->product, ['gtin13'], ['gtin13' => '5901234123457'], []);
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
        $schema = $this->builder->build($this->product, ['color'], [], []);
        $this->assertSame('Purple', $schema['color']);
    }
}
