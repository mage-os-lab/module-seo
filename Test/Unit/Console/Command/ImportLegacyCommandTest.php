<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use MageOS\Seo\Console\Command\ImportLegacyCommand;
use MageOS\Seo\Model\Migration\LegacyImport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `bin/magento seo:import-legacy` runs every import from the modules this one replaces, or repeats
 * it, and reports what each wrote, the values it kept and a failure (issue #27).
 */
class ImportLegacyCommandTest extends TestCase
{
    /**
     * @return void
     */
    public function testEveryImportIsRunAndReported(): void
    {
        $legacyImport = $this->legacyImport([
            'MageOS_MetaRobotsTag' => $this->importResult(['Imported robots settings for 2 product(s).']),
            'MageOS_Hreflang'      => $this->importResult([], ['Website 1: kept x-default store view 1.']),
        ], false);

        $tester = new CommandTester($this->command($legacyImport));
        $status = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame(
            "MageOS_MetaRobotsTag\n"
            . "  Imported robots settings for 2 product(s).\n"
            . "MageOS_Hreflang\n"
            . "  Nothing to import.\n"
            . "  Note: Website 1: kept x-default store view 1.\n",
            $tester->getDisplay()
        );
    }

    /**
     * @return void
     */
    public function testADryRunIsPassedOnAndSaysNothingWasWritten(): void
    {
        $legacyImport = $this->legacyImport([
            'MageOS_MetaRobotsTag' => $this->importResult(['Would import robots settings for 1 product(s).']),
        ], true);

        $tester = new CommandTester($this->command($legacyImport));
        $status = $tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Would import robots settings for 1 product(s).', $tester->getDisplay());
        $this->assertStringEndsWith("Dry run: nothing was written.\n", $tester->getDisplay());
    }

    /**
     * A failed import fails the command, after the other imports have run.
     *
     * @return void
     */
    public function testAFailureFailsTheCommandOnceTheOthersHaveRun(): void
    {
        $legacyImport = $this->legacyImport([
            'MageOS_MetaRobotsTag' => $this->importResult([], [], 'Deadlock found'),
            'MageOS_Hreflang'      => $this->importResult(['Imported hreflang codes for store view(s) 1.']),
        ], false);

        $tester = new CommandTester($this->command($legacyImport));
        $status = $tester->execute([]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString(
            '  Failed: Deadlock found. The system log has the details.',
            $tester->getDisplay()
        );
        $this->assertStringContainsString('Imported hreflang codes for store view(s) 1.', $tester->getDisplay());
    }

    /**
     * Running it again after an import finished is allowed, and said.
     *
     * @return void
     */
    public function testAnImportThatFinishedBeforeIsRepeatedAndSaysSo(): void
    {
        $legacyImport = $this->legacyImport(
            ['MageOS_Hreflang' => $this->importResult()],
            false,
            ['MageOS_Hreflang' => '2026-10-10 09:15:00']
        );

        $tester = new CommandTester($this->command($legacyImport));
        $tester->execute([]);

        $this->assertSame(
            "MageOS_Hreflang\n"
            . "  Imported before, on 2026-10-10 09:15:00 UTC; only what is still empty is filled.\n"
            . "  Nothing to import.\n",
            $tester->getDisplay()
        );
    }

    /**
     * @return void
     */
    public function testSetsTheGlobalAreaWhenNoneIsSet(): void
    {
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willThrowException(new LocalizedException(__('Area code is not set')));
        $state->expects($this->once())->method('setAreaCode')->with(Area::AREA_GLOBAL);

        (new CommandTester($this->command($this->legacyImport([], false), $state)))->execute([]);
    }

    /**
     * @return void
     */
    public function testKeepsAnAreaThatIsAlreadySet(): void
    {
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willReturn(Area::AREA_ADMINHTML);
        $state->expects($this->never())->method('setAreaCode');

        (new CommandTester($this->command($this->legacyImport([], false), $state)))->execute([]);
    }

    /**
     * What LegacyImport::run() returns.
     *
     * @param string[] $lines
     * @param string[] $notes
     * @param string|null $error
     * @return array{lines: string[], notes: string[], error: string|null}
     */
    private function importResult(array $lines = [], array $notes = [], ?string $error = null): array
    {
        return ['lines' => $lines, 'notes' => $notes, 'error' => $error];
    }

    /**
     * A LegacyImport whose run() of each module, with the dry run expected, returns the given result.
     *
     * @param array<string, array{lines: string[], notes: string[], error: string|null}> $results
     * @param bool $dryRun
     * @param array<string, string> $finishedAt
     * @return LegacyImport
     */
    private function legacyImport(array $results, bool $dryRun, array $finishedAt = []): LegacyImport
    {
        $legacyImport = $this->createMock(LegacyImport::class);
        $legacyImport->method('modules')->willReturn(array_keys($results));
        $legacyImport->method('finishedAt')->willReturnCallback(
            static fn (string $module): ?string => $finishedAt[$module] ?? null
        );
        $legacyImport->expects($this->exactly(\count($results)))->method('run')->willReturnCallback(
            function (string $module, bool $asked) use ($results, $dryRun): array {
                $this->assertSame($dryRun, $asked);

                return $results[$module];
            }
        );

        return $legacyImport;
    }

    /**
     * @param LegacyImport $legacyImport
     * @param State|null $state
     * @return ImportLegacyCommand
     */
    private function command(LegacyImport $legacyImport, ?State $state = null): ImportLegacyCommand
    {
        if ($state === null) {
            $state = $this->createStub(State::class);
            $state->method('getAreaCode')->willReturn(Area::AREA_GLOBAL);
        }

        return new ImportLegacyCommand($legacyImport, $state);
    }
}
