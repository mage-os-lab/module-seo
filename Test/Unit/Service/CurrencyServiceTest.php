<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Service;

use Magento\Directory\Model\Currency;
use Magento\Framework\Locale\Resolver;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Service\CurrencyService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class CurrencyServiceTest extends TestCase
{
    private Store&Stub $store;
    private Resolver&Stub $locale;
    private CurrencyService $service;

    protected function setUp(): void
    {
        $this->locale = $this->createStub(Resolver::class);
        $this->locale->method('getLocale')->willReturn('en_GB');

        $this->store   = $this->store();
        $this->service = $this->service();
    }

    public function testGetCurrentCurrencyCodeReturnsStoreCurrentCode(): void
    {
        $this->assertSame('EUR', $this->service->getCurrentCurrencyCode());
    }

    public function testGetCurrentCurrencyCodeWithExplicitStoreId(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager
            ->expects($this->once())
            ->method('getStore')
            ->with(2)
            ->willReturn($this->store);

        $this->assertSame('EUR', $this->service(storeManager: $storeManager)->getCurrentCurrencyCode(2));
    }

    public function testGetCurrentCurrencyCodeFallsBackToBaseCodeOnException(): void
    {
        $this->store
            ->method('getCurrentCurrencyCode')
            ->willThrowException(new \Exception('Currency not set'));

        $result = $this->service->getCurrentCurrencyCode();

        // Falls back to getBaseCurrencyCode which returns GBP
        $this->assertSame('GBP', $result);
    }

    public function testGetBaseCurrencyCodeReturnsStoreBaseCode(): void
    {
        $this->assertSame('GBP', $this->service->getBaseCurrencyCode());
    }

    public function testGetBaseCurrencyCodeReturnsEmptyStringOnException(): void
    {
        $this->store
            ->method('getBaseCurrencyCode')
            ->willThrowException(new \Exception('Store error'));

        // No store context means no knowable currency — never invent one.
        $this->assertSame('', $this->service->getBaseCurrencyCode());
    }

    public function testGetCurrentCurrencySymbolReturnsSymbol(): void
    {
        $this->assertSame('€', $this->service->getCurrentCurrencySymbol());
    }

    public function testGetCurrentCurrencySymbolFallsBackToCodeOnException(): void
    {
        $this->store
            ->method('getCurrentCurrency')
            ->willThrowException(new \Exception('Currency error'));

        // Falls back to getCurrentCurrencyCode which returns 'EUR'
        $result = $this->service->getCurrentCurrencySymbol();

        $this->assertSame('EUR', $result);
    }

    public function testGetBaseCurrencySymbolReturnsSymbol(): void
    {
        $this->assertSame('£', $this->service->getBaseCurrencySymbol());
    }

    public function testGetBaseCurrencySymbolFallsBackToCodeOnException(): void
    {
        $this->store
            ->method('getBaseCurrency')
            ->willThrowException(new \Exception('Currency error'));

        $result = $this->service->getBaseCurrencySymbol();

        $this->assertSame('GBP', $result);
    }

    public function testFormatPriceCallsCurrencyFormatPrecision(): void
    {
        $currency = $this->createMock(Currency::class);
        $currency
            ->expects($this->once())
            ->method('formatPrecision')
            ->with(29.99, 2, [], true, false)
            ->willReturn('€29.99');

        $result = $this->service(store: $this->store(currentCurrency: $currency))->formatPrice(29.99);

        $this->assertSame('€29.99', $result);
    }

    public function testFormatPriceWithoutSymbol(): void
    {
        $currency = $this->createMock(Currency::class);
        $currency
            ->expects($this->once())
            ->method('formatPrecision')
            ->with(29.99, 2, [], false, false)
            ->willReturn('29.99');

        $result = $this->service(store: $this->store(currentCurrency: $currency))->formatPrice(29.99, false);

        $this->assertSame('29.99', $result);
    }

    public function testFormatPriceFallsBackToNumberFormatOnException(): void
    {
        $this->store
            ->method('getCurrentCurrency')
            ->willThrowException(new \Exception('Format error'));

        $result = $this->service->formatPrice(29.99);

        // Fallback: symbol + number_format
        $this->assertStringContainsString('29.99', $result);
    }

    public function testFormatBasePriceCallsBaseCurrencyFormatPrecision(): void
    {
        $currency = $this->createMock(Currency::class);
        $currency
            ->expects($this->once())
            ->method('formatPrecision')
            ->with(49.99, 2, [], true, false)
            ->willReturn('£49.99');

        $result = $this->service(store: $this->store(baseCurrency: $currency))->formatBasePrice(49.99);

        $this->assertSame('£49.99', $result);
    }

    public function testConvertFromBaseConvertsCorrectly(): void
    {
        $currency = $this->createMock(Currency::class);
        $currency
            ->expects($this->once())
            ->method('convert')
            ->with(100.0, 'EUR')
            ->willReturn(118.5);

        $result = $this->service(store: $this->store(baseCurrency: $currency))->convertFromBase(100.0);

        $this->assertSame(118.5, $result);
    }

    public function testConvertFromBaseReturnsOriginalAmountOnException(): void
    {
        $this->store
            ->method('getBaseCurrency')
            ->willThrowException(new \Exception('Conversion error'));

        $result = $this->service->convertFromBase(100.0);

        $this->assertSame(100.0, $result);
    }

    /**
     * The service under test, reading its store through a store manager.
     *
     * @param StoreManagerInterface|null $storeManager Defaults to one that returns $store
     * @param Store|null $store Defaults to the test's store
     * @return CurrencyService
     */
    private function service(?StoreManagerInterface $storeManager = null, ?Store $store = null): CurrencyService
    {
        if ($storeManager === null) {
            $storeManager = $this->createStub(StoreManagerInterface::class);
            $storeManager->method('getStore')->willReturn($store ?? $this->store);
        }

        return new CurrencyService($storeManager, $this->locale);
    }

    /**
     * A store in EUR on a GBP base, returning the given currencies.
     *
     * The currencies are wired in when the store is built: a stubbed method keeps the first value
     * it is given, so a currency a test verifies could not be swapped in afterwards.
     *
     * @param Currency|null $currentCurrency Defaults to one whose symbol is €
     * @param Currency|null $baseCurrency Defaults to one whose symbol is £
     * @return Store&Stub
     */
    private function store(?Currency $currentCurrency = null, ?Currency $baseCurrency = null): Store&Stub
    {
        $store = $this->createStub(Store::class);
        $store->method('getCurrentCurrencyCode')->willReturn('EUR');
        $store->method('getBaseCurrencyCode')->willReturn('GBP');
        $store->method('getCurrentCurrency')->willReturn($currentCurrency ?? $this->currency('€'));
        $store->method('getBaseCurrency')->willReturn($baseCurrency ?? $this->currency('£'));

        return $store;
    }

    /**
     * A currency with the given symbol.
     *
     * @param string $symbol
     * @return Currency&Stub
     */
    private function currency(string $symbol): Currency&Stub
    {
        $currency = $this->createStub(Currency::class);
        $currency->method('getCurrencySymbol')->willReturn($symbol);

        return $currency;
    }
}
