<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Rebuild;

use MageOS\Seo\Api\Rebuild\GroupHandlerInterface;
use MageOS\Seo\Exception\RebuildInProgressException;
use MageOS\Seo\Exception\SitemapRebuildInProgressException;
use MageOS\Seo\Model\Rebuild\BuildFreshness;
use MageOS\Seo\Model\Rebuild\HandlerPool;
use MageOS\Seo\Model\Rebuild\Pause;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use MageOS\Seo\Model\Rebuild\RegenerateConsumer;
use MageOS\Seo\Model\Rebuild\RegenerationRequester;
use MageOS\Seo\Model\Sitemap\Rebuilder as SitemapRebuilder;
use MageOS\Seo\Model\Sitemap\RebuildGroup;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RegenerateConsumerTest extends TestCase
{
    /**
     * A registered handler, owning the groups `one` and `two`.
     *
     * @var GroupHandlerInterface&Stub
     */
    private GroupHandlerInterface&Stub $handler;

    /**
     * @var RegenerationRequester&Stub
     */
    private RegenerationRequester&Stub $requester;

    /**
     * @var LoggerInterface&Stub
     */
    private LoggerInterface&Stub $logger;

    private RegenerateConsumer $consumer;

    protected function setUp(): void
    {
        $this->handler   = $this->createStub(GroupHandlerInterface::class);
        $this->handler->method('getGroups')->willReturn(['one', 'two']);
        $this->requester = $this->createStub(RegenerationRequester::class);
        $this->logger    = $this->createStub(LoggerInterface::class);

        $this->consumer = $this->consumer();
    }

    public function testASitemapGroupRebuildsThatTypeAndNoHandlerGroup(): void
    {
        $rebuilder = $this->createMock(SitemapRebuilder::class);
        $rebuilder->method('hasType')->willReturn(true);
        $rebuilder->expects($this->once())->method('rebuild')->with('products')->willReturn([]);
        $handler = $this->handlerMock();
        $handler->expects($this->never())->method('rebuild');
        $requester = $this->createMock(RegenerationRequester::class);
        $requester->expects($this->once())->method('acknowledge')->with('sitemap-products');

        $this->consumer($rebuilder, $handler, $requester)->process('sitemap-products');
    }

    public function testTheFirstBuildGroupWritesTheMissingSitemapsAndNothingElse(): void
    {
        $rebuilder = $this->createMock(SitemapRebuilder::class);
        $rebuilder->expects($this->once())->method('buildMissing')->willReturn([]);
        $rebuilder->expects($this->never())->method('rebuild');
        $handler = $this->handlerMock();
        $handler->expects($this->never())->method('rebuild');
        $requester = $this->createMock(RegenerationRequester::class);
        $requester->expects($this->once())->method('acknowledge')->with(RebuildGroup::MISSING);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $this->consumer($rebuilder, $handler, $requester, $logger)->process(RebuildGroup::MISSING);
    }

    public function testAFirstBuildBeingWrittenElsewherePutsTheRequestBack(): void
    {
        $rebuilder = $this->createStub(SitemapRebuilder::class);
        $rebuilder->method('buildMissing')->willThrowException(new SitemapRebuildInProgressException(__('busy')));
        $requester = $this->createMock(RegenerationRequester::class);
        $requester->expects($this->once())->method('request')->with(RebuildGroup::MISSING);
        $handler = $this->handlerMock();
        $handler->expects($this->never())->method('rebuild');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $this->consumer($rebuilder, $handler, $requester, $logger)->process(RebuildGroup::MISSING);
    }

    public function testAHandlerGroupRebuildsNoSitemap(): void
    {
        $rebuilder = $this->createMock(SitemapRebuilder::class);
        $rebuilder->expects($this->never())->method('rebuild');
        $rebuilder->expects($this->never())->method('buildMissing');

        $this->consumer($rebuilder)->process('two');
    }

    public function testASitemapTypeNoProviderHasIsRejectedWithoutBuilding(): void
    {
        $rebuilder = $this->createMock(SitemapRebuilder::class);
        $rebuilder->method('hasType')->willReturn(false);
        $rebuilder->expects($this->never())->method('rebuild');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $requester = $this->createMock(RegenerationRequester::class);
        $requester->expects($this->never())->method('acknowledge');

        $this->consumer($rebuilder, requester: $requester, logger: $logger)->process('sitemap-nothing-lists-this');
    }

    public function testASitemapBeingWrittenElsewherePutsTheRequestBack(): void
    {
        $rebuilder = $this->createStub(SitemapRebuilder::class);
        $rebuilder->method('hasType')->willReturn(true);
        $rebuilder->method('rebuild')->willThrowException(new SitemapRebuildInProgressException(__('being written')));
        $requester = $this->createMock(RegenerationRequester::class);
        $requester->expects($this->once())->method('request')->with('sitemap-pages');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $this->consumer($rebuilder, requester: $requester, logger: $logger)->process('sitemap-pages');
    }

    public function testProcessAcknowledgesBeforeRebuilding(): void
    {
        // The flag must clear before the build so invalidations arriving mid-build
        // queue exactly one follow-up rebuild instead of being lost.
        $calls = [];
        $this->requester->method('acknowledge')->willReturnCallback(
            function () use (&$calls): void {
                $calls[] = 'acknowledge';
            }
        );
        $this->handler->method('rebuild')->willReturnCallback(
            function () use (&$calls): array {
                $calls[] = 'rebuild';
                return [];
            }
        );

        $this->consumer->process('one');

        $this->assertSame(['acknowledge', 'rebuild'], $calls);
    }

    public function testProcessPassesTheGroupToItsHandler(): void
    {
        $handler = $this->handlerMock();
        $handler
            ->expects($this->once())
            ->method('rebuild')
            ->with('two');

        $this->consumer(handler: $handler)->process('two');
    }

    public function testUnknownGroupIsRejectedWithoutBuilding(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');
        $requester = $this->createMock(RegenerationRequester::class);
        $requester->expects($this->never())->method('acknowledge');
        $handler = $this->handlerMock();
        $handler->expects($this->never())->method('rebuild');

        $this->consumer(handler: $handler, requester: $requester, logger: $logger)->process('not-a-group');
    }

    public function testLosingTheRebuildLockPutsTheRequestBack(): void
    {
        // acknowledge() has already cleared the pending flag by this point, so dropping the
        // message would lose the invalidation: the build holding the lock may have passed the
        // data this message was about before the message existed.
        $this->handler->method('rebuild')
            ->willThrowException(new RebuildInProgressException(__('already running')));

        $requester = $this->createMock(RegenerationRequester::class);
        $requester->expects($this->once())
            ->method('request')
            ->with('one');

        $this->consumer(requester: $requester)->process('one');
    }

    public function testLosingTheRebuildLockIsNotAnError(): void
    {
        // Another process doing the same work is expected, not a fault: nothing is logged at
        // error level and nothing is thrown at the queue.
        $this->handler->method('rebuild')
            ->willThrowException(new RebuildInProgressException(__('already running')));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');
        $logger->expects($this->once())->method('info');

        $this->consumer(logger: $logger)->process('one');
    }

    /**
     * The consumer over the given doubles, or this test's stubs and a stub sitemap rebuilder.
     *
     * @param SitemapRebuilder|null $rebuilder
     * @param GroupHandlerInterface|null $handler
     * @param RegenerationRequester|null $requester
     * @param LoggerInterface|null $logger
     * @param ProblemLog|null $problemLog
     * @return RegenerateConsumer
     */
    private function consumer(
        ?SitemapRebuilder $rebuilder = null,
        ?GroupHandlerInterface $handler = null,
        ?RegenerationRequester $requester = null,
        ?LoggerInterface $logger = null,
        ?ProblemLog $problemLog = null,
        ?BuildFreshness $freshness = null,
        ?Pause $pause = null,
        int $lockWaitSeconds = 0
    ): RegenerateConsumer {
        return new RegenerateConsumer(
            new HandlerPool([$handler ?? $this->handler]),
            $requester ?? $this->requester,
            $logger ?? $this->logger,
            $rebuilder ?? $this->createStub(SitemapRebuilder::class),
            new RebuildGroup(),
            $problemLog ?? $this->createStub(ProblemLog::class),
            $freshness ?? $this->createStub(BuildFreshness::class),
            $pause ?? $this->createStub(Pause::class),
            $lockWaitSeconds,
            15
        );
    }

    /**
     * Review H4: a build another process is running is waited for, not re-queued at once — a
     * re-queued message is taken again at once by a free consumer, which spins.
     *
     * @return void
     */
    public function testARunningRebuildIsWaitedForThenBuilt(): void
    {
        $attempts = 0;
        $handler  = $this->handlerMock();
        $handler->expects($this->exactly(2))->method('rebuild')->willReturnCallback(
            static function () use (&$attempts): array {
                if (++$attempts === 1) {
                    throw new RebuildInProgressException(__('busy'));
                }
                return [];
            }
        );
        $pause = $this->createMock(Pause::class);
        $pause->expects($this->once())->method('seconds')->with(15);
        $requester = $this->createMock(RegenerationRequester::class);
        $requester->expects($this->never())->method('request');

        $this->consumer(null, $handler, $requester, pause: $pause, lockWaitSeconds: 600)->process('one');
    }

    /**
     * A wait that runs out puts the request back, so the invalidation is not lost.
     *
     * @return void
     */
    public function testAWaitThatRunsOutPutsTheRequestBack(): void
    {
        $this->handler->method('rebuild')->willThrowException(new RebuildInProgressException(__('busy')));
        $pause = $this->createMock(Pause::class);
        $pause->expects($this->exactly(2))->method('seconds')->with(15);
        $requester = $this->createMock(RegenerationRequester::class);
        $requester->expects($this->once())->method('request')->with('one');

        $this->consumer(requester: $requester, pause: $pause, lockWaitSeconds: 30)->process('one');
    }

    /**
     * Module-aeo #9: each build starts from current data, before the pending flag is cleared.
     *
     * @return void
     */
    public function testEachBuildStartsFromCurrentData(): void
    {
        $calls     = [];
        $freshness = $this->createMock(BuildFreshness::class);
        $freshness->expects($this->once())->method('refresh')->willReturnCallback(
            static function () use (&$calls): void {
                $calls[] = 'refresh';
            }
        );
        $requester = $this->createMock(RegenerationRequester::class);
        $requester->expects($this->once())->method('acknowledge')->willReturnCallback(
            static function () use (&$calls): void {
                $calls[] = 'acknowledge';
            }
        );

        $this->consumer(requester: $requester, freshness: $freshness)->process('one');

        $this->assertSame(['refresh', 'acknowledge'], $calls);
    }

    public function testWhatTheRebuildFoundBecomesTheGroupsProblems(): void
    {
        $this->handler->method('rebuild')->willReturn([3 => 'Disk full']);
        $problemLog = $this->createMock(ProblemLog::class);
        $problemLog->expects($this->once())->method('rebuilding')->with('one');
        $problemLog->expects($this->once())->method('rebuilt')->with('one', [3 => 'Disk full']);

        $this->consumer(problemLog: $problemLog)->process('one');
    }

    public function testARebuildRefusedAsAlreadyRunningRecordsNothing(): void
    {
        $this->handler->method('rebuild')->willThrowException(new RebuildInProgressException(__('Busy')));
        $problemLog = $this->createMock(ProblemLog::class);
        $problemLog->expects($this->once())->method('abandoned')->with('one');
        $problemLog->expects($this->never())->method('rebuilt');

        $this->consumer(problemLog: $problemLog)->process('one');
    }

    public function testARebuildThatThrowsIsAProblemForTheWholeGroup(): void
    {
        $this->handler->method('rebuild')->willThrowException(new \RuntimeException('Out of memory'));
        $problemLog = $this->createMock(ProblemLog::class);
        $problemLog->expects($this->once())->method('rebuilt')->with('one', [ProblemLog::ALL => 'Out of memory']);

        $this->expectException(\RuntimeException::class);
        $this->consumer(problemLog: $problemLog)->process('one');
    }

    public function testTakingAMessageMeansTheQueueIsProcessedAgain(): void
    {
        $problemLog = $this->createMock(ProblemLog::class);
        $problemLog->expects($this->once())->method('resumed');

        $this->consumer(problemLog: $problemLog)->process('one');
    }

    /**
     * The handler as a mock, owning the same groups, for a test that verifies what it rebuilds.
     *
     * @return GroupHandlerInterface&MockObject
     */
    private function handlerMock(): GroupHandlerInterface&MockObject
    {
        $handler = $this->createMock(GroupHandlerInterface::class);
        $handler->method('getGroups')->willReturn(['one', 'two']);

        return $handler;
    }
}
