<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Feed;

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
}
