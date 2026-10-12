<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\OfferEnricher;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\Seo\Api\OfferEnricherInterface;
use MageOS\Seo\Api\SchemaPriceInterface;
use MageOS\Seo\Model\Log\OccasionalLog;

/**
 * Adds an OfferShippingDetails node (one rate and delivery time) to the Offer from store configuration.
 *
 * Drives the "Free shipping" / "Arrives in N days" Shopping signals. Opt-in; emits nothing unless
 * enabled.
 *
 * The rate is in the base currency, as product prices and core's flat-rate shipping are, and is
 * converted to the currency the visitor sees — the offer's price currency (issue #33). Handling and
 * transit times are published as Google's shipping-policy reference reads them: a maximum number
 * of days, with a minimum or without (issue #32). Nothing is invented: a time that breaks the rules
 * (ShippingRules) is left out, and so are the shipping details when the rate is missing, invalid
 * or cannot be converted — no rate is not free shipping, and a wrong amount is worse than none.
 * The admin refuses such values; one written past it is logged, once in a while (OccasionalLog).
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
     * The times, by the config path of their minimum: the path of the maximum, and the name logged.
     */
    private const TIMES = [
        self::XML_HANDLING_MIN => [self::XML_HANDLING_MAX, 'handling time'],
        self::XML_TRANSIT_MIN  => [self::XML_TRANSIT_MAX, 'transit time'],
    ];

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param SchemaPriceInterface $schemaPrice
     * @param CountryList $countryList
     * @param ShippingRules $shippingRules
     * @param PriceCurrencyInterface $priceCurrency
     * @param OccasionalLog $occasionalLog
     */
    public function __construct(
        private readonly ScopeConfigInterface   $scopeConfig,
        private readonly SchemaPriceInterface   $schemaPrice,
        private readonly CountryList            $countryList,
        private readonly ShippingRules          $shippingRules,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly OccasionalLog          $occasionalLog
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

        $rate = $this->shippingRate($storeId);
        if ($rate === null) {
            return [];
        }

        $shipping = [
            '@type'        => 'OfferShippingDetails',
            'shippingRate' => $rate,
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
     * The rate as a MonetaryAmount in the currency the visitor sees, or null when it cannot be one.
     *
     * @param int $storeId
     * @return array{'@type': string, value: string, currency: string}|null
     */
    private function shippingRate(int $storeId): ?array
    {
        $configured = $this->value(self::XML_RATE, $storeId);
        if ($this->shippingRules->rateProblem($configured) !== null) {
            $this->occasionalLog->warning(
                'shipping rate' . "\0" . $storeId,
                \sprintf(
                    'MageOS_Seo: store view %d\'s shipping rate "%s" is not an amount of 0 or more, so its'
                    . ' shipping details are left out. Set it under SEO Merchant Policies → Shipping Details;'
                    . ' 0.00 is free shipping.',
                    $storeId,
                    $configured
                )
            );
            return null;
        }

        try {
            $amount   = $this->priceCurrency->convertAndRound((float) $configured, $storeId);
            $currency = (string) $this->priceCurrency->getCurrency($storeId)->getCode();
        } catch (\Exception $e) {
            $this->occasionalLog->error(
                'shipping rate conversion' . "\0" . $storeId,
                \sprintf(
                    'MageOS_Seo: store view %d\'s shipping rate could not be converted to the currency shown,'
                    . ' so its shipping details are left out: %s',
                    $storeId,
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
            return null;
        }

        return [
            '@type'    => 'MonetaryAmount',
            'value'    => $this->schemaPrice->amount($amount),
            'currency' => $currency,
        ];
    }

    /**
     * Build the ShippingDeliveryTime node from handling/transit config, or [] if none set.
     *
     * @param int $storeId
     * @return array<string, mixed>
     */
    private function buildDeliveryTime(int $storeId): array
    {
        $deliveryTime = ['@type' => 'ShippingDeliveryTime'];
        foreach (['handlingTime' => self::XML_HANDLING_MIN, 'transitTime' => self::XML_TRANSIT_MIN] as $name => $min) {
            $range = $this->quantitativeRange($min, $storeId);
            if ($range !== []) {
                $deliveryTime[$name] = $range;
            }
        }

        return \count($deliveryTime) > 1 ? $deliveryTime : [];
    }

    /**
     * Build a QuantitativeValue day range, or [] when it is not set or breaks the rules.
     *
     * @param string $minPath
     * @param int $storeId
     * @return array<string, mixed>
     */
    private function quantitativeRange(string $minPath, int $storeId): array
    {
        [$maxPath, $name] = self::TIMES[$minPath];
        $min = $this->value($minPath, $storeId);
        $max = $this->value($maxPath, $storeId);

        if ($min === '' && $max === '') {
            return [];
        }

        $bounds = $this->shippingRules->dayBounds($min, $max);
        if ($bounds === null) {
            $this->occasionalLog->warning(
                $name . "\0" . $storeId,
                \sprintf(
                    'MageOS_Seo: store view %d\'s %s of "%s" to "%s" days is left out: give the most days,'
                    . ' whole and 0 or more, and the fewest only with it and no more than it.',
                    $storeId,
                    $name,
                    $min,
                    $max
                )
            );
            return [];
        }

        return ['@type' => 'QuantitativeValue', ...$bounds, 'unitCode' => 'DAY'];
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
