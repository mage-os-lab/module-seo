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
use MageOS\Seo\Model\Product\Builder\GenericProductBuilder;
use MageOS\Seo\Model\Product\GtinValidator;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Review\AggregateRatingResolver;
use MageOS\Seo\Service\CurrencyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GenericProductBuilderTest extends TestCase
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
     * @var AvailabilityResolver&MockObject
     */
    private AvailabilityResolver&MockObject $availabilityResolver;

    /**
     * @var ImageHelper&MockObject
     */
    private ImageHelper&MockObject $imageHelper;

    /**
     * @var Config&MockObject
     */
    private Config&MockObject $seoConfig;

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
     * @var GenericProductBuilder
     */
    private GenericProductBuilder $builder;

    protected function setUp(): void
    {
        $this->storeManager    = $this->createMock(StoreManagerInterface::class);
        $this->store           = $this->createMock(Store::class);
        $this->currencyService = $this->createMock(CurrencyService::class);
        $this->availabilityResolver = $this->createMock(AvailabilityResolver::class);
        $this->imageHelper     = $this->createMock(ImageHelper::class);
        $this->seoConfig       = $this->createMock(Config::class);
        $this->dateTime        = $this->createMock(DateTime::class);
        // getShortDescription / getDescription are magic __call() methods on Product.
        $this->product         = $this->createMock(Product::class);
        $this->priceInfo       = $this->createMock(PriceInfoInterface::class);
        $this->finalPrice      = $this->createMock(PriceInterface::class);

        $this->storeManager->method('getStore')->willReturn($this->store);
        $this->store->method('getBaseUrl')->willReturn('https://example.com/');
        $this->currencyService->method('getCurrentCurrencyCode')->willReturn('GBP');
        $this->currencyService->method('convertFromBase')->willReturnArgument(0);
        // Note: availability and getData/getAttributeText are NOT stubbed here — per-test
        // configuration avoids PHPUnit 10's first-match-wins stub ordering issue.
        $this->finalPrice->method('getValue')->willReturn(29.99);
        $this->priceInfo->method('getPrice')->with('final_price')->willReturn($this->finalPrice);
        $this->product->method('getPriceInfo')->willReturn($this->priceInfo);
        $this->product->method('getName')->willReturn('Test Widget');
        $this->product->method('getSku')->willReturn('SKU-001');
        $this->product->method('getId')->willReturn(42);
        $this->product->method('getProductUrl')->willReturn('https://example.com/test-widget');
        // getMediaGalleryImages and imageHelper->getUrl() are NOT stubbed in setUp:
        // tests that need specific values configure them individually to avoid
        // PHPUnit 10's first-match-wins stub ordering issue.
        $this->seoConfig->method('getPriceValidUntilMonths')->willReturn(3);
        $this->imageHelper->method('init')->willReturnSelf();

        $this->builder = new GenericProductBuilder(
            $this->storeManager,
            $this->currencyService,
            $this->availabilityResolver,
            $this->imageHelper,
            $this->seoConfig,
            $this->dateTime,
            new OfferEnricherPool(),
            new AggregateRatingResolver(),
            new GtinValidator()
        );
    }

    public function testGetTemplateCode(): void
    {
        $this->assertSame('GenericProduct', $this->builder->getTemplateCode());
    }

    public function testGetLabel(): void
    {
        $this->assertSame('Generic Product', $this->builder->getLabel());
    }

    public function testGetAvailableFieldsReturnsExpectedKeys(): void
    {
        $fields = $this->builder->getAvailableFields();
        $this->assertArrayHasKey('gtin13', $fields);
        $this->assertArrayHasKey('brand', $fields);
        $this->assertArrayHasKey('color', $fields);
        $this->assertArrayHasKey('weight', $fields);
    }

    public function testBuildReturnsSchemaWithRequiredFields(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertSame('Product', $schema['@type']);
        $this->assertSame('Test Widget', $schema['name']);
        $this->assertSame('SKU-001', $schema['sku']);
        $this->assertSame('https://example.com/test-widget', $schema['url']);
    }

    public function testBuildSetsCorrectSchemaId(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('https://example.com/test-widget#product', $schema['@id']);
    }

    public function testBuildIncludesOffers(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $schema = $this->builder->build($this->product, [], []);
        $this->assertArrayHasKey('offers', $schema);
        $this->assertSame('Offer', $schema['offers']['@type']);
        $this->assertSame('GBP', $schema['offers']['priceCurrency']);
    }

    public function testBuildFormatsPrice(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('29.99', $schema['offers']['price']);
    }

    public function testBuildAvailabilityInStockWhenProductInStock(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('https://schema.org/InStock', $schema['offers']['availability']);
    }

    public function testBuildAvailabilityOutOfStockWhenProductOutOfStock(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::OUT_OF_STOCK);
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('https://schema.org/OutOfStock', $schema['offers']['availability']);
    }

    public function testBuildOfferUrlIsProductUrl(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('https://example.com/test-widget', $schema['offers']['url']);
    }

    public function testBuildDescriptionFromShortDescription(): void
    {
        // getShortDescription() is a magic __call() on Product; stub via __call.
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $this->product->method('__call')->willReturnCallback(
            fn (string $m) => $m === 'getShortDescription' ? 'Short desc' : null
        );
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('Short desc', $schema['description']);
    }

    public function testBuildDescriptionFallsBackToFullDescription(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $this->product->method('__call')->willReturnCallback(
            fn (string $m) => match ($m) {
                'getShortDescription' => '',
                'getDescription'      => 'Full description',
                default               => null,
            }
        );
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('Full description', $schema['description']);
    }

    public function testBuildDescriptionStripsHtmlTags(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $this->product->method('__call')->willReturnCallback(
            fn (string $m) => $m === 'getShortDescription' ? '<p>A <strong>great</strong> product</p>' : null
        );
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('A great product', $schema['description']);
    }

    public function testBuildDescriptionDecodesHtmlEntities(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $this->product->method('__call')->willReturnCallback(
            fn (string $m) => $m === 'getShortDescription' ? 'Caf&eacute; &amp; Co' : null
        );
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('Café & Co', $schema['description']);
    }

    public function testBuildDescriptionOmittedWhenEmpty(): void
    {
        // No __call stub — returns null by default → description omitted.
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $schema = $this->builder->build($this->product, [], []);
        $this->assertArrayNotHasKey('description', $schema);
    }

    public function testBuildDescriptionTruncatedTo5000Chars(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $longText = str_repeat('x', 6000);
        $this->product->method('__call')->willReturnCallback(
            fn (string $m) => $m === 'getShortDescription' ? $longText : null
        );
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame(5000, mb_strlen($schema['description']));
    }

    public function testBuildIncludesImageFromMediaGallery(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        // Use an anonymous class instead of mocking DataObject to avoid __call() stub complexity.
        $image = new class () {
            public function getUrl(): string
            {
                return 'https://example.com/media/product.jpg';
            }
        };
        $this->product->method('getMediaGalleryImages')->willReturn([$image]);
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('https://example.com/media/product.jpg', $schema['image']);
    }

    public function testBuildImageIsArrayWhenMultipleImages(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $makeImage = static fn (string $url) => new class ($url) {
            public function __construct(private readonly string $u)
            {
            }

            public function getUrl(): string
            {
                return $this->u;
            }
        };
        $this->product->method('getMediaGalleryImages')->willReturn([
            $makeImage('https://example.com/img1.jpg'),
            $makeImage('https://example.com/img2.jpg'),
        ]);
        $schema = $this->builder->build($this->product, [], []);
        $this->assertIsArray($schema['image']);
        $this->assertCount(2, $schema['image']);
    }

    public function testBuildFallsBackToImageHelperWhenGalleryEmpty(): void
    {
        // gallery returns null (default) → imageHelper fallback is triggered.
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $this->imageHelper->method('getUrl')->willReturn('https://example.com/fallback.jpg');
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('https://example.com/fallback.jpg', $schema['image']);
    }

    public function testBuildImageOmittedWhenGalleryEmptyAndHelperReturnsEmpty(): void
    {
        // gallery null + imageHelper returns null → no image key in schema.
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $schema = $this->builder->build($this->product, [], []);
        $this->assertArrayNotHasKey('image', $schema);
    }

    public function testBuildBrandIncludedWhenFieldEnabled(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'manufacturer' ? 'Acme' : null
        );
        $this->product->method('getAttributeText')->willReturnCallback(
            fn (string $key) => $key === 'manufacturer' ? 'Acme' : false
        );
        $schema = $this->builder->build($this->product, ['brand'], []);
        $this->assertArrayHasKey('brand', $schema);
        $this->assertSame('Brand', $schema['brand']['@type']);
        $this->assertSame('Acme', $schema['brand']['name']);
    }

    public function testBuildBrandNotIncludedWhenFieldNotEnabled(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $schema = $this->builder->build($this->product, [], []);
        $this->assertArrayNotHasKey('brand', $schema);
    }

    public function testBuildBrandOverrideSetsSchemaKeyDirectly(): void
    {
        // applyOverrides() sets schema['brand'] to the plain string value from overrides.
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $schema = $this->builder->build($this->product, ['brand'], ['brand' => 'Override Brand']);
        $this->assertSame('Override Brand', $schema['brand']);
    }

    public function testBuildGtin13IncludedWhenFieldEnabledAndValueValid(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'gtin13' ? '4006381333931' : null
        );
        $this->product->method('getAttributeText')->willReturn(false);
        $schema = $this->builder->build($this->product, ['gtin13'], []);
        $this->assertSame('4006381333931', $schema['gtin13']);
    }

    public function testBuildGtin13OmittedWhenValueFailsGs1Validation(): void
    {
        // Free-form barcode content (internal SKUs, wrong check digits) must be
        // omitted rather than emitted as an invalid gtin13.
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'gtin13' ? '1234567890123' : null
        );
        $this->product->method('getAttributeText')->willReturn(false);
        $schema = $this->builder->build($this->product, ['gtin13'], []);
        $this->assertArrayNotHasKey('gtin13', $schema);
    }

    public function testBuildColorFromProductAttributeWhenEnabled(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'color' ? 'Red' : null
        );
        $this->product->method('getAttributeText')->willReturn(false);
        $schema = $this->builder->build($this->product, ['color'], []);
        $this->assertSame('Red', $schema['color']);
    }

    public function testBuildWeightFromProductAttributeWhenEnabled(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'weight' ? '1.5kg' : null
        );
        $this->product->method('getAttributeText')->willReturn(false);
        $schema = $this->builder->build($this->product, ['weight'], []);
        $this->assertArrayHasKey('weight', $schema);
        $this->assertSame('1.5kg', $schema['weight']);
    }

    public function testBuildOverridesAppliedToFinalSchema(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $schema = $this->builder->build($this->product, [], ['name' => 'Overridden Name']);
        $this->assertSame('Overridden Name', $schema['name']);
    }

    public function testBuildOverridesDoNotApplyNullValues(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $schema = $this->builder->build($this->product, [], ['name' => null]);
        $this->assertSame('Test Widget', $schema['name']);
    }

    public function testBuildPriceValidUntilUsesSyntheticWindowWhenNoSpecialPrice(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $this->dateTime->method('date')->willReturnCallback(
            static fn (string $format, ?string $input = null): string => $input === null ? '2026-07-10' : '2026-10-10'
        );
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('2026-10-10', $schema['offers']['priceValidUntil']);
    }

    public function testBuildPriceValidUntilPrefersActiveSpecialPriceEndDate(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $this->dateTime->method('date')->willReturn('2026-07-10');
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key): ?string => $key === 'special_to_date' ? '2026-08-01 00:00:00' : null
        );
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('2026-08-01', $schema['offers']['priceValidUntil']);
    }

    public function testBuildOmitsPriceValidUntilWhenMonthsConfiguredZero(): void
    {
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $this->dateTime->method('date')->willReturn('2026-07-10');
        $builder = new GenericProductBuilder(
            $this->storeManager,
            $this->currencyService,
            $this->availabilityResolver,
            $this->imageHelper,
            $this->makeConfigWithMonths(0),
            $this->dateTime,
            new OfferEnricherPool(),
            new AggregateRatingResolver(),
            new GtinValidator()
        );
        $schema = $builder->build($this->product, [], []);
        $this->assertArrayNotHasKey('priceValidUntil', $schema['offers']);
    }

    public function testBuildDoesNotHardcodeItemCondition(): void
    {
        // itemCondition comes from the configurable ItemConditionEnricher; hardcoding
        // NewCondition left used-goods stores no way to remove it.
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
        $schema = $this->builder->build($this->product, [], []);
        $this->assertArrayNotHasKey('itemCondition', $schema['offers']);
    }

    public function testBuildAvailabilityBackOrderWhenOutOfStockButBackorderable(): void
    {
        // The backorder decision itself is AvailabilityResolver's (tested there);
        // the builder passes its result through untouched.
        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::BACKORDER);

        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('https://schema.org/BackOrder', $schema['offers']['availability']);
    }

    /**
     * Build a Config mock whose priceValidUntil window is the given number of months.
     */
    private function makeConfigWithMonths(int $months): Config&MockObject
    {
        $config = $this->createMock(Config::class);
        $config->method('getPriceValidUntilMonths')->willReturn($months);
        return $config;
    }
}
