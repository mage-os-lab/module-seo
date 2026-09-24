<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Feed;

use Magento\Framework\FlagManager;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\RegenerationRequester;
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
            ->with(RegenerationRequester::TOPIC, FeedRegenerator::GROUP_LLMS);

        $this->requester($flagManager, $publisher)->request(FeedRegenerator::GROUP_LLMS);
    }

    public function testDuplicateRequestsAreCollapsedWhilePending(): void
    {
        // A burst of invalidations must queue at most one build per feed group.
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')
            ->willReturn(self::NOW - RegenerationRequester::STALE_AFTER_SECONDS + 1);
        $flagManager->expects($this->never())->method('saveFlag');
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');

        $this->requester($flagManager, $publisher)->request(FeedRegenerator::GROUP_JSONL);
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
            ->with(RegenerationRequester::TOPIC, FeedRegenerator::GROUP_JSONL);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('mageosSeoFeedRegenerate consumer'));

        $this->requester($flagManager, $publisher, $logger)->request(FeedRegenerator::GROUP_JSONL);
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

        $this->requester($flagManager, $publisher, $logger)->request(FeedRegenerator::GROUP_LLMS);
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

        $this->requester($flagManager, $publisher, $logger)->request(FeedRegenerator::GROUP_JSONL);
    }

    public function testAcknowledgeClearsThePendingFlag(): void
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->expects($this->once())->method('deleteFlag')
            ->with('mageos_seo_feed_pending_llms');

        $this->requester($flagManager)->acknowledge(FeedRegenerator::GROUP_LLMS);
    }

    public function testAcknowledgeFailureIsLoggedNotThrown(): void
    {
        $flagManager = $this->createStub(FlagManager::class);
        $flagManager->method('deleteFlag')->willThrowException(new \RuntimeException('db down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->requester($flagManager, null, $logger)->acknowledge(FeedRegenerator::GROUP_LLMS);
    }

    /**
     * Build the requester at a fixed point in time; collaborators a test does not pass are stubs.
     *
     * @param FlagManager $flagManager
     * @param PublisherInterface|null $publisher
     * @param LoggerInterface|null $logger
     * @return RegenerationRequester
     */
    private function requester(
        FlagManager $flagManager,
        ?PublisherInterface $publisher = null,
        ?LoggerInterface $logger = null
    ): RegenerationRequester {
        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);

        return new RegenerationRequester(
            $flagManager,
            $publisher ?? $this->createStub(PublisherInterface::class),
            $dateTime,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }
}
