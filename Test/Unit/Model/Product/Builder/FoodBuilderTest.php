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
use MageOS\Seo\Model\Product\Builder\FoodBuilder;
use MageOS\Seo\Model\Product\GtinValidator;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Review\AggregateRatingResolver;
use MageOS\Seo\Service\CurrencyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FoodBuilderTest extends TestCase
{
    /**
     * @var Product&MockObject
     */
    private Product&MockObject $product;

    /**
     * @var AvailabilityResolver&MockObject
     */
    private AvailabilityResolver&MockObject $availabilityResolver;

    /**
     * @var FoodBuilder
     */
    private FoodBuilder $builder;

    protected function setUp(): void
    {
        $storeManager    = $this->createMock(StoreManagerInterface::class);
        $store           = $this->createMock(Store::class);
        $currencyService = $this->createMock(CurrencyService::class);
        $imageHelper     = $this->createMock(ImageHelper::class);
        $seoConfig       = $this->createMock(Config::class);
        $priceInfo       = $this->createMock(PriceInfoInterface::class);
        $finalPrice      = $this->createMock(PriceInterface::class);

        $this->availabilityResolver = $this->createMock(AvailabilityResolver::class);
        $this->product              = $this->createMock(Product::class);

        $storeManager->method('getStore')->willReturn($store);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $currencyService->method('getCurrentCurrencyCode')->willReturn('GBP');
        $currencyService->method('convertFromBase')->willReturnArgument(0);
        $finalPrice->method('getValue')->willReturn(4.99);
        $priceInfo->method('getPrice')->with('final_price')->willReturn($finalPrice);
        $this->product->method('getPriceInfo')->willReturn($priceInfo);
        $this->product->method('getName')->willReturn('Organic Honey');
        $this->product->method('getSku')->willReturn('HONEY-01');
        $this->product->method('getId')->willReturn(20);
        $this->product->method('getProductUrl')->willReturn('https://example.com/honey');
        $this->product->method('getMediaGalleryImages')->willReturn(null);
        $seoConfig->method('getPriceValidUntilMonths')->willReturn(3);
        $imageHelper->method('init')->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn('');
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);

        $this->builder = new FoodBuilder(
            $storeManager,
            $currencyService,
            $this->availabilityResolver,
            $imageHelper,
            $seoConfig,
            $this->createMock(DateTime::class),
            new OfferEnricherPool(),
            new AggregateRatingResolver(),
            new GtinValidator()
        );
    }

    /**
     * Find an additionalProperty PropertyValue entry by name, or null.
     *
     * @param array<string, mixed> $schema
     * @param string $name
     * @return array<string, mixed>|null
     */
    private function findAdditionalProperty(array $schema, string $name): ?array
    {
        foreach ($schema['additionalProperty'] ?? [] as $entry) {
            if (($entry['name'] ?? null) === $name) {
                return $entry;
            }
        }
        return null;
    }

    public function testGetTemplateCode(): void
    {
        $this->assertSame('Food', $this->builder->getTemplateCode());
    }

    public function testGetLabel(): void
    {
        $this->assertSame('Food Product', $this->builder->getLabel());
    }

    public function testGetAvailableFieldsIncludesFoodSpecificFields(): void
    {
        $fields = $this->builder->getAvailableFields();
        $this->assertArrayHasKey('nutritionInformation', $fields);
        $this->assertArrayHasKey('containsAllergen', $fields);
        $this->assertArrayHasKey('isAlcoholicBeverage', $fields);
    }

    public function testBuildReturnsPlainProductType(): void
    {
        // "FoodProduct" is not a schema.org type; food items are plain Products.
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('Product', $schema['@type']);
    }

    public function testNutritionInformationBecomesAdditionalPropertyNotTopLevel(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'nutrition_info' ? 'Calories: 200 per 100g' : null
        );
        $schema = $this->builder->build($this->product, ['nutritionInformation'], []);

        $this->assertArrayNotHasKey('nutritionInformation', $schema);
        $entry = $this->findAdditionalProperty($schema, 'nutritionInformation');
        $this->assertNotNull($entry);
        $this->assertSame('PropertyValue', $entry['@type']);
        $this->assertSame('Calories: 200 per 100g', $entry['value']);
    }

    public function testAllergensBecomeAdditionalProperty(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'allergens' ? 'Peanuts, Milk' : null
        );
        $schema = $this->builder->build($this->product, ['containsAllergen'], []);

        $entry = $this->findAdditionalProperty($schema, 'allergens');
        $this->assertNotNull($entry);
        $this->assertSame('Peanuts, Milk', $entry['value']);
    }

    public function testAlcoholicBeverageBecomesYesNoAdditionalProperty(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'is_alcoholic_beverage' ? 'yes' : null
        );
        $schema = $this->builder->build($this->product, ['isAlcoholicBeverage'], []);

        $entry = $this->findAdditionalProperty($schema, 'alcoholicBeverage');
        $this->assertNotNull($entry);
        $this->assertSame('Yes', $entry['value']);
    }

    public function testValidGtinFromBarcodeAttributeIsEmitted(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'barcode' ? '5901234123457' : null
        );
        $schema = $this->builder->build($this->product, ['gtin13'], []);
        $this->assertSame('5901234123457', $schema['gtin13']);
    }

    public function testInvalidGtinFromBarcodeAttributeIsOmitted(): void
    {
        // Wrong GS1 check digit (valid value ends 7) — must not be emitted.
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'barcode' ? '5901234123450' : null
        );
        $schema = $this->builder->build($this->product, ['gtin13'], []);
        $this->assertArrayNotHasKey('gtin13', $schema);
    }

    public function testCountryOfOriginAndWeightAreTopLevel(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => match ($key) {
                'country_of_origin' => 'FR',
                'weight'            => '500g',
                default             => null,
            }
        );
        $schema = $this->builder->build($this->product, ['countryOfOrigin', 'weight'], []);
        $this->assertSame('FR', $schema['countryOfOrigin']);
        $this->assertSame('500g', $schema['weight']);
    }
}
