<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\OfferEnricher;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\Seo\Api\OfferEnricherInterface;
use MageOS\Seo\Service\CurrencyService;

/**
 * Adds an OfferShippingDetails node (one rate and delivery time) to the Offer from store configuration.
 *
 * Drives the "Free shipping" / "Arrives in N days" Shopping signals. Opt-in; emits nothing unless
 * enabled. Currency follows the current store display currency.
 *
 * Destinations: one DefinedRegion per chosen country. Worldwide shipping gives none: "If no
 * shipping destination is specified, the shipping conditions are applicable to all shipping
 * destinations worldwide" (Google's shipping-policy reference).
 */
class ShippingDetailsEnricher implements OfferEnricherInterface
{
    private const XML_ENABLED       = 'mageos_seo_merchant/shipping/enabled';
    private const XML_WORLDWIDE     = 'mageos_seo_merchant/shipping/worldwide';
    private const XML_LABEL         = 'mageos_seo_merchant/shipping/label';
    private const XML_COUNTRY       = 'mageos_seo_merchant/shipping/destination_country';
    private const XML_RATE          = 'mageos_seo_merchant/shipping/rate';
    private const XML_HANDLING_MIN  = 'mageos_seo_merchant/shipping/handling_min';
    private const XML_HANDLING_MAX  = 'mageos_seo_merchant/shipping/handling_max';
    private const XML_TRANSIT_MIN   = 'mageos_seo_merchant/shipping/transit_min';
    private const XML_TRANSIT_MAX   = 'mageos_seo_merchant/shipping/transit_max';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param CurrencyService $currencyService
     * @param CountryList $countryList
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CurrencyService      $currencyService,
        private readonly CountryList          $countryList
    ) {
    }

    /**
     * @inheritdoc
     */
    public function enrich(ProductInterface $product, int $storeId): array
    {
        if (!$this->scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)) {
            return [];
        }

        $shipping = [
            '@type'        => 'OfferShippingDetails',
            'shippingRate' => [
                '@type'    => 'MonetaryAmount',
                'value'    => number_format((float) $this->value(self::XML_RATE, $storeId), 2, '.', ''),
                'currency' => $this->currencyService->getCurrentCurrencyCode(),
            ],
        ];

        $label = $this->value(self::XML_LABEL, $storeId);
        if ($label !== '') {
            $shipping['shippingLabel'] = $label;
        }

        $regions = $this->destinations($storeId);
        if ($regions !== []) {
            // One region as an object, as before; several as an array.
            $shipping['shippingDestination'] = \count($regions) === 1 ? $regions[0] : $regions;
        }

        $deliveryTime = $this->buildDeliveryTime($storeId);
        if ($deliveryTime !== []) {
            $shipping['deliveryTime'] = $deliveryTime;
        }

        return ['shippingDetails' => $shipping];
    }

    /**
     * @inheritdoc
     */
    public function getSortOrder(): int
    {
        return 100;
    }

    /**
     * One DefinedRegion per chosen country; none when shipping is worldwide.
     *
     * Worldwide ignores countries still stored from before it was set.
     *
     * @param int $storeId
     * @return array<int, array{'@type': string, addressCountry: string}>
     */
    private function destinations(int $storeId): array
    {
        if ($this->scopeConfig->isSetFlag(self::XML_WORLDWIDE, ScopeInterface::SCOPE_STORE, $storeId)) {
            return [];
        }

        return array_map(
            static fn (string $country): array => ['@type' => 'DefinedRegion', 'addressCountry' => $country],
            $this->countryList->fromConfig($this->value(self::XML_COUNTRY, $storeId))
        );
    }

    /**
     * Build the ShippingDeliveryTime node from handling/transit config, or [] if none set.
     *
     * @param int $storeId
     * @return array<string, mixed>
     */
    private function buildDeliveryTime(int $storeId): array
    {
        $handling = $this->quantitativeRange(self::XML_HANDLING_MIN, self::XML_HANDLING_MAX, $storeId);
        $transit  = $this->quantitativeRange(self::XML_TRANSIT_MIN, self::XML_TRANSIT_MAX, $storeId);

        $deliveryTime = ['@type' => 'ShippingDeliveryTime'];
        if ($handling !== []) {
            $deliveryTime['handlingTime'] = $handling;
        }
        if ($transit !== []) {
            $deliveryTime['transitTime'] = $transit;
        }

        return \count($deliveryTime) > 1 ? $deliveryTime : [];
    }

    /**
     * Build a QuantitativeValue day range, or [] when neither bound is configured.
     *
     * @param string $minPath
     * @param string $maxPath
     * @param int $storeId
     * @return array<string, mixed>
     */
    private function quantitativeRange(string $minPath, string $maxPath, int $storeId): array
    {
        $min = $this->value($minPath, $storeId);
        $max = $this->value($maxPath, $storeId);

        if ($min === '' && $max === '') {
            return [];
        }

        return [
            '@type'    => 'QuantitativeValue',
            'minValue' => (int) $min,
            'maxValue' => (int) $max,
            'unitCode' => 'DAY',
        ];
    }

    /**
     * Read a store-scoped config value as a trimmed string.
     *
     * @param string $path
     * @param int $storeId
     * @return string
     */
    private function value(string $path, int $storeId): string
    {
        return trim((string) $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId));
    }
}
