<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer;

use Magento\CacheInvalidate\Model\PurgeCache;
use Magento\Framework\Event\Observer;
use Magento\PageCache\Model\Config;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use MageOS\Seo\Observer\InvalidateLlmsTxtCache;

class InvalidateLlmsTxtCacheTest extends TestCase
{
    /**
     * @var Config&MockObject
     */
    private Config&MockObject $config;

    /**
     * @var PurgeCache&MockObject
     */
    private PurgeCache&MockObject $purgeCache;

    private InvalidateLlmsTxtCache $observer;

    protected function setUp(): void
    {
        $this->config     = $this->createMock(Config::class);
        $this->purgeCache = $this->createMock(PurgeCache::class);
        $this->observer   = new InvalidateLlmsTxtCache($this->config, $this->purgeCache);
    }

    public function testExecuteSendsPurgeRequestWhenVarnishEnabled(): void
    {
        $this->config->method('getType')->willReturn((string) Config::VARNISH);
        $this->config->method('isEnabled')->willReturn(true);

        $expectedTags = [
            '((^|,)RS_LLMS(,|$))',
            '((^|,)RS_LLMS_FULL(,|$))',
        ];

        $this->purgeCache
            ->expects($this->once())
            ->method('sendPurgeRequest')
            ->with($expectedTags);

        $this->observer->execute($this->createMock(Observer::class));
    }

    public function testExecuteSkipsPurgeWhenVarnishNotEnabled(): void
    {
        $this->config->method('getType')->willReturn((string) Config::VARNISH);
        $this->config->method('isEnabled')->willReturn(false);

        $this->purgeCache->expects($this->never())->method('sendPurgeRequest');

        $this->observer->execute($this->createMock(Observer::class));
    }

    public function testExecuteSkipsPurgeWhenNotVarnishCacheType(): void
    {
        $this->config->method('getType')->willReturn((string) Config::BUILT_IN);
        $this->config->method('isEnabled')->willReturn(true);

        $this->purgeCache->expects($this->never())->method('sendPurgeRequest');

        $this->observer->execute($this->createMock(Observer::class));
    }
}
