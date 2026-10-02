<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Product\OfferEnricher;

use MageOS\Seo\Model\Product\OfferEnricher\CountryList;
use PHPUnit\Framework\TestCase;

/**
 * The country codes a multiselect stored, as the enrichers output them.
 */
class CountryListTest extends TestCase
{
    public function testTheStoredCodesComeBackInOrder(): void
    {
        $this->assertSame(['DE', 'AT', 'CH'], (new CountryList())->fromConfig('DE,AT,CH'));
    }

    public function testBlanksSpacesAndRepeatsAreDropped(): void
    {
        $this->assertSame(['DE', 'AT', 'CH'], (new CountryList())->fromConfig(' DE,,AT , DE,CH,'));
    }

    public function testNothingStoredIsNoCountries(): void
    {
        $this->assertSame([], (new CountryList())->fromConfig(''));
    }
}
