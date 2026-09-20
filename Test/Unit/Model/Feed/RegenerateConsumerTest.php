<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Feed;

use MageOS\Seo\Exception\FeedRebuildInProgressException;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\RegenerateConsumer;
use MageOS\Seo\Model\Feed\RegenerationRequester;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RegenerateConsumerTest extends TestCase
{
    /**
     * @var FeedRegenerator&MockObject
     */
    private FeedRegenerator&MockObject $regenerator;

    /**
     * @var RegenerationRequester&MockObject
     */
    private RegenerationRequester&MockObject $requester;

    /**
     * @var LoggerInterface&MockObject
     */
    private LoggerInterface&MockObject $logger;

    private RegenerateConsumer $consumer;

    protected function setUp(): void
    {
        $this->regenerator = $this->createMock(FeedRegenerator::class);
        $this->requester   = $this->createMock(RegenerationRequester::class);
        $this->logger      = $this->createMock(LoggerInterface::class);

        $this->consumer = new RegenerateConsumer($this->regenerator, $this->requester, $this->logger);
    }

    public function testProcessAcknowledgesBeforeRegenerating(): void
    {
        // The flag must clear before the build so invalidations arriving mid-build
        // queue exactly one follow-up rebuild instead of being lost.
        $calls = [];
        $this->requester->method('acknowledge')->willReturnCallback(
            function () use (&$calls): void {
                $calls[] = 'acknowledge';
            }
        );
        $this->regenerator->method('regenerate')->willReturnCallback(
            function () use (&$calls): array {
                $calls[] = 'regenerate';
                return [];
            }
        );

        $this->consumer->process(FeedRegenerator::GROUP_LLMS);

        $this->assertSame(['acknowledge', 'regenerate'], $calls);
    }

    public function testProcessPassesTheGroupToTheRegenerator(): void
    {
        $this->regenerator
            ->expects($this->once())
            ->method('regenerate')
            ->with(FeedRegenerator::GROUP_HREFLANG);

        $this->consumer->process(FeedRegenerator::GROUP_HREFLANG);
    }

    public function testUnknownGroupIsRejectedWithoutBuilding(): void
    {
        $this->logger->expects($this->once())->method('warning');
        $this->requester->expects($this->never())->method('acknowledge');
        $this->regenerator->expects($this->never())->method('regenerate');

        $this->consumer->process('not-a-feed-group');
    }

    public function testLosingTheRebuildLockPutsTheRequestBack(): void
    {
        // acknowledge() has already cleared the pending flag by this point, so dropping the
        // message would lose the invalidation: the build holding the lock may have passed the
        // data this message was about before the message existed.
        $this->regenerator->method('regenerate')
            ->willThrowException(new FeedRebuildInProgressException(__('already running')));

        $this->requester->expects($this->once())
            ->method('request')
            ->with(FeedRegenerator::GROUP_LLMS);

        $this->consumer->process(FeedRegenerator::GROUP_LLMS);
    }

    public function testLosingTheRebuildLockIsNotAnError(): void
    {
        // Another process doing the same work is expected, not a fault: nothing is logged at
        // error level and nothing is thrown at the queue.
        $this->regenerator->method('regenerate')
            ->willThrowException(new FeedRebuildInProgressException(__('already running')));
        $this->logger->expects($this->never())->method('error');
        $this->logger->expects($this->once())->method('info');

        $this->consumer->process(FeedRegenerator::GROUP_LLMS);
    }
}
