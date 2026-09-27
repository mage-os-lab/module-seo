<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\Seo\Model\Config;
use PHPUnit\Framework\TestCase;

/**
 * `llms_txt/faq_groups`: the FAQ groups the llms documents include, stored as a multi-select's
 * comma-separated list. None selected must read as none, not as a default.
 */
class ConfigLlmsFaqGroupsTest extends TestCase
{
    public function testTheSelectedGroupsInOrder(): void
    {
        $this->assertSame(['global', 'shipping'], $this->config('global,shipping')->getLlmsFaqGroups(1));
    }

    public function testEntriesAreTrimmedAndEmptyOnesDropped(): void
    {
        $this->assertSame(['global', 'returns'], $this->config(' global, ,returns ')->getLlmsFaqGroups(1));
    }

    public function testNoneSelectedIsEmpty(): void
    {
        $this->assertSame([], $this->config('')->getLlmsFaqGroups(1));
        $this->assertSame([], $this->config(null)->getLlmsFaqGroups(1));
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
