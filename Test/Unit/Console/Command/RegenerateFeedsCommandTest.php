<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use MageOS\Seo\Console\Command\RegenerateFeedsCommand;
use MageOS\Seo\Model\Feed\FeedRegenerator;
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
        $this->assertStringContainsString('Feeds rebuilt.', $tester->getDisplay());
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
            '--group' => [FeedRegenerator::GROUP_LLMS, FeedRegenerator::GROUP_HREFLANG, FeedRegenerator::GROUP_LLMS],
        ]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame([FeedRegenerator::GROUP_LLMS, FeedRegenerator::GROUP_HREFLANG], $rebuilt);
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
        $this->assertStringNotContainsString('Feeds rebuilt.', $tester->getDisplay());
    }

    public function testAnUnexpectedErrorFailsTheCommand(): void
    {
        $regenerator = $this->createStub(FeedRegenerator::class);
        $regenerator->method('regenerate')->willThrowException(new \RuntimeException('no stores'));

        $tester = $this->tester($regenerator);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertStringContainsString('no stores', $tester->getDisplay());
    }

    /**
     * Wrap the command in a tester; the app state defaults to a stub with an area set.
     *
     * @param FeedRegenerator $regenerator
     * @param State|null $state
     * @return CommandTester
     */
    private function tester(FeedRegenerator $regenerator, ?State $state = null): CommandTester
    {
        if ($state === null) {
            $state = $this->createStub(State::class);
            $state->method('getAreaCode')->willReturn(Area::AREA_GLOBAL);
        }

        return new CommandTester(new RegenerateFeedsCommand($regenerator, $state));
    }
}
