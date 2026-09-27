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
        // The literals pin the stored values, which toOptionArray() takes from the constants.
        $this->assertSame(
            [
                ['value' => 'mageos_seo', 'label' => 'MageOS SEO'],
                ['value' => 'magento', 'label' => 'Magento'],
            ],
            (new SitemapGenerator())->toOptionArray()
        );
    }
}
