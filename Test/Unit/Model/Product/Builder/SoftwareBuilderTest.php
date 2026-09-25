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
use MageOS\Seo\Model\Product\Builder\SoftwareBuilder;
use MageOS\Seo\Model\Product\GtinValidator;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Review\AggregateRatingResolver;
use MageOS\Seo\Service\CurrencyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SoftwareBuilderTest extends TestCase
{
    /**
     * @var Product&MockObject
     */
    private Product&MockObject $product;

    /**
     * @var SoftwareBuilder
     */
    private SoftwareBuilder $builder;

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
        $finalPrice->method('getValue')->willReturn(49.00);
        $priceInfo->method('getPrice')->with('final_price')->willReturn($finalPrice);
        $this->product->method('getPriceInfo')->willReturn($priceInfo);
        $this->product->method('getName')->willReturn('Photo Editor Pro');
        $this->product->method('getSku')->willReturn('SW-01');
        $this->product->method('getId')->willReturn(22);
        $this->product->method('getProductUrl')->willReturn('https://example.com/photo-editor');
        $this->product->method('getMediaGalleryImages')->willReturn(null);
        $seoConfig->method('getPriceValidUntilMonths')->willReturn(3);
        $imageHelper->method('init')->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn('');
        $availability->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);

        $this->builder = new SoftwareBuilder(
            $storeManager,
            $currencyService,
            $availability,
            $imageHelper,
            $seoConfig,
            $this->createMock(DateTime::class),
            new OfferEnricherPool(),
            new AggregateRatingResolver(),
            new GtinValidator()
        );
    }

    public function testGetTemplateCode(): void
    {
        $this->assertSame('Software', $this->builder->getTemplateCode());
    }

    public function testGetLabel(): void
    {
        $this->assertSame('Software / Digital Download', $this->builder->getLabel());
    }

    public function testBuildReturnsProductAndSoftwareApplicationMultiType(): void
    {
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame(['Product', 'SoftwareApplication'], $schema['@type']);
    }

    public function testOperatingSystemVersionAndCategoryAreTopLevel(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => match ($key) {
                'os'                   => 'Windows',
                'software_version'     => '2.1',
                'application_category' => 'MultimediaApplication',
                default                => null,
            }
        );
        $schema = $this->builder->build(
            $this->product,
            ['operatingSystem', 'softwareVersion', 'applicationCategory'],
            []
        );
        $this->assertSame('Windows', $schema['operatingSystem']);
        $this->assertSame('2.1', $schema['softwareVersion']);
        $this->assertSame('MultimediaApplication', $schema['applicationCategory']);
    }

    public function testValidGtinFromBarcodeIsEmitted(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'barcode' ? '5901234123457' : null
        );
        $schema = $this->builder->build($this->product, ['gtin13'], []);
        $this->assertSame('5901234123457', $schema['gtin13']);
    }

    public function testInvalidGtinFromBarcodeIsOmitted(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'barcode' ? '5901234123450' : null
        );
        $schema = $this->builder->build($this->product, ['gtin13'], []);
        $this->assertArrayNotHasKey('gtin13', $schema);
    }
}
