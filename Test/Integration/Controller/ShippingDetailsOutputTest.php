<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Controller;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Directory\Model\Currency;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * Issues #32 and #33 on a product page: the shipping rate is converted from the base currency to
 * the currency the visitor sees, as the offer's price is, and a maximum alone is published alone.
 *
 * The store's base currency is USD; it shows prices in EUR, at a rate saved for the test.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class ShippingDetailsOutputTest extends AbstractController
{
    use ProductPageOutput;

    /**
     * @return void
     */
    #[Config('currency/options/allow', 'USD,EUR', ScopeInterface::SCOPE_STORE, 'default')]
    #[Config('currency/options/default', 'EUR', ScopeInterface::SCOPE_STORE, 'default')]
    #[Config('mageos_seo_merchant/shipping/enabled', '1', ScopeInterface::SCOPE_STORE, 'default')]
    #[Config('mageos_seo_merchant/shipping/rate', '10', ScopeInterface::SCOPE_STORE, 'default')]
    #[Config('mageos_seo_merchant/shipping/transit_max', '3', ScopeInterface::SCOPE_STORE, 'default')]
    #[DataFixture(ProductFixture::class, ['price' => 12.5], 'product')]
    public function testTheShippingRateIsInTheCurrencyShownLikeThePrice(): void
    {
        Bootstrap::getObjectManager()->create(Currency::class)->saveRates(['USD' => ['EUR' => 0.5]]);

        $offer = $this->productNode($this->productPage('product'))['offers'] ?? [];

        $this->assertSame('EUR', $offer['priceCurrency'] ?? null, 'The page shows EUR.');
        $this->assertSame('6.25', $offer['price'] ?? null, 'The price is converted.');
        $this->assertSame(
            ['@type' => 'MonetaryAmount', 'value' => '5.00', 'currency' => 'EUR'],
            $offer['shippingDetails']['shippingRate'] ?? null,
            'The shipping rate is converted, not relabelled.'
        );
        $this->assertSame(
            ['@type' => 'QuantitativeValue', 'maxValue' => 3, 'unitCode' => 'DAY'],
            $offer['shippingDetails']['deliveryTime']['transitTime'] ?? null,
            'No minimum is invented.'
        );
    }
}
