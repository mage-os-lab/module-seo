<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product;

use Magento\Catalog\Model\Product;
use Magento\ConfigurableProduct\Pricing\Price\ConfigurableOptionsProviderInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\OfferEnricherInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use MageOS\Seo\Model\Product\OfferBuilder;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Product\Variant\ChildProducts;
use MageOS\Seo\Service\CurrencyService;
use PHPUnit\Framework\TestCase;

/**
 * The one offer builder: an Offer for any product at the URL given, and an AggregateOffer for a
 * configurable whose sellable children differ in price, read from the children themselves.
 */
class OfferBuilderTest extends TestCase
{
    private const URL = 'https://example.com/tee.html';

    public function testAnOfferForTheProductAtTheUrlGiven(): void
    {
        $offer = $this->offerBuilder()->build($this->product('simple', 12.5), self::URL . '?size=2');

        $this->assertSame(
            [
                '@type'           => 'Offer',
                'url'             => self::URL . '?size=2',
                'price'           => '12.50',
                'priceCurrency'   => 'GBP',
                'availability'    => AvailabilityResolver::IN_STOCK,
                'priceValidUntil' => '2026-12-25',
            ],
            $offer
        );
    }

    public function testEnrichersAddToTheOfferAndCanOverrideIt(): void
    {
        $enricher = $this->createStub(OfferEnricherInterface::class);
        $enricher->method('enrich')->willReturn([
            'itemCondition'   => 'https://schema.org/UsedCondition',
            'shippingDetails' => ['@type' => 'OfferShippingDetails'],
        ]);
        $enricher->method('getSortOrder')->willReturn(100);

        $offer = $this->offerBuilder(enrichers: [$enricher])->build($this->product('simple', 12.5), self::URL);

        $this->assertSame('https://schema.org/UsedCondition', $offer['itemCondition']);
        $this->assertSame(['@type' => 'OfferShippingDetails'], $offer['shippingDetails']);
    }

    public function testAnActiveSpecialPriceEndDateIsWhenThePriceIsValidUntil(): void
    {
        $offer = $this->offerBuilder()->build($this->product('simple', 12.5, '2027-01-31 00:00:00'), self::URL);

        $this->assertSame('2027-01-31', $offer['priceValidUntil']);
    }

    public function testZeroMonthsLeavesPriceValidUntilOut(): void
    {
        $offer = $this->offerBuilder(months: 0)->build($this->product('simple', 12.5), self::URL);

        $this->assertArrayNotHasKey('priceValidUntil', $offer);
    }

    public function testAConfigurableWhoseChildrenDifferInPriceHasAnAggregateOffer(): void
    {
        $children = [$this->product('simple', 30), $this->product('simple', 10), $this->product('simple', 50)];

        $offer = $this->offerBuilder($children)->build($this->product('configurable', 10), self::URL);

        $this->assertSame(
            [
                '@type'           => 'AggregateOffer',
                'url'             => self::URL,
                'priceCurrency'   => 'GBP',
                'availability'    => AvailabilityResolver::IN_STOCK,
                'priceValidUntil' => '2026-12-25',
                'lowPrice'        => '10.00',
                'highPrice'       => '50.00',
            ],
            $offer
        );
    }

    public function testAConfigurableWhoseChildrenShareOnePriceKeepsAnOffer(): void
    {
        $children = [$this->product('simple', 15), $this->product('simple', 15)];

        $offer = $this->offerBuilder($children)->build($this->product('configurable', 15), self::URL);

        $this->assertSame('Offer', $offer['@type']);
        $this->assertSame('15.00', $offer['price']);
    }

    public function testAConfigurableWithOneChildKeepsAnOffer(): void
    {
        $offer = $this->offerBuilder([$this->product('simple', 15)])
            ->build($this->product('configurable', 15), self::URL);

        $this->assertSame('Offer', $offer['@type']);
    }

    public function testTheRangeIsInTheDisplayCurrency(): void
    {
        $children = [$this->product('simple', 10), $this->product('simple', 50)];

        $offer = $this->offerBuilder($children, rate: 2.0)->build($this->product('configurable', 10), self::URL);

        $this->assertSame('20.00', $offer['lowPrice']);
        $this->assertSame('100.00', $offer['highPrice']);
    }

    public function testChildrenAreLookedUpForAConfigurableOnly(): void
    {
        $optionsProvider = $this->createMock(ConfigurableOptionsProviderInterface::class);
        $optionsProvider->expects($this->never())->method('getProducts');

        $this->offerBuilder(optionsProvider: $optionsProvider)->build($this->product('simple', 12.5), self::URL);
    }

    /**
     * @param Product[] $children The configurable's sellable children
     * @param OfferEnricherInterface[] $enrichers
     * @param int $months priceValidUntil months
     * @param float $rate Display currency per unit of base currency
     * @param ConfigurableOptionsProviderInterface|null $optionsProvider
     * @return OfferBuilder
     */
    private function offerBuilder(
        array $children = [],
        array $enrichers = [],
        int $months = 3,
        float $rate = 1.0,
        ?ConfigurableOptionsProviderInterface $optionsProvider = null
    ): OfferBuilder {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $currencyService = $this->createStub(CurrencyService::class);
        $currencyService->method('getCurrentCurrencyCode')->willReturn('GBP');
        $currencyService->method('convertFromBase')->willReturnCallback(
            static fn (float $amount): float => $amount * $rate
        );

        $availability = $this->createStub(AvailabilityResolver::class);
        $availability->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);

        $seoConfig = $this->createStub(Config::class);
        $seoConfig->method('getPriceValidUntilMonths')->willReturn($months);

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('date')->willReturnCallback(
            static fn (string $format, ?string $input = null): string => $input === null ? '2026-09-25' : '2026-12-25'
        );

        if ($optionsProvider === null) {
            $optionsProvider = $this->createStub(ConfigurableOptionsProviderInterface::class);
            $optionsProvider->method('getProducts')->willReturn($children);
        }

        return new OfferBuilder(
            $storeManager,
            $currencyService,
            $availability,
            $seoConfig,
            $dateTime,
            new OfferEnricherPool($enrichers),
            new ChildProducts($optionsProvider)
        );
    }

    /**
     * @param string $typeId
     * @param float $finalPrice
     * @param string|null $specialToDate
     * @return Product
     */
    private function product(string $typeId, float $finalPrice, ?string $specialToDate = null): Product
    {
        $price = $this->createStub(PriceInterface::class);
        $price->method('getValue')->willReturn($finalPrice);
        $priceInfo = $this->createStub(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willReturn($price);

        $product = $this->createStub(Product::class);
        $product->method('getTypeId')->willReturn($typeId);
        $product->method('getPriceInfo')->willReturn($priceInfo);
        $product->method('getData')->willReturnCallback(
            static fn (string $key = '') => $key === 'special_to_date' ? $specialToDate : null
        );

        return $product;
    }
}
