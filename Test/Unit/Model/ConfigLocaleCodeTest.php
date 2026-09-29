<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use MageOS\Seo\Model\Config;
use PHPUnit\Framework\TestCase;

/**
 * A store view's locale: `general/locale/code` at that store view's scope.
 */
class ConfigLocaleCodeTest extends TestCase
{
    public function testTheLocaleOfTheStoreViewAsked(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['general/locale/code', ScopeInterface::SCOPE_STORE, 1, 'en_GB'],
            ['general/locale/code', ScopeInterface::SCOPE_STORE, 2, 'nl_NL'],
        ]);
        $config = new Config($scopeConfig);

        $this->assertSame('en_GB', $config->getLocaleCode(1));
        $this->assertSame('nl_NL', $config->getLocaleCode(2));
    }

    public function testUnsetIsEmpty(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        $this->assertSame('', (new Config($scopeConfig))->getLocaleCode(1));
    }
}
