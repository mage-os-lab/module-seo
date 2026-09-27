<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Config\Source;

use MageOS\Seo\Model\Config\Source\SitemapGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The sitemap generators offered under Catalog → XML Sitemap: this module's, the default, then Magento's.
 */
class SitemapGeneratorTest extends TestCase
{
    public function testThisModulesGeneratorIsOfferedFirstThenMagentos(): void
    {
        $this->assertSame(
            [
                ['value' => 'mageos_seo', 'label' => 'MageOS SEO'],
                ['value' => 'magento', 'label' => 'Magento'],
            ],
            (new SitemapGenerator())->toOptionArray()
        );
    }

    public function testTheConstantsAreTheStoredValues(): void
    {
        $this->assertSame('mageos_seo', SitemapGenerator::MAGEOS_SEO);
        $this->assertSame('magento', SitemapGenerator::MAGENTO);
    }
}
