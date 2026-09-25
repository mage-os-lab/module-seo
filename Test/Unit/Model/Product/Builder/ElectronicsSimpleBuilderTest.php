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
use MageOS\Seo\Model\Product\Builder\ElectronicsSimpleBuilder;
use MageOS\Seo\Model\Product\GtinValidator;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Review\AggregateRatingResolver;
use MageOS\Seo\Service\CurrencyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * gtin13 was originally set inside this builder's simpleFields loop, which
 * bypassed GtinValidator on the attribute path (only the override path, via
 * applyOverrides, was validated). It now routes through applyGtin() like every
 * other builder; both the override and attribute paths are asserted below.
 */
class ElectronicsSimpleBuilderTest extends TestCase
{
    use OfferBuilders;

    /**
     * @var Product&MockObject
     */
    private Product&MockObject $product;

    /**
     * @var ElectronicsSimpleBuilder
     */
    private ElectronicsSimpleBuilder $builder;

    protected function setUp(): void
    {
        $storeManager    = $this->createMock(StoreManagerInterface::class);
        $store           = $this->createMock(Store::class);
        $currencyService = $this->createMock(CurrencyService::class);
        $imageHelper     = $this->createMock(ImageHelper::class);
        $seoConfig       = $this->createMock(Config::class);
        $priceInfo       = $this->createMock(PriceInfoInterface::class);
        $finalPrice      = $this->createMock(PriceInterface::class);
        $availability    = $this->createMock(AvailabilityResolver::class);

        $this->product = $this->createMock(Product::class);

        $storeManager->method('getStore')->willReturn($store);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $currencyService->method('getCurrentCurrencyCode')->willReturn('GBP');
        $currencyService->method('convertFromBase')->willReturnArgument(0);
        $finalPrice->method('getValue')->willReturn(199.00);
        $priceInfo->method('getPrice')->with('final_price')->willReturn($finalPrice);
        $this->product->method('getPriceInfo')->willReturn($priceInfo);
        $this->product->method('getName')->willReturn('Wireless Earbuds');
        $this->product->method('getSku')->willReturn('EL-01');
        $this->product->method('getId')->willReturn(31);
        $this->product->method('getProductUrl')->willReturn('https://example.com/earbuds');
        $this->product->method('getMediaGalleryImages')->willReturn(null);
        $seoConfig->method('getPriceValidUntilMonths')->willReturn(3);
        $imageHelper->method('init')->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn('');
        $availability->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);

        $this->builder = new ElectronicsSimpleBuilder(
            $storeManager,
            $imageHelper,
            $seoConfig,
            $this->offerBuilder(
                $storeManager,
                $currencyService,
                $availability,
                $seoConfig,
                $this->createMock(DateTime::class),
                new OfferEnricherPool()
            ),
            new AggregateRatingResolver(),
            new GtinValidator()
        );
    }

    public function testGetTemplateCode(): void
    {
        $this->assertSame('ElectronicsSimple', $this->builder->getTemplateCode());
    }

    public function testGetLabel(): void
    {
        $this->assertSame('Electronics & Gadgets', $this->builder->getLabel());
    }

    public function testBuildReturnsPlainProductType(): void
    {
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('Product', $schema['@type']);
    }

    public function testOfferNodeShape(): void
    {
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('Offer', $schema['offers']['@type']);
        $this->assertSame('199.00', $schema['offers']['price']);
        $this->assertSame('GBP', $schema['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $schema['offers']['availability']);
    }

    public function testMpnAndModelAreTopLevel(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => match ($key) {
                'mpn'   => 'WE-2000',
                'model' => 'X200',
                default => null,
            }
        );
        $schema = $this->builder->build($this->product, ['mpn', 'model'], []);
        $this->assertSame('WE-2000', $schema['mpn']);
        $this->assertSame('X200', $schema['model']);
    }

    public function testBrandFromManufacturerAttribute(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'manufacturer' ? 'Acme' : null
        );
        $this->product->method('getAttributeText')->willReturn(false);
        $schema = $this->builder->build($this->product, ['brand'], []);
        $this->assertSame('Brand', $schema['brand']['@type']);
        $this->assertSame('Acme', $schema['brand']['name']);
    }

    public function testValidGtinFromOverrideIsEmitted(): void
    {
        $schema = $this->builder->build($this->product, ['gtin13'], ['gtin13' => '5901234123457']);
        $this->assertSame('5901234123457', $schema['gtin13']);
    }

    public function testInvalidGtinFromOverrideIsOmitted(): void
    {
        // Override gtin is validated by applyOverrides (runs last).
        $schema = $this->builder->build($this->product, ['gtin13'], ['gtin13' => '5901234123450']);
        $this->assertArrayNotHasKey('gtin13', $schema);
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
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'barcode' ? '5901234123450' : null
        );
        $schema = $this->builder->build($this->product, ['gtin13'], []);
        $this->assertArrayNotHasKey('gtin13', $schema);
    }
}
