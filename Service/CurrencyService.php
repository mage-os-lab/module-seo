<?php

declare(strict_types=1);

namespace MageOS\Seo\Service;

use Magento\Directory\Model\Currency;
use Magento\Framework\Locale\FormatInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Currency service.
 *
 * Provides consistent access to currency codes, symbols and formatted
 * price strings across all store views.
 */
class CurrencyService
{
    /**
     * Service constructor
     *
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Get currency code
     *
     * Get the current store's active currency code.
     * e.g. "GBP", "EUR", "USD"
     *
     * @param int|null $storeId
     * @return string
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
     *
     * E.g. "GBP" — the currency the store is configured in, regardless
     * of what the customer has switched to.
     *
     * Returns an empty string when no store context can be resolved: callers all
     * run with a resolved store, so inventing a currency here would only mask a
     * broken store context with wrong data.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getBaseCurrencyCode(?int $storeId = null): string
    {
        try {
            return $this->getStore($storeId)->getBaseCurrencyCode();
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * Get the currency symbol
     *
     * Currency symbol for the current store's active currency.
     * e.g. "£", "€", "$"
     *
     * @param int|null $storeId
     * @return string
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
     *
     * @param int|null $storeId
     * @return string
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
     * Format a price
     *
     * Price value as a localised string using the current currency.
     * e.g. 29.99 => "£29.99"
     *
     * @param float $amount The price to format
     * @param bool $includeSymbol Whether to include the currency symbol
     * @param int|null $storeId Optional store ID, defaults to current store
     * @return string
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
     *
     * Useful when displaying prices that have not been converted.
     *
     * @param float $amount The price to format
     * @param bool $includeSymbol Whether to include the currency symbol
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
     *
     * Returns the original amount if conversion fails.
     *
     * @param float $amount
     * @param int|null $storeId
     * @return float
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
     * Store getter
     *
     * @param int|null $storeId
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @return Store
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
