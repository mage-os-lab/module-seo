<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\Seo\Model\Config;
use PHPUnit\Framework\TestCase;

/**
 * `has_variant_max`: how many sellable children a configurable may have and still be a
 * ProductGroup. 0 must stay 0 — it turns variants off — rather than fall back to the default.
 */
class ConfigHasVariantMaxTest extends TestCase
{
    public function testTheConfiguredValue(): void
    {
        $this->assertSame(12, $this->config('12')->getHasVariantMax());
    }

    public function testZeroStaysZero(): void
    {
        $this->assertSame(0, $this->config('0')->getHasVariantMax());
    }

    public function testUnsetIsTheDefault(): void
    {
        $this->assertSame(50, $this->config(null)->getHasVariantMax());
        $this->assertSame(50, $this->config('')->getHasVariantMax());
    }

    public function testANegativeValueIsZero(): void
    {
        $this->assertSame(0, $this->config('-3')->getHasVariantMax());
    }

    /**
     * @param string|null $value
     * @return Config
     */
    private function config(?string $value): Config
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($value);

        return new Config($scopeConfig);
    }
}
