<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Service;

use Magento\Directory\Model\Currency;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Service\CurrencyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CurrencyServiceTest extends TestCase
{
    private StoreManagerInterface&MockObject $storeManager;
    private Store&MockObject $store;
    private Currency&MockObject $currentCurrency;
    private Currency&MockObject $baseCurrency;
    private CurrencyService $service;

    protected function setUp(): void
    {
        $this->storeManager    = $this->createMock(StoreManagerInterface::class);
        $this->store           = $this->createMock(Store::class);
        $this->currentCurrency = $this->createMock(Currency::class);
        $this->baseCurrency    = $this->createMock(Currency::class);

        $this->store->method('getCurrentCurrencyCode')->willReturn('EUR');
        $this->store->method('getBaseCurrencyCode')->willReturn('GBP');
        $this->store->method('getCurrentCurrency')->willReturn($this->currentCurrency);
        $this->store->method('getBaseCurrency')->willReturn($this->baseCurrency);

        $this->currentCurrency->method('getCurrencySymbol')->willReturn('€');
        $this->baseCurrency->method('getCurrencySymbol')->willReturn('£');

        $this->storeManager->method('getStore')->willReturn($this->store);

        $this->service = new CurrencyService($this->storeManager);
    }

    public function testGetCurrentCurrencyCodeReturnsStoreCurrentCode(): void
    {
        $this->assertSame('EUR', $this->service->getCurrentCurrencyCode());
    }

    public function testGetCurrentCurrencyCodeWithExplicitStoreId(): void
    {
        $this->storeManager
            ->expects($this->once())
            ->method('getStore')
            ->with(2)
            ->willReturn($this->store);

        $this->assertSame('EUR', $this->service->getCurrentCurrencyCode(2));
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

    public function testGetBaseCurrencyCodeFallsBackToGbpOnException(): void
    {
        $this->store
            ->method('getBaseCurrencyCode')
            ->willThrowException(new \Exception('Store error'));

        // Ultimate fallback is the hardcoded 'GBP'
        $this->assertSame('GBP', $this->service->getBaseCurrencyCode());
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
        $this->currentCurrency
            ->expects($this->once())
            ->method('formatPrecision')
            ->with(29.99, 2, [], true, false)
            ->willReturn('€29.99');

        $result = $this->service->formatPrice(29.99);

        $this->assertSame('€29.99', $result);
    }

    public function testFormatPriceWithoutSymbol(): void
    {
        $this->currentCurrency
            ->expects($this->once())
            ->method('formatPrecision')
            ->with(29.99, 2, [], false, false)
            ->willReturn('29.99');

        $result = $this->service->formatPrice(29.99, false);

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
        $this->baseCurrency
            ->expects($this->once())
            ->method('formatPrecision')
            ->with(49.99, 2, [], true, false)
            ->willReturn('£49.99');

        $result = $this->service->formatBasePrice(49.99);

        $this->assertSame('£49.99', $result);
    }

    public function testConvertFromBaseConvertsCorrectly(): void
    {
        $this->baseCurrency
            ->expects($this->once())
            ->method('convert')
            ->with(100.0, 'EUR')
            ->willReturn(118.5);

        $result = $this->service->convertFromBase(100.0);

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
}
