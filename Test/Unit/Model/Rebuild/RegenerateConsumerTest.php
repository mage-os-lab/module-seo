<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Rebuild;

use MageOS\Seo\Api\Rebuild\GroupHandlerInterface;
use MageOS\Seo\Exception\RebuildInProgressException;
use MageOS\Seo\Exception\SitemapRebuildInProgressException;
use MageOS\Seo\Model\Rebuild\HandlerPool;
use MageOS\Seo\Model\Rebuild\RegenerateConsumer;
use MageOS\Seo\Model\Rebuild\RegenerationRequester;
use MageOS\Seo\Model\Sitemap\Rebuilder as SitemapRebuilder;
use MageOS\Seo\Model\Sitemap\RebuildGroup;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RegenerateConsumerTest extends TestCase
{
    /**
     * A registered handler, owning the groups `one` and `two`.
     *
     * @var GroupHandlerInterface&MockObject
     */
    private GroupHandlerInterface&MockObject $handler;

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
        $this->handler   = $this->createMock(GroupHandlerInterface::class);
        $this->handler->method('getGroups')->willReturn(['one', 'two']);
        $this->requester = $this->createMock(RegenerationRequester::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $this->consumer = $this->consumer($this->createStub(SitemapRebuilder::class));
    }

    public function testASitemapGroupRebuildsThatTypeAndNoHandlerGroup(): void
    {
        $rebuilder = $this->createMock(SitemapRebuilder::class);
        $rebuilder->method('hasType')->willReturn(true);
        $rebuilder->expects($this->once())->method('rebuild')->with('products')->willReturn([]);
        $this->handler->expects($this->never())->method('rebuild');
        $this->requester->expects($this->once())->method('acknowledge')->with('sitemap-products');

        $this->consumer($rebuilder)->process('sitemap-products');
    }

    public function testTheFirstBuildGroupWritesTheMissingSitemapsAndNothingElse(): void
    {
        $rebuilder = $this->createMock(SitemapRebuilder::class);
        $rebuilder->expects($this->once())->method('buildMissing')->willReturn([]);
        $rebuilder->expects($this->never())->method('rebuild');
        $this->handler->expects($this->never())->method('rebuild');
        $this->requester->expects($this->once())->method('acknowledge')->with(RebuildGroup::MISSING);
        $this->logger->expects($this->never())->method('warning');

        $this->consumer($rebuilder)->process(RebuildGroup::MISSING);
    }

    public function testAFirstBuildBeingWrittenElsewherePutsTheRequestBack(): void
    {
        $rebuilder = $this->createStub(SitemapRebuilder::class);
        $rebuilder->method('buildMissing')->willThrowException(new SitemapRebuildInProgressException(__('busy')));
        $this->requester->expects($this->once())->method('request')->with(RebuildGroup::MISSING);
        $this->handler->expects($this->never())->method('rebuild');
        $this->logger->expects($this->never())->method('error');

        $this->consumer($rebuilder)->process(RebuildGroup::MISSING);
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
        $this->handler
            ->expects($this->once())
            ->method('rebuild')
            ->with('two');

        $this->consumer->process('two');
    }

    public function testUnknownGroupIsRejectedWithoutBuilding(): void
    {
        $this->logger->expects($this->once())->method('warning');
        $this->requester->expects($this->never())->method('acknowledge');
        $this->handler->expects($this->never())->method('rebuild');

        $this->consumer->process('not-a-group');
    }

    public function testLosingTheRebuildLockPutsTheRequestBack(): void
    {
        // acknowledge() has already cleared the pending flag by this point, so dropping the
        // message would lose the invalidation: the build holding the lock may have passed the
        // data this message was about before the message existed.
        $this->handler->method('rebuild')
            ->willThrowException(new RebuildInProgressException(__('already running')));

        $this->requester->expects($this->once())
            ->method('request')
            ->with('one');

        $this->consumer->process('one');
    }

    public function testLosingTheRebuildLockIsNotAnError(): void
    {
        // Another process doing the same work is expected, not a fault: nothing is logged at
        // error level and nothing is thrown at the queue.
        $this->handler->method('rebuild')
            ->willThrowException(new RebuildInProgressException(__('already running')));
        $this->logger->expects($this->never())->method('error');
        $this->logger->expects($this->once())->method('info');

        $this->consumer->process('one');
    }

    /**
     * The consumer over this test's handler and doubles, and the given sitemap rebuilder.
     *
     * @param SitemapRebuilder $rebuilder
     * @return RegenerateConsumer
     */
    private function consumer(SitemapRebuilder $rebuilder): RegenerateConsumer
    {
        return new RegenerateConsumer(
            new HandlerPool([$this->handler]),
            $this->requester,
            $this->logger,
            $rebuilder,
            new RebuildGroup()
        );
    }
}
