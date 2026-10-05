<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Rebuild;

use Magento\Framework\FlagManager;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use MageOS\Seo\Model\Rebuild\RegenerationRequester;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RegenerationRequesterTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testRequestPublishesAndRecordsWhenItWasQueued(): void
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willReturn(null);
        $flagManager->expects($this->once())->method('saveFlag')
            ->with('mageos_seo_feed_pending_llms', self::NOW);
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())->method('publish')
            ->with(RegenerationRequester::TOPIC, 'llms');

        $this->requester($flagManager, $publisher)->request('llms');
    }

    public function testDuplicateRequestsAreCollapsedWhilePending(): void
    {
        // A burst of invalidations must queue at most one build per group.
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')
            ->willReturn(self::NOW - RegenerationRequester::STALE_AFTER_SECONDS + 1);
        $flagManager->expects($this->never())->method('saveFlag');
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $this->requester($flagManager, $publisher)->request('jsonl');
    }

    public function testAStaleRequestIsQueuedAgainWithAWarning(): void
    {
        // A request nobody picked up within the cutoff (consumer not running, message
        // lost) must not block event-driven rebuilds forever.
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')
            ->willReturn(self::NOW - RegenerationRequester::STALE_AFTER_SECONDS);
        $flagManager->expects($this->once())->method('saveFlag')
            ->with('mageos_seo_feed_pending_jsonl', self::NOW);
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())->method('publish')
            ->with(RegenerationRequester::TOPIC, 'jsonl');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('mageosSeoFeedRegenerate consumer'));

        $this->requester($flagManager, $publisher, $logger)->request('jsonl');
    }

    public function testAnUnreadablePendingFlagIsTreatedAsStale(): void
    {
        $flagManager = $this->createStub(FlagManager::class);
        $flagManager->method('getFlagData')->willReturn('not-a-timestamp');
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->once())->method('publish');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('an unknown time'));

        $this->requester($flagManager, $publisher, $logger)->request('llms');
    }

    public function testPublishFailureReleasesTheFlagAndIsLoggedNotThrown(): void
    {
        // Feed freshness must never break a save or a frontend request, and a request that
        // was never queued must not block the next one.
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willReturn(null);
        $flagManager->expects($this->once())->method('saveFlag');
        $flagManager->expects($this->once())->method('deleteFlag')->with('mageos_seo_feed_pending_jsonl');
        $publisher = $this->createStub(PublisherInterface::class);
        $publisher->method('publish')->willThrowException(new \RuntimeException('queue down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->requester($flagManager, $publisher, $logger)->request('jsonl');
    }

    public function testAcknowledgeClearsThePendingFlag(): void
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->expects($this->once())->method('deleteFlag')
            ->with('mageos_seo_feed_pending_llms');

        $this->requester($flagManager)->acknowledge('llms');
    }

    public function testAcknowledgeFailureIsLoggedNotThrown(): void
    {
        $flagManager = $this->createStub(FlagManager::class);
        $flagManager->method('deleteFlag')->willThrowException(new \RuntimeException('db down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->requester($flagManager, null, $logger)->acknowledge('llms');
    }

    /**
     * Build the requester at a fixed point in time; collaborators a test does not pass are stubs.
     *
     * @param FlagManager $flagManager
     * @param PublisherInterface|null $publisher
     * @param LoggerInterface|null $logger
     * @param ProblemLog|null $problemLog
     * @return RegenerationRequester
     */
    private function requester(
        FlagManager $flagManager,
        ?PublisherInterface $publisher = null,
        ?LoggerInterface $logger = null,
        ?ProblemLog $problemLog = null
    ): RegenerationRequester {
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);

        return new RegenerationRequester(
            $flagManager,
            $publisher ?? $this->createStub(PublisherInterface::class),
            $dateTime,
            $logger ?? $this->createStub(LoggerInterface::class),
            $problemLog ?? $this->createStub(ProblemLog::class)
        );
    }

    /**
     * A request still pending after an hour means the consumer is not taking messages: the admin
     * is told, besides the log.
     */
    public function testAStaleRequestShowsTheQueueAsStalled(): void
    {
        $flagManager = $this->createStub(FlagManager::class);
        $flagManager->method('getFlagData')->willReturn(self::NOW - RegenerationRequester::STALE_AFTER_SECONDS);
        $problemLog = $this->createMock(ProblemLog::class);
        $problemLog->expects($this->once())->method('stalled')
            ->with('llms', self::NOW - RegenerationRequester::STALE_AFTER_SECONDS);

        $this->requester($flagManager, problemLog: $problemLog)->request('llms');
    }

    public function testAStalledRequestWithAnUnreadableQueuedTimeSaysItIsUnknown(): void
    {
        $flagManager = $this->createStub(FlagManager::class);
        $flagManager->method('getFlagData')->willReturn('not-a-timestamp');
        $problemLog = $this->createMock(ProblemLog::class);
        $problemLog->expects($this->once())->method('stalled')->with('llms', null);

        $this->requester($flagManager, problemLog: $problemLog)->request('llms');
    }

    public function testAFreshRequestShowsNothing(): void
    {
        $flagManager = $this->createStub(FlagManager::class);
        $flagManager->method('getFlagData')->willReturn(self::NOW - 60);
        $problemLog = $this->createMock(ProblemLog::class);
        $problemLog->expects($this->never())->method('stalled');

        $this->requester($flagManager, problemLog: $problemLog)->request('llms');
    }
}
