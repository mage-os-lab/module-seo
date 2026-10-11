<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\ConfigurableProduct\Pricing\Price\ConfigurableOptionsProviderInterface;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\OfferEnricherInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use MageOS\Seo\Model\Product\FinalPrice;
use MageOS\Seo\Model\Product\OfferBuilder;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Product\OfferSummary;
use MageOS\Seo\Test\Unit\Model\Price\SchemaPrices;
use MageOS\Seo\Test\Unit\Model\Product\Variant\ChildProductsOverProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The one offer builder: an Offer for any product at the URL given, and an AggregateOffer for a
 * configurable whose sellable children differ in price, read from the children themselves.
 */
class OfferBuilderTest extends TestCase
{
    use SchemaPrices;
    use ChildProductsOverProvider;

    private const URL = 'https://example.com/tee.html';

    public function testAnOfferForTheProductAtTheUrlGiven(): void
    {
        $offer = $this->offerBuilder()->build($this->product('simple', 12.5), self::URL . '?size=2');

        $this->assertSame(
            [
                '@type'         => 'Offer',
                'url'           => self::URL . '?size=2',
                'price'         => '12.50',
                'priceCurrency' => 'GBP',
                'availability'  => AvailabilityResolver::IN_STOCK,
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

    public function testAnActiveSpecialThatIsThePriceGivesItsEndDate(): void
    {
        $offer = $this->offerBuilder()
            ->build($this->product('simple', 9.99, '2027-01-31 00:00:00', 9.99), self::URL);

        $this->assertSame('2027-01-31', $offer['priceValidUntil']);
    }

    public function testASpecialEndingTodayStillGivesTheDate(): void
    {
        // Core counts the to-date as active for the whole of that day.
        $offer = $this->offerBuilder()
            ->build($this->product('simple', 9.99, '2026-09-25 00:00:00', 9.99), self::URL);

        $this->assertSame('2026-09-25', $offer['priceValidUntil']);
    }

    public function testWithoutASpecialPriceThereIsNoPriceValidUntil(): void
    {
        $offer = $this->offerBuilder()->build($this->product('simple', 12.5), self::URL);

        $this->assertArrayNotHasKey('priceValidUntil', $offer);
    }

    public function testAnActiveSpecialWithoutAnEndDateGivesNoDate(): void
    {
        $offer = $this->offerBuilder()->build($this->product('simple', 9.99, null, 9.99), self::URL);

        $this->assertArrayNotHasKey('priceValidUntil', $offer);
    }

    public function testAFutureSpecialGivesNoDate(): void
    {
        // Issue #31: a November special is not October's price, so its end date is not October's.
        // Core's SpecialPrice answers false for a special outside its dates.
        $offer = $this->offerBuilder()
            ->build($this->product('simple', 12.5, '2026-11-30 00:00:00', false), self::URL);

        $this->assertArrayNotHasKey('priceValidUntil', $offer);
    }

    public function testAnExpiredSpecialGivesNoDate(): void
    {
        $offer = $this->offerBuilder()
            ->build($this->product('simple', 12.5, '2026-09-24 00:00:00', false), self::URL);

        $this->assertArrayNotHasKey('priceValidUntil', $offer);
    }

    public function testASpecialBeatenByALowerPriceGivesNoDate(): void
    {
        // Issue #31: a catalog rule at 8.00 is the price published, and the special's end date is
        // not that price's.
        $offer = $this->offerBuilder()
            ->build($this->product('simple', 8.0, '2027-01-31 00:00:00', 9.99), self::URL);

        $this->assertSame('8.00', $offer['price']);
        $this->assertArrayNotHasKey('priceValidUntil', $offer);
    }

    public function testAConfigurableWhoseChildrenDifferInPriceHasAnAggregateOffer(): void
    {
        $children = [$this->product('simple', 30), $this->product('simple', 10), $this->product('simple', 50)];

        $offer = $this->offerBuilder($children)->build($this->product('configurable', 10), self::URL);

        $this->assertSame(
            [
                '@type'         => 'AggregateOffer',
                'url'           => self::URL,
                'priceCurrency' => 'GBP',
                'availability'  => AvailabilityResolver::IN_STOCK,
                'lowPrice'      => '10.00',
                'highPrice'     => '50.00',
            ],
            $offer
        );
    }

    public function testAConfigurableIsAsAvailableAsItsBestChild(): void
    {
        // On the product page the children are loaded already: a configurable whose children can
        // only be backordered is on backorder, not in stock as its own index row would say.
        $children     = [$this->product('simple', 10), $this->product('simple', 12)];
        $availability = $this->createMock(AvailabilityResolver::class);
        $availability->expects($this->once())->method('best')->with($children)
            ->willReturn(AvailabilityResolver::BACKORDER);
        $availability->expects($this->never())->method('resolve');

        $offer = $this->offerBuilder($children, availability: $availability)
            ->build($this->product('configurable', 10), self::URL);

        $this->assertSame(AvailabilityResolver::BACKORDER, $offer['availability']);
    }

    public function testAProductWithoutChildrenIsAsAvailableAsItself(): void
    {
        $availability = $this->createMock(AvailabilityResolver::class);
        $availability->expects($this->once())->method('resolve')->willReturn(AvailabilityResolver::BACKORDER);
        $availability->expects($this->never())->method('best');

        $offer = $this->offerBuilder(availability: $availability)->build($this->product('simple', 10), self::URL);

        $this->assertSame(AvailabilityResolver::BACKORDER, $offer['availability']);
    }

    /**
     * Decision D8a: a stock lookup that failed leaves availability out, rather than claiming
     * OutOfStock, which nothing backs.
     */
    public function testAnUnknownAvailabilityIsLeftOut(): void
    {
        $availability = $this->createStub(AvailabilityResolver::class);
        $availability->method('resolve')->willReturn(null);

        $offer = $this->offerBuilder(availability: $availability)->build($this->product('simple', 10), self::URL);

        $this->assertArrayNotHasKey('availability', $offer);
        $this->assertSame('10.00', $offer['price']);
    }

    /**
     * Decision D8a, for a configurable whose children's stock could not be read.
     */
    public function testAConfigurableOfUnknownAvailabilityLeavesItOut(): void
    {
        $children     = [$this->product('simple', 10), $this->product('simple', 12)];
        $availability = $this->createStub(AvailabilityResolver::class);
        $availability->method('best')->willReturn(null);

        $offer = $this->offerBuilder($children, availability: $availability)
            ->build($this->product('configurable', 10), self::URL);

        $this->assertArrayNotHasKey('availability', $offer);
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

    /**
     * PriceInfo amounts are already in the display currency (core RegularPrice /
     * SpecialPrice / CatalogRulePrice convert with PriceCurrency), so a base→display rate
     * must not be applied to them again.
     */
    public function testTheRangeIsNotConvertedASecondTime(): void
    {
        $children = [$this->product('simple', 10), $this->product('simple', 50)];

        $offer = $this->offerBuilder($children, rate: 2.0)->build($this->product('configurable', 10), self::URL);

        $this->assertSame('10.00', $offer['lowPrice']);
        $this->assertSame('50.00', $offer['highPrice']);
    }

    public function testThePriceIsNotConvertedASecondTime(): void
    {
        $offer = $this->offerBuilder(rate: 2.0)->build($this->product('simple', 12.5), self::URL);

        $this->assertSame('12.50', $offer['price']);
        $this->assertSame('GBP', $offer['priceCurrency']);
    }

    /**
     * With no sellable child, core prices a configurable 0, which Google reads as free. Google also
     * requires a price on every Offer, so there is no offer at all: the caller leaves `offers` out.
     */
    public function testAConfigurableNothingCanPriceHasNoOffer(): void
    {
        $offer = $this->offerBuilder([])->build($this->product('configurable', 0.0), self::URL);

        $this->assertSame([], $offer);
    }

    public function testASimpleProductPricedZeroHasNoOffer(): void
    {
        // Issue #41: unless Publish a Price of Zero is on (Model\Product\FinalPrice), a price of 0
        // is one hidden from the visitor, not a free product.
        $this->assertSame([], $this->offerBuilder()->build($this->product('simple', 0.0), self::URL));
    }

    public function testAChildWhosePriceCannotBeReadIsLeftOutOfTheRange(): void
    {
        // Built on its own: a stubbed method keeps the first behaviour it is given.
        $unreadable = $this->createStub(Product::class);
        $unreadable->method('getPriceInfo')->willThrowException(new \RuntimeException('No price index'));
        $children = [$this->product('simple', 30), $unreadable, $this->product('simple', 10)];

        $offer = $this->offerBuilder($children)->build($this->product('configurable', 10), self::URL);

        $this->assertSame('10.00', $offer['lowPrice']);
        $this->assertSame('30.00', $offer['highPrice']);
    }

    public function testChildrenAreLookedUpForAConfigurableOnly(): void
    {
        $optionsProvider = $this->createMock(ConfigurableOptionsProviderInterface::class);
        $optionsProvider->expects($this->never())->method('getProducts');

        $this->offerBuilder(optionsProvider: $optionsProvider)->build($this->product('simple', 12.5), self::URL);
    }

    /**
     * The offer builder.
     *
     * @param Product[] $children The configurable's sellable children
     * @param OfferEnricherInterface[] $enrichers
     * @param float $rate Display currency per unit of base currency
     * @param ConfigurableOptionsProviderInterface|null $optionsProvider
     * @param AvailabilityResolver|null $availability
     * @return OfferBuilder
     */
    private function offerBuilder(
        array $children = [],
        array $enrichers = [],
        float $rate = 1.0,
        ?ConfigurableOptionsProviderInterface $optionsProvider = null,
        ?AvailabilityResolver $availability = null
    ): OfferBuilder {
        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $schemaPrice = $this->schemaPrice('GBP', $rate);

        if ($availability === null) {
            $availability = $this->createStub(AvailabilityResolver::class);
            $availability->method('resolve')->willReturn(AvailabilityResolver::IN_STOCK);
            $availability->method('best')->willReturn(AvailabilityResolver::IN_STOCK);
        }

        if ($optionsProvider === null) {
            $optionsProvider = $this->createStub(ConfigurableOptionsProviderInterface::class);
            $optionsProvider->method('getProducts')->willReturn($children);
        }

        return new OfferBuilder(
            $storeManager,
            $schemaPrice,
            new OfferEnricherPool($enrichers),
            new OfferSummary(
                new FinalPrice($this->createStub(LoggerInterface::class), $this->createStub(Config::class)),
                $this->childProductsOver($optionsProvider),
                $availability
            )
        );
    }

    /**
     * A product as loaded: its final price, its special price as core's SpecialPrice answers (false
     * when there is none in effect today), and its special_to_date as the database has it.
     *
     * @param string $typeId
     * @param float $finalPrice
     * @param string|null $specialToDate
     * @param float|false $special
     * @return Product
     */
    private function product(
        string $typeId,
        float $finalPrice,
        ?string $specialToDate = null,
        float|false $special = false
    ): Product {
        $final = $this->createStub(PriceInterface::class);
        $final->method('getValue')->willReturn($finalPrice);
        $displayed = $this->createStub(AmountInterface::class);
        $displayed->method('getValue')->willReturn($finalPrice);
        $final->method('getAmount')->willReturn($displayed);
        $specialPrice = $this->createStub(PriceInterface::class);
        $specialPrice->method('getValue')->willReturn($special);
        $priceInfo = $this->createStub(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willReturnCallback(
            static fn (string $code): PriceInterface => $code === 'special_price' ? $specialPrice : $final
        );

        $type = $this->createStub(AbstractType::class);
        $type->method('isComposite')->willReturn($typeId === 'configurable');

        // A special with an end date is stored: the attribute is there whether or not it is in effect.
        $data    = ['special_to_date' => $specialToDate, 'special_price' => $specialToDate === null ? null : '9.9900'];
        $product = $this->createStub(Product::class);
        $product->method('getTypeId')->willReturn($typeId);
        $product->method('getTypeInstance')->willReturn($type);
        $product->method('getPriceInfo')->willReturn($priceInfo);
        $product->method('getData')->willReturnCallback(
            static fn (string $key = '') => $data[$key] ?? null
        );

        return $product;
    }
}
