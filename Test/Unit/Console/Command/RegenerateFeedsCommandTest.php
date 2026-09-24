<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sitemap\Model\Sitemap;
use MageOS\Seo\Console\Command\RegenerateFeedsCommand;
use MageOS\Seo\Exception\SitemapRebuildInProgressException;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Sitemap\RebuildableSitemaps;
use MageOS\Seo\Model\Sitemap\Rebuilder as SitemapRebuilder;
use MageOS\Seo\Model\Sitemap\RebuildGroup;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RegenerateFeedsCommandTest extends TestCase
{
    public function testRebuildsEveryFeedByDefault(): void
    {
        $regenerator = $this->createMock(FeedRegenerator::class);
        $regenerator->expects($this->once())->method('regenerate')->with(null)->willReturn([]);

        $tester = $this->tester($regenerator);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertStringContainsString('Rebuilding all feeds...', $tester->getDisplay());
        $this->assertStringContainsString('Done.', $tester->getDisplay());
    }

    public function testSetsTheGlobalAreaLikeTheQueueConsumerWhenNoAreaIsSet(): void
    {
        $regenerator = $this->createStub(FeedRegenerator::class);
        $regenerator->method('regenerate')->willReturn([]);
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willThrowException(new LocalizedException(__('Area code is not set')));
        $state->expects($this->once())->method('setAreaCode')->with(Area::AREA_GLOBAL);
        $state->expects($this->never())->method('emulateAreaCode');

        $this->assertSame(Command::SUCCESS, $this->tester($regenerator, $state)->execute([]));
    }

    public function testKeepsAnAreaThatIsAlreadySet(): void
    {
        $regenerator = $this->createStub(FeedRegenerator::class);
        $regenerator->method('regenerate')->willReturn([]);
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willReturn(Area::AREA_CRONTAB);
        $state->expects($this->never())->method('setAreaCode');

        $this->assertSame(Command::SUCCESS, $this->tester($regenerator, $state)->execute([]));
    }

    public function testRebuildsOnlyTheRequestedGroups(): void
    {
        $rebuilt     = [];
        $regenerator = $this->createStub(FeedRegenerator::class);
        $regenerator->method('regenerate')->willReturnCallback(
            static function (?string $group) use (&$rebuilt): array {
                $rebuilt[] = $group;
                return [];
            }
        );

        $status = $this->tester($regenerator)->execute([
            '--group' => [FeedRegenerator::GROUP_LLMS, FeedRegenerator::GROUP_JSONL, FeedRegenerator::GROUP_LLMS],
        ]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame([FeedRegenerator::GROUP_LLMS, FeedRegenerator::GROUP_JSONL], $rebuilt);
    }

    public function testUnknownGroupsAreRejectedBeforeBuildingAnything(): void
    {
        $regenerator = $this->createMock(FeedRegenerator::class);
        $regenerator->expects($this->never())->method('regenerate');

        $tester = $this->tester($regenerator);

        $this->assertSame(Command::INVALID, $tester->execute(['--group' => ['llms', 'sitemap']]));
        $this->assertStringContainsString('Unknown feed group(s): sitemap', $tester->getDisplay());
    }

    public function testStoreViewFailuresAreReportedWithAFailingExitCode(): void
    {
        $regenerator = $this->createStub(FeedRegenerator::class);
        $regenerator->method('regenerate')->willReturn([2 => 'disk full']);

        $tester = $this->tester($regenerator);

        $this->assertSame(Command::FAILURE, $tester->execute(['--group' => ['jsonl']]));
        $this->assertStringContainsString('jsonl, store view 2: disk full', $tester->getDisplay());
        $this->assertStringNotContainsString('Done.', $tester->getDisplay());
    }

    public function testAnUnexpectedErrorFailsTheCommand(): void
    {
        $regenerator = $this->createStub(FeedRegenerator::class);
        $regenerator->method('regenerate')->willThrowException(new \RuntimeException('no stores'));

        $tester = $this->tester($regenerator);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('no stores', $tester->getDisplay());
    }

    public function testASitemapGroupRebuildsItsTypeInEverySitemapWithoutTheFeeds(): void
    {
        $regenerator = $this->createMock(FeedRegenerator::class);
        $regenerator->expects($this->never())->method('regenerate');
        $rebuilder = $this->sitemapRebuilder();
        $rebuilder->expects($this->once())->method('rebuildOnDemand')->with('products')->willReturn([]);
        $rebuilder->expects($this->never())->method('rebuild');

        $tester = $this->tester($regenerator, rebuilder: $rebuilder);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--group' => ['sitemap-products']]));
        $this->assertStringContainsString('Rebuilding sitemap-products...', $tester->getDisplay());
    }

    public function testSitemapStarRebuildsEveryType(): void
    {
        $rebuilder = $this->sitemapRebuilder();
        $rebuilder->expects($this->once())->method('rebuildOnDemand')->with('*')->willReturn([]);

        $tester = $this->tester($this->createStub(FeedRegenerator::class), rebuilder: $rebuilder);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--group' => ['sitemap-*']]));
    }

    public function testASitemapTypeNoProviderHasIsRejectedWithTheTypesThereAre(): void
    {
        $rebuilder = $this->sitemapRebuilder(['pages', 'products']);
        $rebuilder->expects($this->never())->method('rebuildOnDemand');

        $tester = $this->tester($this->createStub(FeedRegenerator::class), rebuilder: $rebuilder);

        $this->assertSame(Command::INVALID, $tester->execute(['--group' => ['sitemap-blog']]));
        $this->assertStringContainsString('Unknown feed group(s): sitemap-blog', $tester->getDisplay());
        $this->assertStringContainsString('sitemap-pages, sitemap-products, sitemap-*', $tester->getDisplay());
    }

    public function testNoSitemapToRebuildIsSaidAndIsNotAFailure(): void
    {
        $rebuilder = $this->sitemapRebuilder();
        $rebuilder->expects($this->never())->method('rebuildOnDemand');

        $tester = $this->tester($this->createStub(FeedRegenerator::class), rebuilder: $rebuilder, sitemaps: []);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--group' => ['sitemap-pages']]));
        $this->assertStringContainsString('No sitemap to rebuild', $tester->getDisplay());
    }

    public function testSitemapFailuresAreReportedWithAFailingExitCode(): void
    {
        $rebuilder = $this->sitemapRebuilder();
        $rebuilder->expects($this->once())->method('rebuildOnDemand')->willReturn([7 => 'disk full']);

        $tester = $this->tester($this->createStub(FeedRegenerator::class), rebuilder: $rebuilder);

        $this->assertSame(Command::FAILURE, $tester->execute(['--group' => ['sitemap-pages']]));
        $this->assertStringContainsString('sitemap-pages, sitemap 7: disk full', $tester->getDisplay());
    }

    public function testASitemapBeingWrittenElsewhereFailsWithAHintToRetry(): void
    {
        $rebuilder = $this->sitemapRebuilder();
        $rebuilder->expects($this->once())->method('rebuildOnDemand')->willThrowException(
            new SitemapRebuildInProgressException(__('being written'))
        );

        $tester = $this->tester($this->createStub(FeedRegenerator::class), rebuilder: $rebuilder);

        $this->assertSame(Command::FAILURE, $tester->execute(['--group' => ['sitemap-pages']]));
        $this->assertStringContainsString('Wait for the running rebuild to finish', $tester->getDisplay());
    }

    /**
     * Wrap the command in a tester; the app state defaults to a stub with an area set, and there is
     * one sitemap to rebuild.
     *
     * @param FeedRegenerator $regenerator
     * @param State|null $state
     * @param SitemapRebuilder|null $rebuilder
     * @param Sitemap[]|null $sitemaps
     * @return CommandTester
     */
    private function tester(
        FeedRegenerator   $regenerator,
        ?State            $state = null,
        ?SitemapRebuilder $rebuilder = null,
        ?array            $sitemaps = null
    ): CommandTester {
        if ($state === null) {
            $state = $this->createStub(State::class);
            $state->method('getAreaCode')->willReturn(Area::AREA_GLOBAL);
        }
        $rebuildableSitemaps = $this->createStub(RebuildableSitemaps::class);
        $rebuildableSitemaps->method('all')->willReturn($sitemaps ?? [$this->createStub(Sitemap::class)]);

        return new CommandTester(new RegenerateFeedsCommand(
            $regenerator,
            $state,
            $rebuilder ?? $this->createStub(SitemapRebuilder::class),
            $rebuildableSitemaps,
            new RebuildGroup()
        ));
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
