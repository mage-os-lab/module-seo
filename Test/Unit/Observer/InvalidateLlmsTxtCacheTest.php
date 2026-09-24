<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\Seo\Model\Feed\FeedInvalidator;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\InvalidationPolicy;
use MageOS\Seo\Observer\InvalidateLlmsJsonlCache;
use MageOS\Seo\Observer\InvalidateLlmsTxtCache;
use PHPUnit\Framework\TestCase;

/**
 * The two feed invalidation observers: each asks the policy about its own feed group.
 */
class InvalidateLlmsTxtCacheTest extends TestCase
{
    public function testLlmsObserverInvalidatesOnlyRelevantChanges(): void
    {
        $this->assertObserver(InvalidateLlmsTxtCache::class, FeedRegenerator::GROUP_LLMS, 'invalidateLlms');
    }

    public function testJsonlObserverInvalidatesOnlyRelevantChanges(): void
    {
        $this->assertObserver(InvalidateLlmsJsonlCache::class, FeedRegenerator::GROUP_JSONL, 'invalidateJsonl');
    }

    /**
     * Run an observer once with a relevant and once with an irrelevant change.
     *
     * @param class-string<ObserverInterface> $observerClass
     * @param string $group
     * @param non-empty-string $invalidateMethod
     * @return void
     */
    private function assertObserver(string $observerClass, string $group, string $invalidateMethod): void
    {
        $event = new Event(['name' => 'some_event']);
        foreach ([[true, 1], [false, 0]] as [$relevant, $expectedCalls]) {
            $policy = $this->createMock(InvalidationPolicy::class);
            $policy->expects($this->once())->method('isRelevantChange')
                ->with($group, $event)
                ->willReturn($relevant);
            $invalidator = $this->createMock(FeedInvalidator::class);
            $invalidator->expects($this->exactly($expectedCalls))->method($invalidateMethod);

            (new $observerClass($invalidator, $policy))->execute(new Observer(['event' => $event]));
        }
    }
}
