<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\Builder;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\Builder\GenericProductBuilder;
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
     * @var StockRegistryInterface&MockObject
     */
    private StockRegistryInterface&MockObject $stockRegistry;

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
        $this->stockRegistry   = $this->createMock(StockRegistryInterface::class);
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
        // Note: stockItem and getData/getAttributeText are NOT stubbed here — per-test
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
            $this->stockRegistry,
            $this->imageHelper,
            $this->seoConfig,
            $this->dateTime
        );
    }

    private function makeStockItem(bool $inStock): StockItemInterface&MockObject
    {
        $item = $this->createMock(StockItemInterface::class);
        $item->method('getIsInStock')->willReturn($inStock);
        return $item;
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
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertSame('Product', $schema['@type']);
        $this->assertSame('Test Widget', $schema['name']);
        $this->assertSame('SKU-001', $schema['sku']);
        $this->assertSame('https://example.com/test-widget', $schema['url']);
    }

    public function testBuildSetsCorrectSchemaId(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertSame('https://example.com/test-widget#product', $schema['@id']);
    }

    public function testBuildIncludesOffers(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertArrayHasKey('offers', $schema);
        $this->assertSame('Offer', $schema['offers']['@type']);
        $this->assertSame('GBP', $schema['offers']['priceCurrency']);
    }

    public function testBuildFormatsPrice(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertSame('29.99', $schema['offers']['price']);
    }

    public function testBuildPriceFromVariantDataOverridesProductPrice(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $schema = $this->builder->build($this->product, [], [], ['_price' => '49.99']);
        $this->assertSame('49.99', $schema['offers']['price']);
    }

    public function testBuildAvailabilityInStockWhenProductInStock(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertSame('https://schema.org/InStock', $schema['offers']['availability']);
    }

    public function testBuildAvailabilityOutOfStockWhenProductOutOfStock(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(false));
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertSame('https://schema.org/OutOfStock', $schema['offers']['availability']);
    }

    public function testBuildAvailabilityFromVariantDataOverridesStockRegistry(): void
    {
        $schema = $this->builder->build($this->product, [], [], [
            '_availability' => 'https://schema.org/PreOrder',
        ]);
        $this->assertSame('https://schema.org/PreOrder', $schema['offers']['availability']);
    }

    public function testBuildAvailabilityFallsBackToOutOfStockOnStockRegistryException(): void
    {
        $this->stockRegistry->method('getStockItem')->willThrowException(new \Exception('DB error'));
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertSame('https://schema.org/OutOfStock', $schema['offers']['availability']);
    }

    public function testBuildOfferUrlUsesVariantCanonicalUrlWhenPresent(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $schema = $this->builder->build($this->product, [], [], [
            '_canonical_url' => 'https://example.com/test-widget?variant=red',
        ]);
        $this->assertSame('https://example.com/test-widget?variant=red', $schema['offers']['url']);
    }

    public function testBuildOfferUrlFallsBackToProductUrlWithoutVariant(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertSame('https://example.com/test-widget', $schema['offers']['url']);
    }

    public function testBuildDescriptionFromShortDescription(): void
    {
        // getShortDescription() is a magic __call() on Product; stub via __call.
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $this->product->method('__call')->willReturnCallback(
            fn (string $m) => $m === 'getShortDescription' ? 'Short desc' : null
        );
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertSame('Short desc', $schema['description']);
    }

    public function testBuildDescriptionFallsBackToFullDescription(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $this->product->method('__call')->willReturnCallback(
            fn (string $m) => match ($m) {
                'getShortDescription' => '',
                'getDescription'      => 'Full description',
                default               => null,
            }
        );
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertSame('Full description', $schema['description']);
    }

    public function testBuildDescriptionStripsHtmlTags(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $this->product->method('__call')->willReturnCallback(
            fn (string $m) => $m === 'getShortDescription' ? '<p>A <strong>great</strong> product</p>' : null
        );
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertSame('A great product', $schema['description']);
    }

    public function testBuildDescriptionDecodesHtmlEntities(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $this->product->method('__call')->willReturnCallback(
            fn (string $m) => $m === 'getShortDescription' ? 'Caf&eacute; &amp; Co' : null
        );
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertSame('Café & Co', $schema['description']);
    }

    public function testBuildDescriptionOmittedWhenEmpty(): void
    {
        // No __call stub — returns null by default → description omitted.
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertArrayNotHasKey('description', $schema);
    }

    public function testBuildDescriptionTruncatedTo5000Chars(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $longText = str_repeat('x', 6000);
        $this->product->method('__call')->willReturnCallback(
            fn (string $m) => $m === 'getShortDescription' ? $longText : null
        );
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertSame(5000, mb_strlen($schema['description']));
    }

    public function testBuildIncludesImageFromMediaGallery(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        // Use an anonymous class instead of mocking DataObject to avoid __call() stub complexity.
        $image = new class () {
            public function getUrl(): string
            {
                return 'https://example.com/media/product.jpg';
            }
        };
        $this->product->method('getMediaGalleryImages')->willReturn([$image]);
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertSame('https://example.com/media/product.jpg', $schema['image']);
    }

    public function testBuildImageIsArrayWhenMultipleImages(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
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
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertIsArray($schema['image']);
        $this->assertCount(2, $schema['image']);
    }

    public function testBuildFallsBackToImageHelperWhenGalleryEmpty(): void
    {
        // gallery returns null (default) → imageHelper fallback is triggered.
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $this->imageHelper->method('getUrl')->willReturn('https://example.com/fallback.jpg');
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertSame('https://example.com/fallback.jpg', $schema['image']);
    }

    public function testBuildImageOmittedWhenGalleryEmptyAndHelperReturnsEmpty(): void
    {
        // gallery null + imageHelper returns null → no image key in schema.
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertArrayNotHasKey('image', $schema);
    }

    public function testBuildBrandIncludedWhenFieldEnabled(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'manufacturer' ? 'Acme' : null
        );
        $this->product->method('getAttributeText')->willReturnCallback(
            fn (string $key) => $key === 'manufacturer' ? 'Acme' : false
        );
        $schema = $this->builder->build($this->product, ['brand'], [], []);
        $this->assertArrayHasKey('brand', $schema);
        $this->assertSame('Brand', $schema['brand']['@type']);
        $this->assertSame('Acme', $schema['brand']['name']);
    }

    public function testBuildBrandNotIncludedWhenFieldNotEnabled(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertArrayNotHasKey('brand', $schema);
    }

    public function testBuildBrandOverrideSetsSchemaKeyDirectly(): void
    {
        // applyOverrides() sets schema['brand'] to the plain string value from overrides.
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $schema = $this->builder->build($this->product, ['brand'], ['brand' => 'Override Brand'], []);
        $this->assertSame('Override Brand', $schema['brand']);
    }

    public function testBuildGtin13IncludedWhenFieldEnabled(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'gtin13' ? '1234567890123' : null
        );
        $this->product->method('getAttributeText')->willReturn(false);
        $schema = $this->builder->build($this->product, ['gtin13'], [], []);
        $this->assertArrayHasKey('gtin13', $schema);
    }

    public function testBuildColorFromVariantDataWhenEnabled(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $schema = $this->builder->build($this->product, ['color'], [], ['color' => 'Red']);
        $this->assertSame('Red', $schema['color']);
    }

    public function testBuildWeightFromProductAttributeWhenEnabled(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $this->product->method('getData')->willReturnCallback(
            fn (string $key) => $key === 'weight' ? '1.5kg' : null
        );
        $this->product->method('getAttributeText')->willReturn(false);
        $schema = $this->builder->build($this->product, ['weight'], [], []);
        $this->assertArrayHasKey('weight', $schema);
        $this->assertSame('1.5kg', $schema['weight']);
    }

    public function testBuildOverridesAppliedToFinalSchema(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $schema = $this->builder->build($this->product, [], ['name' => 'Overridden Name'], []);
        $this->assertSame('Overridden Name', $schema['name']);
    }

    public function testBuildOverridesDoNotApplyNullValues(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $schema = $this->builder->build($this->product, [], ['name' => null], []);
        $this->assertSame('Test Widget', $schema['name']);
    }

    public function testBuildPriceValidUntilFormatIsDate(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $schema['offers']['priceValidUntil']);
    }

    public function testBuildItemConditionIsNewCondition(): void
    {
        $this->stockRegistry->method('getStockItem')->willReturn($this->makeStockItem(true));
        $schema = $this->builder->build($this->product, [], [], []);
        $this->assertSame('https://schema.org/NewCondition', $schema['offers']['itemCondition']);
    }
}
