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
use MageOS\Seo\Api\AggregateRatingProviderInterface;
use MageOS\Seo\Api\OfferEnricherInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use MageOS\Seo\Model\Product\Builder\GenericProductBuilder;
use MageOS\Seo\Model\Product\GtinValidator;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Review\AggregateRatingResolver;
use MageOS\Seo\Service\CurrencyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What AbstractBuilder::buildBase() adds around the template's own fields — the offer from the
 * offer builder and the AggregateRating — exercised through the concrete GenericProductBuilder.
 * The offer itself is covered by OfferBuilderTest.
 */
class AbstractBuilderEnrichmentTest extends TestCase
{
    use OfferBuilders;

    /**
     * @var StoreManagerInterface&MockObject
     */
    private StoreManagerInterface&MockObject $storeManager;

    /**
     * @var AvailabilityResolver&MockObject
     */
    private AvailabilityResolver&MockObject $availabilityResolver;

    /**
     * @var Config&MockObject
     */
    private Config&MockObject $seoConfig;

    /**
     * @var Product&MockObject
     */
    private Product&MockObject $product;

    protected function setUp(): void
    {
        $this->storeManager         = $this->createMock(StoreManagerInterface::class);
        $store                      = $this->createMock(Store::class);
        $this->availabilityResolver = $this->createMock(AvailabilityResolver::class);
        $this->seoConfig            = $this->createMock(Config::class);
        $this->product              = $this->createMock(Product::class);

        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $this->storeManager->method('getStore')->willReturn($store);

        $this->availabilityResolver->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);

        $this->product->method('getName')->willReturn('Test Widget');
        $this->product->method('getSku')->willReturn('SKU-001');
        $this->product->method('getId')->willReturn(42);
        $this->product->method('getProductUrl')->willReturn('https://example.com/test-widget');
        $this->seoConfig->method('getPriceValidUntilMonths')->willReturn(12);

        $finalPrice = $this->createMock(PriceInterface::class);
        $finalPrice->method('getValue')->willReturn(29.99);
        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->with('final_price')->willReturn($finalPrice);
        $this->product->method('getPriceInfo')->willReturn($priceInfo);
    }

    /**
     * @param array<string, mixed> $offerFragment
     * @param array<string, string>|null $rating
     */
    private function makeBuilder(array $offerFragment = [], ?array $rating = null): GenericProductBuilder
    {
        $enricher = $this->createMock(OfferEnricherInterface::class);
        $enricher->method('enrich')->willReturn($offerFragment);
        $enricher->method('getSortOrder')->willReturn(100);

        $ratingProvider = $this->createMock(AggregateRatingProviderInterface::class);
        $ratingProvider->method('getRating')->willReturn($rating);
        $ratingProvider->method('getPriority')->willReturn(100);

        $currencyService = $this->createMock(CurrencyService::class);
        $currencyService->method('getCurrentCurrencyCode')->willReturn('GBP');
        $currencyService->method('convertFromBase')->willReturnArgument(0);

        $imageHelper = $this->createMock(ImageHelper::class);
        $imageHelper->method('init')->willReturnSelf();

        return new GenericProductBuilder(
            $this->storeManager,
            $imageHelper,
            $this->seoConfig,
            $this->offerBuilder(
                $this->storeManager,
                $currencyService,
                $this->availabilityResolver,
                $this->seoConfig,
                $this->createMock(DateTime::class),
                new OfferEnricherPool([$enricher])
            ),
            new AggregateRatingResolver([$ratingProvider]),
            new GtinValidator()
        );
    }

    public function testTheNodeCarriesTheOfferTheOfferBuilderMade(): void
    {
        $this->seoConfig->method('isAggregateRatingEnabled')->willReturn(false);
        $builder = $this->makeBuilder(['shippingDetails' => ['@type' => 'OfferShippingDetails']]);
        $schema  = $builder->build($this->product, [], []);

        $this->assertSame('Offer', $schema['offers']['@type']);
        $this->assertSame('https://example.com/test-widget', $schema['offers']['url']);
        $this->assertSame(['@type' => 'OfferShippingDetails'], $schema['offers']['shippingDetails']);
    }

    public function testAggregateRatingAddedWhenEnabledAndAvailable(): void
    {
        $this->seoConfig->method('isAggregateRatingEnabled')->willReturn(true);
        $builder = $this->makeBuilder([], ['ratingValue' => '4.5', 'reviewCount' => '17']);
        $schema  = $builder->build($this->product, [], []);
        $this->assertArrayHasKey('aggregateRating', $schema);
        $this->assertSame('AggregateRating', $schema['aggregateRating']['@type']);
        $this->assertSame('4.5', $schema['aggregateRating']['ratingValue']);
    }

    public function testAggregateRatingNotAddedWhenDisabled(): void
    {
        $this->seoConfig->method('isAggregateRatingEnabled')->willReturn(false);
        $builder = $this->makeBuilder([], ['ratingValue' => '4.5', 'reviewCount' => '17']);
        $schema  = $builder->build($this->product, [], []);
        $this->assertArrayNotHasKey('aggregateRating', $schema);
    }

    public function testAggregateRatingNotAddedWhenResolverReturnsNull(): void
    {
        $this->seoConfig->method('isAggregateRatingEnabled')->willReturn(true);
        $builder = $this->makeBuilder([], null);
        $schema  = $builder->build($this->product, [], []);
        $this->assertArrayNotHasKey('aggregateRating', $schema);
    }
}
