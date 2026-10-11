<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Price;

use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\SchemaPriceInterface;

/**
 * A price as structured data and the feeds publish it: the amount as a plain number, and the store
 * view's current currency.
 *
 * Replaces the old CurrencyService, of which only these two were ever used. A store that cannot be
 * read is not answered with a guessed currency: the exception reaches the caller, whose log names it,
 * rather than a price going out in the wrong currency.
 */
class SchemaPrice implements SchemaPriceInterface
{
    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritdoc
     */
    public function amount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * @inheritdoc
     */
    public function currencyCode(): string
    {
        /** @var Store $store */
        $store = $this->storeManager->getStore();

        return (string) $store->getCurrentCurrencyCode();
    }
}
