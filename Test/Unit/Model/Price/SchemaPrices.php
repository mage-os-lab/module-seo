<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Price;

use Magento\Directory\Model\Currency;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Price\SchemaPrice;

/**
 * A real SchemaPrice over a stubbed store, for a test whose subject writes prices.
 *
 * The amounts such a test asserts are then formatted by SchemaPrice itself, so they check the
 * format the module publishes rather than a stub's copy of it.
 */
trait SchemaPrices
{
    /**
     * A real SchemaPrice for a store whose current currency is the given one.
     *
     * The store's base currency converts at the given rate, as core's Currency::convert() would.
     * Prices from PriceInfo are already converted, so a test proving one is not converted a
     * second time sets a rate other than 1: nothing may reach the conversion.
     *
     * @param string $currencyCode
     * @param float $rate Current currency per unit of base currency
     * @return SchemaPrice
     */
    private function schemaPrice(string $currencyCode = 'GBP', float $rate = 1.0): SchemaPrice
    {
        $baseCurrency = $this->createStub(Currency::class);
        $baseCurrency->method('convert')->willReturnCallback(
            static fn ($amount): float => (float) $amount * $rate
        );
        $store = $this->createStub(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn($currencyCode);
        $store->method('getBaseCurrency')->willReturn($baseCurrency);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new SchemaPrice($storeManager);
    }
}
