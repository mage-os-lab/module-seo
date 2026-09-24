<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Feed;

use MageOS\Seo\Exception\FeedRebuildInProgressException;
use MageOS\Seo\Exception\SitemapRebuildInProgressException;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\RegenerateConsumer;
use MageOS\Seo\Model\Feed\RegenerationRequester;
use MageOS\Seo\Model\Sitemap\Rebuilder as SitemapRebuilder;
use MageOS\Seo\Model\Sitemap\RebuildGroup;
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

        $this->consumer = $this->consumer($this->createStub(SitemapRebuilder::class));
    }

    public function testASitemapGroupRebuildsThatTypeAndNoFeed(): void
    {
        $rebuilder = $this->createMock(SitemapRebuilder::class);
        $rebuilder->method('hasType')->willReturn(true);
        $rebuilder->expects($this->once())->method('rebuild')->with('products')->willReturn([]);
        $this->regenerator->expects($this->never())->method('regenerate');
        $this->requester->expects($this->once())->method('acknowledge')->with('sitemap-products');

        $this->consumer($rebuilder)->process('sitemap-products');
    }

    public function testTheFirstBuildGroupWritesTheMissingSitemapsAndNothingElse(): void
    {
        $rebuilder = $this->createMock(SitemapRebuilder::class);
        $rebuilder->expects($this->once())->method('buildMissing')->willReturn([]);
        $rebuilder->expects($this->never())->method('rebuild');
        $this->regenerator->expects($this->never())->method('regenerate');
        $this->requester->expects($this->once())->method('acknowledge')->with(RebuildGroup::MISSING);
        $this->logger->expects($this->never())->method('warning');

        $this->consumer($rebuilder)->process(RebuildGroup::MISSING);
    }

    public function testAFirstBuildBeingWrittenElsewherePutsTheRequestBack(): void
    {
        $rebuilder = $this->createStub(SitemapRebuilder::class);
        $rebuilder->method('buildMissing')->willThrowException(new SitemapRebuildInProgressException(__('busy')));
        $this->requester->expects($this->once())->method('request')->with(RebuildGroup::MISSING);
        $this->regenerator->expects($this->never())->method('regenerate');
        $this->logger->expects($this->never())->method('error');

        $this->consumer($rebuilder)->process(RebuildGroup::MISSING);
    }

    public function testAFeedGroupRebuildsNoSitemap(): void
    {
        $rebuilder = $this->createMock(SitemapRebuilder::class);
        $rebuilder->expects($this->never())->method('rebuild');

        $this->consumer($rebuilder)->process(FeedRegenerator::GROUP_JSONL);
    }

    public function testASitemapTypeNoProviderHasIsRejectedWithoutBuilding(): void
    {
        $rebuilder = $this->createMock(SitemapRebuilder::class);
        $rebuilder->method('hasType')->willReturn(false);
        $rebuilder->expects($this->never())->method('rebuild');
        $this->logger->expects($this->once())->method('warning');
        $this->requester->expects($this->never())->method('acknowledge');

        $this->consumer($rebuilder)->process('sitemap-nothing-lists-this');
    }

    public function testASitemapBeingWrittenElsewherePutsTheRequestBack(): void
    {
        $rebuilder = $this->createStub(SitemapRebuilder::class);
        $rebuilder->method('hasType')->willReturn(true);
        $rebuilder->method('rebuild')->willThrowException(new SitemapRebuildInProgressException(__('being written')));
        $this->requester->expects($this->once())->method('request')->with('sitemap-pages');
        $this->logger->expects($this->never())->method('error');

        $this->consumer($rebuilder)->process('sitemap-pages');
    }

    /**
     * The consumer over this test's feed doubles and the given sitemap rebuilder.
     *
     * @param SitemapRebuilder $rebuilder
     * @return RegenerateConsumer
     */
    private function consumer(SitemapRebuilder $rebuilder): RegenerateConsumer
    {
        return new RegenerateConsumer(
            $this->regenerator,
            $this->requester,
            $this->logger,
            $rebuilder,
            new RebuildGroup()
        );
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
            ->with(FeedRegenerator::GROUP_JSONL);

        $this->consumer->process(FeedRegenerator::GROUP_JSONL);
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
