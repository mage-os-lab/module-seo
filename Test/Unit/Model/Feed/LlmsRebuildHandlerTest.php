<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Feed;

use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\LlmsInvalidationPolicy;
use MageOS\Seo\Model\Feed\LlmsRebuildHandler;
use PHPUnit\Framework\TestCase;

class LlmsRebuildHandlerTest extends TestCase
{
    /**
     * @return void
     */
    public function testItOwnsTheLlmsGroups(): void
    {
        $this->assertSame(['llms', 'jsonl'], $this->handler()->getGroups());
    }

    /**
     * @return void
     */
    public function testWhetherAGroupIsEnabledIsThePolicysCall(): void
    {
        $policy = $this->createStub(LlmsInvalidationPolicy::class);
        $policy->method('isGroupEnabled')->willReturnCallback(
            static fn (string $group): bool => $group === FeedRegenerator::GROUP_JSONL
        );

        $handler = $this->handler(policy: $policy);

        $this->assertTrue($handler->isEnabled(FeedRegenerator::GROUP_JSONL));
        $this->assertFalse($handler->isEnabled(FeedRegenerator::GROUP_LLMS));
    }

    /**
     * @return void
     */
    public function testARebuildIsTheRegeneratorsAndItsFailuresAreReturned(): void
    {
        $regenerator = $this->createMock(FeedRegenerator::class);
        $regenerator->expects($this->exactly(2))->method('regenerate')
            ->willReturnCallback(static fn (?string $group): array => $group === null ? [] : [2 => 'disk full']);

        $handler = $this->handler($regenerator);

        $this->assertSame([2 => 'disk full'], $handler->rebuild(FeedRegenerator::GROUP_LLMS));
        $this->assertSame([], $handler->rebuild(null));
    }

    /**
     * @param FeedRegenerator|null $regenerator
     * @param LlmsInvalidationPolicy|null $policy
     * @return LlmsRebuildHandler
     */
    private function handler(
        ?FeedRegenerator $regenerator = null,
        ?LlmsInvalidationPolicy $policy = null
    ): LlmsRebuildHandler {
        return new LlmsRebuildHandler(
            $regenerator ?? $this->createStub(FeedRegenerator::class),
            $policy ?? $this->createStub(LlmsInvalidationPolicy::class)
        );
    }
}
