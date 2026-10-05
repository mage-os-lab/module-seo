<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\OfferEnricher;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\Seo\Api\OfferEnricherInterface;
use MageOS\Seo\Model\Config;

/**
 * Adds a hasMerchantReturnPolicy node to the Offer from store configuration.
 *
 * Required for Google Merchant free-listing eligibility and the "30-day returns" badge. Emits
 * nothing unless explicitly enabled, and omits individual fields that are not configured so a
 * partially-configured store still produces valid schema.
 *
 * Countries: Google reads up to 50 as `applicableCountry`, and has no value for every country. A
 * worldwide policy lists none, and the policy page link (`merchantReturnLink`) is what Google reads
 * instead.
 */
class ReturnPolicyEnricher implements OfferEnricherInterface
{
    /**
     * The most countries Google reads in applicableCountry ("You can specify up to 50 countries").
     */
    public const MAX_COUNTRIES = 50;

    private const XML_ENABLED         = Config::XML_RETURN_POLICY_ENABLED;
    private const XML_WORLDWIDE       = 'mageos_seo_merchant/return/worldwide';
    private const XML_COUNTRY         = 'mageos_seo_merchant/return/applicable_country';
    private const XML_POLICY_CATEGORY = 'mageos_seo_merchant/return/policy_category';
    private const XML_DAYS            = 'mageos_seo_merchant/return/days';
    private const XML_METHOD          = 'mageos_seo_merchant/return/method';
    private const XML_FEES            = 'mageos_seo_merchant/return/fees';
    private const XML_REFUND_TYPE     = 'mageos_seo_merchant/return/refund_type';

    private const FINITE_WINDOW = 'https://schema.org/MerchantReturnFiniteReturnWindow';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param CountryList $countryList
     * @param Config $seoConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CountryList          $countryList,
        private readonly Config               $seoConfig
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

        $policy = ['@type' => 'MerchantReturnPolicy'];

        $countries = $this->countries($storeId);
        if ($countries !== []) {
            // One country as a value, as in Google's examples; several as an array.
            $policy['applicableCountry'] = \count($countries) === 1 ? $countries[0] : $countries;
        }

        $category = $this->value(self::XML_POLICY_CATEGORY, $storeId);
        if ($category !== '') {
            $policy['returnPolicyCategory'] = $category;
            // merchantReturnDays only applies to a finite return window.
            if ($category === self::FINITE_WINDOW) {
                $days = (int) $this->value(self::XML_DAYS, $storeId);
                if ($days > 0) {
                    $policy['merchantReturnDays'] = $days;
                }
            }
        }

        $optionalFields = [
            'returnMethod' => self::XML_METHOD,
            'returnFees'   => self::XML_FEES,
            'refundType'   => self::XML_REFUND_TYPE,
        ];
        foreach ($optionalFields as $key => $path) {
            $value = $this->value($path, $storeId);
            if ($value !== '') {
                $policy[$key] = $value;
            }
        }

        $url = $this->seoConfig->getReturnPolicyUrl($storeId);
        if ($url !== null) {
            $policy['merchantReturnLink'] = $url;
        }

        return ['hasMerchantReturnPolicy' => $policy];
    }

    /**
     * @inheritdoc
     */
    public function getSortOrder(): int
    {
        return 100;
    }

    /**
     * The countries to list: none when the policy applies worldwide, otherwise the first 50 chosen.
     *
     * A worldwide policy ignores countries still stored from before it was set. Above 50, the save
     * told the admin that only the first 50 in the list are output (Config\Backend\ReturnCountries).
     *
     * @param int $storeId
     * @return string[]
     */
    private function countries(int $storeId): array
    {
        if ($this->scopeConfig->isSetFlag(self::XML_WORLDWIDE, ScopeInterface::SCOPE_STORE, $storeId)) {
            return [];
        }

        return \array_slice(
            $this->countryList->fromConfig($this->value(self::XML_COUNTRY, $storeId)),
            0,
            self::MAX_COUNTRIES
        );
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
