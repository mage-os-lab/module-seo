<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sitemap\Model\Sitemap;
use MageOS\Seo\Api\Rebuild\GroupHandlerInterface;
use MageOS\Seo\Console\Command\RegenerateFeedsCommand;
use MageOS\Seo\Exception\RebuildInProgressException;
use MageOS\Seo\Exception\SitemapRebuildInProgressException;
use MageOS\Seo\Model\Rebuild\HandlerPool;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use MageOS\Seo\Model\Sitemap\RebuildableSitemaps;
use MageOS\Seo\Model\Sitemap\Rebuilder as SitemapRebuilder;
use MageOS\Seo\Model\Sitemap\RebuildGroup;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RegenerateFeedsCommandTest extends TestCase
{
    public function testRebuildsEveryRegisteredGroupByDefault(): void
    {
        $handler = $this->handlerMock();
        $handler->expects($this->once())->method('rebuild')->with(null)->willReturn([]);

        $tester = $this->tester($handler);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Rebuilding all feeds...', $tester->getDisplay());
        $this->assertStringContainsString('Done.', $tester->getDisplay());
    }

    public function testWithNoFeedGroupRegisteredAPlainRunSaysThereIsNothingToRebuild(): void
    {
        // MageOS_Seo without a module that registers a feed group. Sitemaps are rebuilt from here
        // only when asked for with -g, so a plain run has nothing to do, and says so.
        $tester = $this->tester(null);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Nothing to rebuild: no feed group is registered.', $tester->getDisplay());
        $this->assertStringNotContainsString('Rebuilding all feeds...', $tester->getDisplay());
    }

    public function testTheHelpListsTheRegisteredGroups(): void
    {
        $command = null;
        $this->tester($this->handler(), command: $command);

        $this->assertInstanceOf(RegenerateFeedsCommand::class, $command);
        $this->assertStringContainsString('Feed groups: one, two.', $command->getHelp());
    }

    /**
     * A run by hand records its result like the queue does, so a successful one clears the problems
     * the admin shows for the group.
     */
    public function testARunOfOneGroupRecordsItsResult(): void
    {
        $handler = $this->createStub(GroupHandlerInterface::class);
        $handler->method('getGroups')->willReturn(['one', 'two']);
        $handler->method('rebuild')->willReturn([]);
        $problemLog = $this->createMock(ProblemLog::class);
        $problemLog->expects($this->once())->method('rebuilding')->with('one');
        $problemLog->expects($this->once())->method('rebuilt')->with('one', []);

        $this->tester($handler, problemLog: $problemLog)->execute(['--group' => ['one']]);
    }

    /**
     * A run of every group is not recorded here: its results are not per group. Handlers record
     * their own.
     */
    public function testARunOfEveryGroupRecordsNothingItself(): void
    {
        $handler = $this->createStub(GroupHandlerInterface::class);
        $handler->method('getGroups')->willReturn(['one', 'two']);
        $handler->method('rebuild')->willReturn([]);
        $problemLog = $this->createMock(ProblemLog::class);
        $problemLog->expects($this->never())->method('rebuilt');

        $this->tester($handler, problemLog: $problemLog)->execute([]);
    }

    public function testBuildingTheCommandAsksTheHandlersNothing(): void
    {
        // bin/magento builds every command at start-up — setup:install included, before any store
        // exists — and the handlers read store configuration.
        $pool = $this->createMock(HandlerPool::class);
        $pool->expects($this->never())->method($this->anything());

        $command = new RegenerateFeedsCommand(
            $pool,
            $this->createStub(State::class),
            $this->createStub(SitemapRebuilder::class),
            $this->createStub(RebuildableSitemaps::class),
            new RebuildGroup(),
            $this->createStub(ProblemLog::class)
        );

        $this->assertSame('seo:rebuild', $command->getName());
        $this->assertNotSame('', $command->getDescription());
    }

    public function testSetsTheGlobalAreaLikeTheQueueConsumerWhenNoAreaIsSet(): void
    {
        $handler = $this->handler();
        $handler->method('rebuild')->willReturn([]);
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willThrowException(new LocalizedException(__('Area code is not set')));
        $state->expects($this->once())->method('setAreaCode')->with(Area::AREA_GLOBAL);
        $state->expects($this->never())->method('emulateAreaCode');

        $this->assertSame(Command::SUCCESS, $this->tester($handler, $state)->execute([]));
    }

    public function testKeepsAnAreaThatIsAlreadySet(): void
    {
        $handler = $this->handler();
        $handler->method('rebuild')->willReturn([]);
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willReturn(Area::AREA_CRONTAB);
        $state->expects($this->never())->method('setAreaCode');

        $this->assertSame(Command::SUCCESS, $this->tester($handler, $state)->execute([]));
    }

    public function testRebuildsOnlyTheRequestedGroups(): void
    {
        $rebuilt = [];
        $handler = $this->handler();
        $handler->method('rebuild')->willReturnCallback(
            static function (?string $group) use (&$rebuilt): array {
                $rebuilt[] = $group;
                return [];
            }
        );

        $status = $this->tester($handler)->execute(['--group' => ['one', 'two', 'one']]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame(['one', 'two'], $rebuilt);
    }

    public function testUnknownGroupsAreRejectedBeforeBuildingAnything(): void
    {
        $handler = $this->handlerMock();
        $handler->expects($this->never())->method('rebuild');

        $tester = $this->tester($handler);

        $this->assertSame(Command::INVALID, $tester->execute(['--group' => ['one', 'sitemap']]));
        $this->assertStringContainsString('Unknown feed group(s): sitemap', $tester->getDisplay());
    }

    public function testStoreViewFailuresAreReportedWithAFailingExitCode(): void
    {
        $handler = $this->handler();
        $handler->method('rebuild')->willReturn([2 => 'disk full']);

        $tester = $this->tester($handler);

        $this->assertSame(Command::FAILURE, $tester->execute(['--group' => ['two']]));
        $this->assertStringContainsString('two, store view 2: disk full', $tester->getDisplay());
        $this->assertStringNotContainsString('Done.', $tester->getDisplay());
    }

    public function testAnUnexpectedErrorFailsTheCommand(): void
    {
        $handler = $this->handler();
        $handler->method('rebuild')->willThrowException(new \RuntimeException('no stores'));

        $tester = $this->tester($handler);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('no stores', $tester->getDisplay());
    }

    public function testAGroupBeingRebuiltElsewhereFailsWithAHintToRetry(): void
    {
        $handler = $this->handler();
        $handler->method('rebuild')->willThrowException(new RebuildInProgressException(__('already running')));

        $tester = $this->tester($handler);

        $this->assertSame(Command::FAILURE, $tester->execute(['--group' => ['one']]));
        $this->assertStringContainsString('Wait for the running rebuild to finish', $tester->getDisplay());
    }

    public function testASitemapGroupRebuildsItsTypeInEverySitemapWithoutTheFeeds(): void
    {
        $handler = $this->handlerMock();
        $handler->expects($this->never())->method('rebuild');
        $rebuilder = $this->sitemapRebuilder();
        $rebuilder->expects($this->once())->method('rebuildOnDemand')->with('products')->willReturn([]);
        $rebuilder->expects($this->never())->method('rebuild');

        $tester = $this->tester($handler, rebuilder: $rebuilder);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--group' => ['sitemap-products']]));
        $this->assertStringContainsString('Rebuilding sitemap-products...', $tester->getDisplay());
    }

    public function testSitemapStarRebuildsEveryType(): void
    {
        $rebuilder = $this->sitemapRebuilder();
        $rebuilder->expects($this->once())->method('rebuildOnDemand')->with('*')->willReturn([]);

        $tester = $this->tester($this->handler(), rebuilder: $rebuilder);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--group' => ['sitemap-*']]));
    }

    public function testASitemapTypeNoProviderHasIsRejectedWithTheTypesThereAre(): void
    {
        $rebuilder = $this->sitemapRebuilder(['pages', 'products']);
        $rebuilder->expects($this->never())->method('rebuildOnDemand');

        $tester = $this->tester($this->handler(), rebuilder: $rebuilder);

        $this->assertSame(Command::INVALID, $tester->execute(['--group' => ['sitemap-blog']]));
        $this->assertStringContainsString('Unknown feed group(s): sitemap-blog', $tester->getDisplay());
        $this->assertStringContainsString(
            'one, two, sitemap-pages, sitemap-products, sitemap-*, sitemaps-missing',
            $tester->getDisplay()
        );
    }

    public function testTheFirstBuildGroupWritesTheSitemapsThatHaveNoFileAndRecordsTheResult(): void
    {
        // The admin's rebuild message gives a command for every group it lists, this one included.
        $rebuilder = $this->sitemapRebuilder();
        $rebuilder->expects($this->once())->method('buildMissing')->willReturn([4 => 'disk full']);
        $rebuilder->expects($this->never())->method('rebuildOnDemand');
        $problemLog = $this->createMock(ProblemLog::class);
        $problemLog->expects($this->once())->method('rebuilt')->with(RebuildGroup::MISSING, [4 => 'disk full']);

        $tester = $this->tester($this->handler(), rebuilder: $rebuilder, problemLog: $problemLog);

        $this->assertSame(Command::FAILURE, $tester->execute(['--group' => [RebuildGroup::MISSING]]));
        $this->assertStringContainsString('sitemaps-missing, sitemap 4: disk full', $tester->getDisplay());
    }

    public function testNoSitemapToRebuildIsSaidAndIsNotAFailure(): void
    {
        $rebuilder = $this->sitemapRebuilder();
        $rebuilder->expects($this->never())->method('rebuildOnDemand');

        $tester = $this->tester($this->handler(), rebuilder: $rebuilder, sitemaps: []);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--group' => ['sitemap-pages']]));
        $this->assertStringContainsString('No sitemap to rebuild', $tester->getDisplay());
    }

    public function testSitemapFailuresAreReportedWithAFailingExitCode(): void
    {
        $rebuilder = $this->sitemapRebuilder();
        $rebuilder->expects($this->once())->method('rebuildOnDemand')->willReturn([7 => 'disk full']);

        $tester = $this->tester($this->handler(), rebuilder: $rebuilder);

        $this->assertSame(Command::FAILURE, $tester->execute(['--group' => ['sitemap-pages']]));
        $this->assertStringContainsString('sitemap-pages, sitemap 7: disk full', $tester->getDisplay());
    }

    public function testASitemapBeingWrittenElsewhereFailsWithAHintToRetry(): void
    {
        $rebuilder = $this->sitemapRebuilder();
        $rebuilder->expects($this->once())->method('rebuildOnDemand')->willThrowException(
            new SitemapRebuildInProgressException(__('being written'))
        );

        $tester = $this->tester($this->handler(), rebuilder: $rebuilder);

        $this->assertSame(Command::FAILURE, $tester->execute(['--group' => ['sitemap-pages']]));
        $this->assertStringContainsString('Wait for the running rebuild to finish', $tester->getDisplay());
    }

    /**
     * A registered handler owning the groups `one` and `two`.
     *
     * @return GroupHandlerInterface&Stub
     */
    private function handler(): GroupHandlerInterface&Stub
    {
        $handler = $this->createStub(GroupHandlerInterface::class);
        $handler->method('getGroups')->willReturn(['one', 'two']);

        return $handler;
    }

    /**
     * The same handler as a mock, for a test that verifies what it is asked to rebuild.
     *
     * @return GroupHandlerInterface&MockObject
     */
    private function handlerMock(): GroupHandlerInterface&MockObject
    {
        $handler = $this->createMock(GroupHandlerInterface::class);
        $handler->method('getGroups')->willReturn(['one', 'two']);

        return $handler;
    }

    /**
     * Wrap the command in a tester; the app state defaults to a stub with an area set, and there is
     * one sitemap to rebuild.
     *
     * @param GroupHandlerInterface|null $handler The one registered handler, or null for none
     * @param State|null $state
     * @param SitemapRebuilder|null $rebuilder
     * @param Sitemap[]|null $sitemaps
     * @param RegenerateFeedsCommand|null $command Receives the command under test
     * @param ProblemLog|null $problemLog
     * @param-out RegenerateFeedsCommand $command
     * @return CommandTester
     */
    private function tester(
        ?GroupHandlerInterface $handler,
        ?State $state = null,
        ?SitemapRebuilder $rebuilder = null,
        ?array $sitemaps = null,
        ?RegenerateFeedsCommand &$command = null,
        ?ProblemLog $problemLog = null
    ): CommandTester {
        if ($state === null) {
            $state = $this->createStub(State::class);
            $state->method('getAreaCode')->willReturn(Area::AREA_GLOBAL);
        }
        $rebuildableSitemaps = $this->createStub(RebuildableSitemaps::class);
        $rebuildableSitemaps->method('all')->willReturn($sitemaps ?? [$this->createStub(Sitemap::class)]);

        $command = new RegenerateFeedsCommand(
            new HandlerPool($handler === null ? [] : [$handler]),
            $state,
            $rebuilder ?? $this->createStub(SitemapRebuilder::class),
            $rebuildableSitemaps,
            new RebuildGroup(),
            $problemLog ?? $this->createStub(ProblemLog::class)
        );

        return new CommandTester($command);
    }

    /**
     * A sitemap rebuilder whose generator writes the given types.
     *
     * @param string[] $types
     * @return SitemapRebuilder&MockObject
     */
    private function sitemapRebuilder(array $types = ['pages', 'products']): SitemapRebuilder
    {
        $rebuilder = $this->createMock(SitemapRebuilder::class);
        $rebuilder->method('types')->willReturn($types);
        $rebuilder->method('hasType')->willReturnCallback(
            static fn (string $type): bool => $type === '*' || \in_array($type, $types, true)
        );

        return $rebuilder;
    }
}
