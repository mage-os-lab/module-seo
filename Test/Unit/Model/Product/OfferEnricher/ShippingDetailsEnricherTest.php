<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\OfferEnricher;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Directory\Model\Currency;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use MageOS\Seo\Model\Log\OccasionalLog;
use MageOS\Seo\Model\Price\SchemaPrice;
use MageOS\Seo\Model\Product\OfferEnricher\CountryList;
use MageOS\Seo\Model\Product\OfferEnricher\ShippingDetailsEnricher;
use MageOS\Seo\Model\Product\OfferEnricher\ShippingRules;
use MageOS\Seo\Test\Unit\Model\Price\SchemaPrices;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ShippingDetailsEnricherTest extends TestCase
{
    use SchemaPrices;

    /**
     * @var ScopeConfigInterface&Stub
     */
    private ScopeConfigInterface&Stub $scopeConfig;

    /**
     * @var SchemaPrice
     */
    private SchemaPrice $schemaPrice;

    /**
     * @var ProductInterface&Stub
     */
    private ProductInterface&Stub $product;

    /**
     * @var ShippingDetailsEnricher
     */
    private ShippingDetailsEnricher $enricher;

    /**
     * What the enricher logged, as [level, message, context].
     *
     * @var array<int,array{string,string,mixed[]}>
     */
    private array $logged = [];

    /**
     * What a remembering cache holds, by key.
     *
     * @var array<string,string>
     */
    private array $cached = [];

    protected function setUp(): void
    {
        $this->scopeConfig     = $this->createStub(ScopeConfigInterface::class);
        $this->schemaPrice     = $this->schemaPrice();
        $this->product         = $this->createStub(ProductInterface::class);
        $this->logged          = [];
        $this->enricher        = $this->enricher($this->priceCurrency());
    }

    /**
     * The enricher, converting the rate with the given price currency.
     *
     * @param PriceCurrencyInterface $priceCurrency
     * @param bool $rememberLogged Whether the log's cache remembers what was logged, as a real one does
     * @return ShippingDetailsEnricher
     */
    private function enricher(
        PriceCurrencyInterface $priceCurrency,
        bool $rememberLogged = false
    ): ShippingDetailsEnricher {
        $logger = $this->createStub(LoggerInterface::class);
        foreach (['warning', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string $message, array $context = []) use ($level): void {
                    $this->logged[] = [$level, $message, $context];
                }
            );
        }
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            fn (string $key): string|false => $rememberLogged ? ($this->cached[$key] ?? false) : false
        );
        $cache->method('save')->willReturnCallback(function (string $data, string $key): bool {
            $this->cached[$key] = $data;

            return true;
        });

        return new ShippingDetailsEnricher(
            $this->scopeConfig,
            $this->schemaPrice,
            new CountryList(),
            new ShippingRules(),
            $priceCurrency,
            new OccasionalLog($cache, $logger)
        );
    }

    /**
     * A price currency converting the base currency into the given one at the given rate.
     *
     * @param string $currencyCode
     * @param float $rate
     * @return PriceCurrencyInterface
     */
    private function priceCurrency(string $currencyCode = 'GBP', float $rate = 1.0): PriceCurrencyInterface
    {
        $currency = $this->createStub(Currency::class);
        $currency->method('getCode')->willReturn($currencyCode);
        $priceCurrency = $this->createStub(PriceCurrencyInterface::class);
        $priceCurrency->method('convertAndRound')->willReturnCallback(
            static fn ($amount): float => round((float) $amount * $rate, 2)
        );
        $priceCurrency->method('getCurrency')->willReturn($currency);

        return $priceCurrency;
    }

    /**
     * Enabled answers the enabled flag; every other flag and value comes from $values.
     *
     * @param array<string, string> $values
     */
    private function stubConfig(bool $enabled, array $values): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => $path === 'mageos_seo_merchant/shipping/enabled'
                ? $enabled
                : ($values[$path] ?? '') === '1'
        );
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $values[$path] ?? ''
        );
    }

    public function testSortOrderIsHundred(): void
    {
        $this->assertSame(100, $this->enricher->getSortOrder());
    }

    public function testReturnsEmptyWhenDisabled(): void
    {
        $this->stubConfig(false, []);
        $this->assertSame([], $this->enricher->enrich($this->product, 1));
    }

    public function testBuildsShippingDetailsWithRateAndDelivery(): void
    {
        $this->stubConfig(true, [
            'mageos_seo_merchant/shipping/label'               => 'Standard UK Delivery',
            'mageos_seo_merchant/shipping/destination_country' => 'GB',
            'mageos_seo_merchant/shipping/rate'                => '0',
            'mageos_seo_merchant/shipping/handling_min'        => '0',
            'mageos_seo_merchant/shipping/handling_max'        => '1',
            'mageos_seo_merchant/shipping/transit_min'         => '2',
            'mageos_seo_merchant/shipping/transit_max'         => '5',
        ]);

        $shipping = $this->enricher->enrich($this->product, 1)['shippingDetails'];

        $this->assertSame('OfferShippingDetails', $shipping['@type']);
        $this->assertSame('Standard UK Delivery', $shipping['shippingLabel']);
        $this->assertSame('0.00', $shipping['shippingRate']['value']);
        $this->assertSame('GBP', $shipping['shippingRate']['currency']);
        $this->assertSame('GB', $shipping['shippingDestination']['addressCountry']);
        $this->assertSame(2, $shipping['deliveryTime']['transitTime']['minValue']);
        $this->assertSame(5, $shipping['deliveryTime']['transitTime']['maxValue']);
        $this->assertSame('DAY', $shipping['deliveryTime']['transitTime']['unitCode']);
    }

    public function testDeliveryTimeOmittedWhenNoDaysConfigured(): void
    {
        $this->stubConfig(true, [
            'mageos_seo_merchant/shipping/rate' => '4.99',
        ]);
        $shipping = $this->enricher->enrich($this->product, 1)['shippingDetails'];
        $this->assertArrayNotHasKey('deliveryTime', $shipping);
        $this->assertSame('4.99', $shipping['shippingRate']['value']);
    }

    public function testLabelAndDestinationOmittedWhenEmpty(): void
    {
        $this->stubConfig(true, ['mageos_seo_merchant/shipping/rate' => '0']);
        $shipping = $this->enricher->enrich($this->product, 1)['shippingDetails'];
        $this->assertArrayNotHasKey('shippingLabel', $shipping);
        $this->assertArrayNotHasKey('shippingDestination', $shipping);
    }

    /**
     * Google's shipping-policy reference: several destinations are an array of DefinedRegion, one
     * country each.
     */
    public function testSeveralCountriesAreOneRegionEach(): void
    {
        $this->stubConfig(true, [
            'mageos_seo_merchant/shipping/rate'                => '0',
            'mageos_seo_merchant/shipping/destination_country' => 'US,CA',
        ]);

        $shipping = $this->enricher->enrich($this->product, 1)['shippingDetails'];

        $this->assertSame(
            [
                ['@type' => 'DefinedRegion', 'addressCountry' => 'US'],
                ['@type' => 'DefinedRegion', 'addressCountry' => 'CA'],
            ],
            $shipping['shippingDestination']
        );
    }

    /**
     * "If no shipping destination is specified, the shipping conditions are applicable to all
     * shipping destinations worldwide" (Google's shipping-policy reference). Countries still stored
     * from before are not output.
     */
    public function testWorldwideGivesNoDestination(): void
    {
        $this->stubConfig(true, [
            'mageos_seo_merchant/shipping/rate'                => '0',
            'mageos_seo_merchant/shipping/worldwide'           => '1',
            'mageos_seo_merchant/shipping/destination_country' => 'GB',
        ]);

        $shipping = $this->enricher->enrich($this->product, 1)['shippingDetails'];

        $this->assertArrayNotHasKey('shippingDestination', $shipping);
    }

    /**
     * Issue #32: a maximum alone is published alone — no minimum of 0 is invented.
     *
     * @return void
     */
    public function testAMaximumAloneIsPublishedAlone(): void
    {
        $this->stubConfig(true, [
            'mageos_seo_merchant/shipping/rate'         => '0',
            'mageos_seo_merchant/shipping/handling_max' => '1',
        ]);

        $shipping = $this->enricher->enrich($this->product, 1)['shippingDetails'];

        $this->assertSame(
            ['@type' => 'QuantitativeValue', 'maxValue' => 1, 'unitCode' => 'DAY'],
            $shipping['deliveryTime']['handlingTime']
        );
        $this->assertArrayNotHasKey('transitTime', $shipping['deliveryTime']);
    }

    /**
     * Issue #32: a minimum alone was published as minimum..0. Google needs a maximum, so the time is
     * left out — and the stored value, which the admin refuses, is logged.
     *
     * @return void
     */
    public function testAMinimumAloneIsLeftOutAndLogged(): void
    {
        $this->stubConfig(true, [
            'mageos_seo_merchant/shipping/rate'         => '0',
            'mageos_seo_merchant/shipping/handling_max' => '1',
            'mageos_seo_merchant/shipping/transit_min'  => '2',
        ]);

        $shipping = $this->enricher->enrich($this->product, 1)['shippingDetails'];

        $this->assertArrayNotHasKey('transitTime', $shipping['deliveryTime']);
        $this->assertArrayHasKey('handlingTime', $shipping['deliveryTime'], 'The other time is kept.');
        $this->assertCount(1, $this->logged);
        $this->assertSame('warning', $this->logged[0][0]);
        $this->assertStringContainsString('transit time', $this->logged[0][1]);
    }

    /**
     * Issue #32: an inverted range, a negative number of days or a fraction is left out.
     *
     * @return void
     */
    public function testATimeBreakingTheRulesIsLeftOut(): void
    {
        $this->stubConfig(true, [
            'mageos_seo_merchant/shipping/rate'         => '0',
            'mageos_seo_merchant/shipping/handling_min' => '-1',
            'mageos_seo_merchant/shipping/handling_max' => '1',
            'mageos_seo_merchant/shipping/transit_min'  => '5',
            'mageos_seo_merchant/shipping/transit_max'  => '2',
        ]);

        $shipping = $this->enricher->enrich($this->product, 1)['shippingDetails'];

        $this->assertArrayNotHasKey('deliveryTime', $shipping);
        $this->assertCount(2, $this->logged);
    }

    /**
     * A blank rate was published as 0.00 — free shipping. No rate is not free shipping: the details
     * are left out, and logged.
     *
     * @return void
     */
    public function testABlankRateLeavesTheShippingDetailsOut(): void
    {
        $this->stubConfig(true, ['mageos_seo_merchant/shipping/transit_max' => '3']);

        $this->assertSame([], $this->enricher->enrich($this->product, 1));
        $this->assertCount(1, $this->logged);
        $this->assertStringContainsString('shipping rate', $this->logged[0][1]);
    }

    /**
     * Issue #33: the rate is in the base currency, converted to the currency the visitor sees, as
     * product prices are — not the base amount relabelled.
     *
     * @return void
     */
    public function testTheRateIsConvertedFromTheBaseCurrency(): void
    {
        $this->stubConfig(true, ['mageos_seo_merchant/shipping/rate' => '10']);

        $shipping = $this->enricher($this->priceCurrency('EUR', 0.9))->enrich($this->product, 1)['shippingDetails'];

        $this->assertSame(
            ['@type' => 'MonetaryAmount', 'value' => '9.00', 'currency' => 'EUR'],
            $shipping['shippingRate']
        );
    }

    /**
     * A rate that cannot be converted is left out, with the shipping details, and logged: a wrong
     * amount is worse than none.
     *
     * @return void
     */
    public function testARateThatCannotBeConvertedLeavesTheShippingDetailsOut(): void
    {
        $this->stubConfig(true, ['mageos_seo_merchant/shipping/rate' => '10']);
        $priceCurrency = $this->createStub(PriceCurrencyInterface::class);
        $priceCurrency->method('convertAndRound')->willThrowException(new \RuntimeException('Undefined rate'));

        $this->assertSame([], $this->enricher($priceCurrency)->enrich($this->product, 1));
        $this->assertSame('error', $this->logged[0][0] ?? null);
        $this->assertStringContainsString('Undefined rate', $this->logged[0][1]);
        $this->assertInstanceOf(\RuntimeException::class, $this->logged[0][2]['exception'] ?? null);
    }

    /**
     * Faults are logged once in a while, each on its own: one store view's does not keep another's
     * out of the log, nor one shipping time the other.
     *
     * @return void
     */
    public function testEachStoreViewsFaultsAreLoggedOnceInAWhile(): void
    {
        $this->stubConfig(true, [
            'mageos_seo_merchant/shipping/rate'         => '0',
            'mageos_seo_merchant/shipping/handling_min' => '2',
            'mageos_seo_merchant/shipping/transit_min'  => '3',
        ]);
        $enricher = $this->enricher($this->priceCurrency(), true);

        foreach ([1, 2, 1, 2] as $storeId) {
            $enricher->enrich($this->product, $storeId);
        }

        $this->assertCount(4, $this->logged, 'Two times in two store views, each logged once.');
    }

    /**
     * A rate fault, and a failed conversion, are logged once in a while per store view too.
     *
     * @return void
     */
    public function testRateFaultsAreLoggedOnceInAWhilePerStoreView(): void
    {
        $this->stubConfig(true, ['mageos_seo_merchant/shipping/rate' => 'free']);
        $enricher = $this->enricher($this->priceCurrency(), true);
        foreach ([1, 2, 1] as $storeId) {
            $enricher->enrich($this->product, $storeId);
        }
        $this->assertCount(2, $this->logged, 'A rate that is not an amount, once per store view.');

        $this->logged = [];
        $this->scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $this->stubConfig(true, ['mageos_seo_merchant/shipping/rate' => '10']);
        $failing = $this->createStub(PriceCurrencyInterface::class);
        $failing->method('convertAndRound')->willThrowException(new \RuntimeException('Undefined rate'));
        $enricher = $this->enricher($failing, true);
        foreach ([1, 2, 1] as $storeId) {
            $enricher->enrich($this->product, $storeId);
        }
        $this->assertCount(2, $this->logged, 'A failed conversion, once per store view.');
    }
}
