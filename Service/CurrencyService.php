<?php

declare(strict_types=1);

namespace MageOS\Seo\Service;

use Magento\Directory\Model\Currency;
use Magento\Framework\Locale\FormatInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Shared currency service for Reessolutions modules.
 *
 * Provides consistent access to currency codes, symbols and formatted
 * price strings across all store views. Inject this in place of
 * hardcoded currency fallbacks throughout the Reessolutions module suite.
 */
class CurrencyService
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {}

    /**
     * Get the current store's active currency code.
     * e.g. "GBP", "EUR", "USD"
     */
    public function getCurrentCurrencyCode(?int $storeId = null): string
    {
        try {
            return $this->getStore($storeId)->getCurrentCurrencyCode();
        } catch (\Exception) {
            return $this->getBaseCurrencyCode($storeId);
        }
    }

    /**
     * Get the current store's base currency code.
     * e.g. "GBP" — the currency the store is configured in, regardless
     * of what the customer has switched to.
     */
    public function getBaseCurrencyCode(?int $storeId = null): string
    {
        try {
            return $this->getStore($storeId)->getBaseCurrencyCode();
        } catch (\Exception) {
            return 'GBP';
        }
    }

    /**
     * Get the currency symbol for the current store's active currency.
     * e.g. "£", "€", "$"
     */
    public function getCurrentCurrencySymbol(?int $storeId = null): string
    {
        try {
            return $this->getStore($storeId)
                        ->getCurrentCurrency()
                        ->getCurrencySymbol();
        } catch (\Exception) {
            return $this->getCurrentCurrencyCode($storeId);
        }
    }

    /**
     * Get the currency symbol for the store's base currency.
     */
    public function getBaseCurrencySymbol(?int $storeId = null): string
    {
        try {
            return $this->getStore($storeId)
                        ->getBaseCurrency()
                        ->getCurrencySymbol();
        } catch (\Exception) {
            return $this->getBaseCurrencyCode($storeId);
        }
    }

    /**
     * Format a price value as a localised string using the current currency.
     * e.g. 29.99 => "£29.99"
     *
     * @param float $amount The price to format
     * @param bool  $includeSymbol Whether to include the currency symbol
     * @param int|null $storeId Optional store ID, defaults to current store
     */
    public function formatPrice(
        float $amount,
        bool $includeSymbol = true,
        ?int $storeId = null
    ): string {
        try {
            $currency = $this->getStore($storeId)->getCurrentCurrency();
            return $currency->formatPrecision(
                $amount,
                2,
                [],
                $includeSymbol,
                false
            );
        } catch (\Exception) {
            // Graceful fallback — symbol + 2 decimal places
            $symbol = $includeSymbol ? $this->getCurrentCurrencySymbol($storeId) : '';
            return $symbol . number_format($amount, 2);
        }
    }

    /**
     * Format a price value using the store's base currency.
     * Useful when displaying prices that have not been converted.
     *
     * @param float $amount The price to format
     * @param bool  $includeSymbol Whether to include the currency symbol
     * @param int|null $storeId Optional store ID, defaults to current store
     */
    public function formatBasePrice(
        float $amount,
        bool $includeSymbol = true,
        ?int $storeId = null
    ): string {
        try {
            $currency = $this->getStore($storeId)->getBaseCurrency();
            return $currency->formatPrecision(
                $amount,
                2,
                [],
                $includeSymbol,
                false
            );
        } catch (\Exception) {
            $symbol = $includeSymbol ? $this->getBaseCurrencySymbol($storeId) : '';
            return $symbol . number_format($amount, 2);
        }
    }

    /**
     * Convert an amount from the base currency to the current display currency.
     * Returns the original amount if conversion fails.
     */
    public function convertFromBase(float $amount, ?int $storeId = null): float
    {
        try {
            $store = $this->getStore($storeId);
            return (float) $store->getBaseCurrency()->convert(
                $amount,
                $store->getCurrentCurrencyCode()
            );
        } catch (\Exception) {
            return $amount;
        }
    }

    /**
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    private function getStore(?int $storeId = null): Store
    {
        /** @var Store $store */
        $store = $storeId !== null
            ? $this->storeManager->getStore($storeId)
            : $this->storeManager->getStore();

        return $store;
    }
}