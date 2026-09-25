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
use MageOS\Seo\Model\Product\Builder\ArtAndCraftBuilder;
use MageOS\Seo\Model\Product\GtinValidator;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Review\AggregateRatingResolver;
use MageOS\Seo\Service\CurrencyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ArtAndCraftBuilderTest extends TestCase
{
    /**
     * @var Product&MockObject
     */
    private Product&MockObject $product;

    /**
     * @var ArtAndCraftBuilder
     */
    private ArtAndCraftBuilder $builder;

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
        $finalPrice->method('getValue')->willReturn(85.00);
        $priceInfo->method('getPrice')->with('final_price')->willReturn($finalPrice);
        $this->product->method('getPriceInfo')->willReturn($priceInfo);
        $this->product->method('getName')->willReturn('Sunset Painting');
        $this->product->method('getSku')->willReturn('ART-01');
        $this->product->method('getId')->willReturn(23);
        $this->product->method('getProductUrl')->willReturn('https://example.com/sunset');
        $this->product->method('getMediaGalleryImages')->willReturn(null);
        $seoConfig->method('getPriceValidUntilMonths')->willReturn(3);
        $imageHelper->method('init')->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn('');
        $availability->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);

        $this->builder = new ArtAndCraftBuilder(
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
        $this->assertSame('ArtAndCraft', $this->builder->getTemplateCode());
    }

    public function testGetLabel(): void
    {
        $this->assertSame('Art & Craft / Handmade', $this->builder->getLabel());
    }

    public function testBuildReturnsProductAndVisualArtworkMultiType(): void
    {
        $schema = $this->builder->build($this->product, [], []);
        $this->assertSame(['Product', 'VisualArtwork'], $schema['@type']);
    }

    public function testArtMediumAndSurfaceAreTopLevel(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => match ($key) {
                'art_medium'      => 'Oil',
                'artwork_surface' => 'Canvas',
                default           => null,
            }
        );
        $schema = $this->builder->build($this->product, ['artMedium', 'artworkSurface'], []);
        $this->assertSame('Oil', $schema['artMedium']);
        $this->assertSame('Canvas', $schema['artworkSurface']);
    }

    public function testDimensionsAreTopLevel(): void
    {
        $this->product->method('getData')->willReturnCallback(
            static fn (string $key) => match ($key) {
                'width'  => '40cm',
                'height' => '60cm',
                default  => null,
            }
        );
        $schema = $this->builder->build($this->product, ['width', 'height'], []);
        $this->assertSame('40cm', $schema['width']);
        $this->assertSame('60cm', $schema['height']);
    }

    public function testCreatorFromOverrideIsApplied(): void
    {
        $schema = $this->builder->build($this->product, ['creator'], ['creator' => 'Local Artist']);
        $this->assertSame('Local Artist', $schema['creator']);
    }
}
