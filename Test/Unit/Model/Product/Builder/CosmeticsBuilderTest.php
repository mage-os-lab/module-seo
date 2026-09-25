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
use MageOS\Seo\Model\Product\Builder\CosmeticsBuilder;
use MageOS\Seo\Model\Product\GtinValidator;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Review\AggregateRatingResolver;
use MageOS\Seo\Service\CurrencyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CosmeticsBuilderTest extends TestCase
{
    /**
     * @var Product&MockObject
     */
    private Product&MockObject $product;

    /**
     * @var CosmeticsBuilder
     */
    private CosmeticsBuilder $builder;

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
        $finalPrice->method('getValue')->willReturn(22.00);
        $priceInfo->method('getPrice')->with('final_price')->willReturn($finalPrice);
        $this->product->method('getPriceInfo')->willReturn($priceInfo);
        $this->product->method('getName')->willReturn('Rose Lipstick');
        $this->product->method('getSku')->willReturn('COS-01');
        $this->product->method('getId')->willReturn(28);
        $this->product->method('getProductUrl')->willReturn('https://example.com/rose-lipstick');
        $this->product->method('getMediaGalleryImages')->willReturn(null);
        $seoConfig->method('getPriceValidUntilMonths')->willReturn(3);
        $imageHelper->method('init')->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn('');
        $availability->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);

        $this->builder = new CosmeticsBuilder(
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
        $this->assertSame('Cosmetics', $this->builder->getTemplateCode());
    }

    public function testGetLabel(): void
    {
        $this->assertSame('Beauty & Cosmetics', $this->builder->getLabel());
    }

    public function testBuildReturnsPlainProductType(): void
    {
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame('Product', $schema['@type']);
    }

    public function testShadeColorAndScentAreTopLevel(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => match ($key) {
                'color' => 'Rose Red',
                'scent' => 'Vanilla',
                default => null,
            }
        );
        $schema = $this->builder->build($this->product, ['color', 'scent'], []);
        $this->assertSame('Rose Red', $schema['color']);
        $this->assertSame('Vanilla', $schema['scent']);
    }

    public function testGenderBecomesPeopleAudienceNode(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => $key === 'gender' ? 'Female' : null
        );
        $schema = $this->builder->build($this->product, ['gender'], []);
        $this->assertSame('PeopleAudience', $schema['audience']['@type']);
        $this->assertSame('Female', $schema['audience']['suggestedGender']);
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
