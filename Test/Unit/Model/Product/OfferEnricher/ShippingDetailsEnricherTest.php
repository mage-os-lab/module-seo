<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\OfferEnricher;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\Seo\Model\Product\OfferEnricher\CountryList;
use MageOS\Seo\Model\Product\OfferEnricher\ShippingDetailsEnricher;
use MageOS\Seo\Service\CurrencyService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ShippingDetailsEnricherTest extends TestCase
{
    /**
     * @var ScopeConfigInterface&Stub
     */
    private ScopeConfigInterface&Stub $scopeConfig;

    /**
     * @var CurrencyService&Stub
     */
    private CurrencyService&Stub $currencyService;

    /**
     * @var ProductInterface&Stub
     */
    private ProductInterface&Stub $product;

    /**
     * @var ShippingDetailsEnricher
     */
    private ShippingDetailsEnricher $enricher;

    protected function setUp(): void
    {
        $this->scopeConfig     = $this->createStub(ScopeConfigInterface::class);
        $this->currencyService = $this->createStub(CurrencyService::class);
        $this->product         = $this->createStub(ProductInterface::class);
        $this->currencyService->method('getCurrentCurrencyCode')->willReturn('GBP');
        $this->enricher = new ShippingDetailsEnricher($this->scopeConfig, $this->currencyService, new CountryList());
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
        $this->stubConfig(true, ['mageos_seo_merchant/shipping/destination_country' => 'US,CA']);

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
            'mageos_seo_merchant/shipping/worldwide'           => '1',
            'mageos_seo_merchant/shipping/destination_country' => 'GB',
        ]);

        $shipping = $this->enricher->enrich($this->product, 1)['shippingDetails'];

        $this->assertArrayNotHasKey('shippingDestination', $shipping);
    }
}
