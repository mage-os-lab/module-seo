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
 * Verifies the Offer enrichment, AggregateRating and AggregateOffer behaviour added to
 * AbstractBuilder::buildBase(), exercised through the concrete GenericProductBuilder.
 */
class AbstractBuilderEnrichmentTest extends TestCase
{
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
    }

    private function useSimplePrice(): void
    {
        $finalPrice = $this->createMock(PriceInterface::class);
        $finalPrice->method('getValue')->willReturn(29.99);
        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->with('final_price')->willReturn($finalPrice);
        $this->product->method('getPriceInfo')->willReturn($priceInfo);
    }

    private function useRangePrice(float $low, float $high): void
    {
        // A range-aware price object. PriceInfoInterface::getPrice() has no return type, so a plain
        // anonymous object with the methods AbstractBuilder::resolvePriceRange() probes is sufficient.
        $rangePrice = new class ($low, $this->makeAmount($low), $this->makeAmount($high)) {
            public function __construct(
                private readonly float $value,
                private readonly object $min,
                private readonly object $max
            ) {
            }

            public function getValue(): float
            {
                return $this->value;
            }

            public function getMinimalPrice(): object
            {
                return $this->min;
            }

            public function getMaximalPrice(): object
            {
                return $this->max;
            }
        };

        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->with('final_price')->willReturn($rangePrice);
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
            $currencyService,
            $this->availabilityResolver,
            $imageHelper,
            $this->seoConfig,
            $this->createMock(DateTime::class),
            new OfferEnricherPool([$enricher]),
            new AggregateRatingResolver([$ratingProvider]),
            new GtinValidator()
        );
    }

    public function testOfferEnrichmentIsMergedIntoOffers(): void
    {
        $this->useSimplePrice();
        $this->seoConfig->method('isAggregateRatingEnabled')->willReturn(false);
        $builder = $this->makeBuilder(['shippingDetails' => ['@type' => 'OfferShippingDetails']]);
        $schema  = $builder->build($this->product, [], []);
        $this->assertArrayHasKey('shippingDetails', $schema['offers']);
    }

    public function testEnricherCanOverrideItemCondition(): void
    {
        $this->useSimplePrice();
        $this->seoConfig->method('isAggregateRatingEnabled')->willReturn(false);
        $builder = $this->makeBuilder(['itemCondition' => 'https://schema.org/UsedCondition']);
        $schema  = $builder->build($this->product, [], []);
        $this->assertSame('https://schema.org/UsedCondition', $schema['offers']['itemCondition']);
    }

    public function testAggregateRatingAddedWhenEnabledAndAvailable(): void
    {
        $this->useSimplePrice();
        $this->seoConfig->method('isAggregateRatingEnabled')->willReturn(true);
        $builder = $this->makeBuilder([], ['ratingValue' => '4.5', 'reviewCount' => '17']);
        $schema  = $builder->build($this->product, [], []);
        $this->assertArrayHasKey('aggregateRating', $schema);
        $this->assertSame('AggregateRating', $schema['aggregateRating']['@type']);
        $this->assertSame('4.5', $schema['aggregateRating']['ratingValue']);
    }

    public function testAggregateRatingNotAddedWhenDisabled(): void
    {
        $this->useSimplePrice();
        $this->seoConfig->method('isAggregateRatingEnabled')->willReturn(false);
        $builder = $this->makeBuilder([], ['ratingValue' => '4.5', 'reviewCount' => '17']);
        $schema  = $builder->build($this->product, [], []);
        $this->assertArrayNotHasKey('aggregateRating', $schema);
    }

    public function testAggregateRatingNotAddedWhenResolverReturnsNull(): void
    {
        $this->useSimplePrice();
        $this->seoConfig->method('isAggregateRatingEnabled')->willReturn(true);
        $builder = $this->makeBuilder([], null);
        $schema  = $builder->build($this->product, [], []);
        $this->assertArrayNotHasKey('aggregateRating', $schema);
    }

    public function testSimpleProductKeepsSingleOffer(): void
    {
        $this->useSimplePrice();
        $this->seoConfig->method('isAggregateRatingEnabled')->willReturn(false);
        $this->product->method('getTypeId')->willReturn('simple');
        $schema = $this->makeBuilder()->build($this->product, [], []);
        $this->assertSame('Offer', $schema['offers']['@type']);
        $this->assertArrayHasKey('price', $schema['offers']);
    }

    public function testConfigurableProductProducesAggregateOffer(): void
    {
        $this->useRangePrice(10.0, 50.0);
        $this->seoConfig->method('isAggregateRatingEnabled')->willReturn(false);
        $this->product->method('getTypeId')->willReturn('configurable');

        $schema = $this->makeBuilder()->build($this->product, [], []);

        $this->assertSame('AggregateOffer', $schema['offers']['@type']);
        $this->assertSame('10.00', $schema['offers']['lowPrice']);
        $this->assertSame('50.00', $schema['offers']['highPrice']);
        $this->assertArrayNotHasKey('price', $schema['offers']);
    }

    private function makeAmount(float $value): object
    {
        return new class ($value) {
            public function __construct(private readonly float $value)
            {
            }

            public function getValue(): float
            {
                return $this->value;
            }
        };
    }
}
