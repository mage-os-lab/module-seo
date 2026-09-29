<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Cache;

use MageOS\Seo\Model\Cache\CleaningMode;
use PHPUnit\Framework\TestCase;

class CleaningModeTest extends TestCase
{
    public function testResolvesTheMatchingAnyTagIdentifier(): void
    {
        // 'matchingAnyTag' is what cache backends expect on every supported Magento version:
        // \Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG originally, restated by
        // Magento\Framework\Cache\CacheConstants on 2.4.9+. Naming either class would tie the
        // module to one end of the supported range, so the value is asserted directly.
        $this->assertSame('matchingAnyTag', (new CleaningMode())->matchingAnyTag());
    }
}
