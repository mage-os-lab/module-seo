<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\OfferEnricher;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\OfferEnricher\CountryList;
use MageOS\Seo\Model\Product\OfferEnricher\ReturnPolicyEnricher;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ReturnPolicyEnricherTest extends TestCase
{
    /**
     * @var ScopeConfigInterface&Stub
     */
    private ScopeConfigInterface&Stub $scopeConfig;

    /**
     * @var ProductInterface&Stub
     */
    private ProductInterface&Stub $product;

    /**
     * @var ReturnPolicyEnricher
     */
    private ReturnPolicyEnricher $enricher;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $this->product     = $this->createStub(ProductInterface::class);
        $this->enricher    = new ReturnPolicyEnricher(
            $this->scopeConfig,
            new CountryList(),
            new Config($this->scopeConfig)
        );
    }

    /**
     * Enabled answers the enabled flag; every other flag and value comes from $values.
     *
     * @param array<string, string> $values
     */
    private function stubConfig(bool $enabled, array $values): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => $path === 'mageos_seo_merchant/return/enabled'
                ? $enabled
                : ($values[$path] ?? '') === '1'
        );
        $this->scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $values[$path] ?? ''
        );
    }

    public function testSortOrderIsHundred(): void
    {
        $this->assertSame(100, $this->enricher->getSortOrder());
    }

    public function testReturnsEmptyWhenDisabled(): void
    {
        $this->stubConfig(false, []);
        $this->assertSame([], $this->enricher->enrich($this->product, 1));
    }

    public function testBuildsFullPolicy(): void
    {
        $this->stubConfig(true, [
            'mageos_seo_merchant/return/applicable_country' => 'GB',
            'mageos_seo_merchant/return/policy_category'    => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'mageos_seo_merchant/return/days'               => '30',
            'mageos_seo_merchant/return/method'             => 'https://schema.org/ReturnByMail',
            'mageos_seo_merchant/return/fees'               => 'https://schema.org/FreeReturn',
            'mageos_seo_merchant/return/refund_type'        => 'https://schema.org/FullRefund',
            'mageos_seo_merchant/return/policy_url'         => 'https://example.com/returns',
        ]);

        $policy = $this->enricher->enrich($this->product, 1)['hasMerchantReturnPolicy'];

        $this->assertSame('MerchantReturnPolicy', $policy['@type']);
        $this->assertSame('GB', $policy['applicableCountry']);
        $this->assertSame(30, $policy['merchantReturnDays']);
        $this->assertSame('https://schema.org/ReturnByMail', $policy['returnMethod']);
        $this->assertSame('https://schema.org/FreeReturn', $policy['returnFees']);
        $this->assertSame('https://schema.org/FullRefund', $policy['refundType']);
        $this->assertSame('https://example.com/returns', $policy['merchantReturnLink']);
    }

    public function testReturnDaysOmittedWhenZero(): void
    {
        $this->stubConfig(true, [
            'mageos_seo_merchant/return/policy_category' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'mageos_seo_merchant/return/days'            => '0',
        ]);
        $policy = $this->enricher->enrich($this->product, 1)['hasMerchantReturnPolicy'];
        $this->assertArrayNotHasKey('merchantReturnDays', $policy);
    }

    public function testReturnDaysOmittedWhenNotFiniteWindow(): void
    {
        $this->stubConfig(true, [
            'mageos_seo_merchant/return/policy_category' => 'https://schema.org/MerchantReturnUnlimitedWindow',
            'mageos_seo_merchant/return/days'            => '30',
        ]);
        $policy = $this->enricher->enrich($this->product, 1)['hasMerchantReturnPolicy'];
        $this->assertArrayNotHasKey('merchantReturnDays', $policy);
    }

    public function testMinimalPolicyWhenOnlyEnabled(): void
    {
        $this->stubConfig(true, []);
        $policy = $this->enricher->enrich($this->product, 1)['hasMerchantReturnPolicy'];
        $this->assertSame(['@type' => 'MerchantReturnPolicy'], $policy);
    }

    /**
     * Google's return-policy reference: "You can specify up to 50 countries", as an array.
     */
    public function testSeveralCountriesAreAList(): void
    {
        $this->stubConfig(true, ['mageos_seo_merchant/return/applicable_country' => 'DE,AT,CH']);

        $policy = $this->enricher->enrich($this->product, 1)['hasMerchantReturnPolicy'];

        $this->assertSame(['DE', 'AT', 'CH'], $policy['applicableCountry']);
    }

    /**
     * Google reads at most 50; the save tells the admin which are output.
     */
    public function testOnlyTheFirstFiftyCountriesAreOutput(): void
    {
        $codes = array_map(static fn (int $i): string => \sprintf('C%02d', $i), range(1, 51));
        $this->stubConfig(true, ['mageos_seo_merchant/return/applicable_country' => implode(',', $codes)]);

        $policy = $this->enricher->enrich($this->product, 1)['hasMerchantReturnPolicy'];

        $this->assertSame(\array_slice($codes, 0, 50), $policy['applicableCountry']);
    }

    /**
     * Google has no value for every country. Worldwide lists none, even if some are still stored from
     * before, and the policy page link is what Google reads instead.
     */
    public function testWorldwideListsNoCountriesAndKeepsThePolicyLink(): void
    {
        $this->stubConfig(true, [
            'mageos_seo_merchant/return/worldwide'          => '1',
            'mageos_seo_merchant/return/applicable_country' => 'GB',
            'mageos_seo_merchant/return/policy_url'         => 'https://example.com/returns',
        ]);

        $policy = $this->enricher->enrich($this->product, 1)['hasMerchantReturnPolicy'];

        $this->assertArrayNotHasKey('applicableCountry', $policy);
        $this->assertSame('https://example.com/returns', $policy['merchantReturnLink']);
    }
}
