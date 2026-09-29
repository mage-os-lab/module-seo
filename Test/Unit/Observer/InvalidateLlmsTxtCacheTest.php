<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\LlmsInvalidationPolicy;
use MageOS\Seo\Model\Rebuild\Invalidator;
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
        $this->assertObserver(InvalidateLlmsTxtCache::class, FeedRegenerator::GROUP_LLMS);
    }

    public function testJsonlObserverInvalidatesOnlyRelevantChanges(): void
    {
        $this->assertObserver(InvalidateLlmsJsonlCache::class, FeedRegenerator::GROUP_JSONL);
    }

    /**
     * Run an observer once with a relevant and once with an irrelevant change.
     *
     * @param class-string<ObserverInterface> $observerClass
     * @param string $group
     * @return void
     */
    private function assertObserver(string $observerClass, string $group): void
    {
        $event = new Event(['name' => 'some_event']);
        foreach ([[true, 1], [false, 0]] as [$relevant, $expectedCalls]) {
            $policy = $this->createMock(LlmsInvalidationPolicy::class);
            $policy->expects($this->once())->method('isRelevantChange')
                ->with($group, $event)
                ->willReturn($relevant);
            $invalidator = $this->createMock(Invalidator::class);
            $invalidator->expects($this->exactly($expectedCalls))->method('invalidate')->with($group);

            (new $observerClass($invalidator, $policy))->execute(new Observer(['event' => $event]));
        }
    }
}
