<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Migration;

use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use MageOS\Seo\Model\Migration\LegacyImport;
use MageOS\Seo\Model\Migration\LegacyImporterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Issue #27: an import from a module this one replaces is recorded as finished only when it
 * finished, in the module's own flag, so a failed one is not mistaken for done.
 */
class LegacyImportTest extends TestCase
{
    private const NOW = '2026-10-10 12:00:00';

    /**
     * The flag's data, as the fake flag manager holds it.
     *
     * @var mixed
     */
    private mixed $flag = null;

    /**
     * Calls to the logger, as [level, message, context].
     *
     * @var array<int, array{0: string, 1: string, 2: mixed[]}>
     */
    private array $logged = [];

    /**
     * @return void
     */
    public function testAFinishedImportIsRecordedAndWhatItDidLogged(): void
    {
        $this->flag = ['MageOS_Hreflang' => '2026-10-01 08:00:00'];
        $importer   = $this->importer(['written' => 3, 'notes' => ['Kept one.']], ['Imported 3.']);

        $result = $this->legacyImport(['MageOS_MetaRobotsTag' => $importer])->run('MageOS_MetaRobotsTag');

        $this->assertSame(['lines' => ['Imported 3.'], 'notes' => ['Kept one.'], 'error' => null], $result);
        $this->assertSame(
            ['MageOS_Hreflang' => '2026-10-01 08:00:00', 'MageOS_MetaRobotsTag' => self::NOW],
            $this->flag,
            'Recorded alongside the imports that finished before.'
        );
        $this->assertSame(
            [
                ['warning', 'MageOS_Seo: MageOS_MetaRobotsTag import: Kept one.', []],
                ['info', 'MageOS_Seo: imported MageOS_MetaRobotsTag settings', ['written' => 3]],
            ],
            $this->logged
        );
    }

    /**
     * @return void
     */
    public function testAFailedImportIsLoggedAndStaysUnfinished(): void
    {
        $failure  = new \RuntimeException('Deadlock found');
        $importer = $this->createStub(LegacyImporterInterface::class);
        $importer->method('import')->willThrowException($failure);
        $importer->method('hasLegacyData')->willReturn(true);
        $legacyImport = $this->legacyImport(['MageOS_MetaRobotsTag' => $importer]);

        $result = $legacyImport->run('MageOS_MetaRobotsTag');

        $this->assertSame(['lines' => [], 'notes' => [], 'error' => 'Deadlock found'], $result);
        $this->assertNull($this->flag);
        $this->assertNull($legacyImport->finishedAt('MageOS_MetaRobotsTag'));
        $this->assertSame(['MageOS_MetaRobotsTag'], $legacyImport->pending());
        $this->assertSame(
            [[
                'error',
                'MageOS_Seo: could not import MageOS_MetaRobotsTag settings: Deadlock found',
                ['exception' => $failure],
            ]],
            $this->logged
        );
    }

    /**
     * The data patches' run never throws, so setup:upgrade carries on.
     *
     * @return void
     */
    public function testTheAutomaticRunOfAFailingImportDoesNotThrow(): void
    {
        $importer = $this->createStub(LegacyImporterInterface::class);
        $importer->method('import')->willThrowException(new \RuntimeException('Deadlock found'));

        $this->legacyImport(['MageOS_Hreflang' => $importer])->runIfUnfinished('MageOS_Hreflang');

        $this->assertNull($this->flag);
        $this->assertCount(1, $this->logged);
    }

    /**
     * @return void
     */
    public function testTheAutomaticRunRunsAnUnfinishedImport(): void
    {
        $this->legacyImport(['MageOS_Hreflang' => $this->importer(['notes' => []], [])])
            ->runIfUnfinished('MageOS_Hreflang');

        $this->assertSame(['MageOS_Hreflang' => self::NOW], $this->flag);
    }

    /**
     * @return void
     */
    public function testTheAutomaticRunSkipsAFinishedImport(): void
    {
        $this->flag = ['MageOS_Hreflang' => '2026-10-01 08:00:00'];
        $importer   = $this->createMock(LegacyImporterInterface::class);
        $importer->expects($this->never())->method('import');

        $this->legacyImport(['MageOS_Hreflang' => $importer])->runIfUnfinished('MageOS_Hreflang');

        $this->assertSame(['MageOS_Hreflang' => '2026-10-01 08:00:00'], $this->flag);
    }

    /**
     * @return void
     */
    public function testADryRunIsNeitherRecordedNorLogged(): void
    {
        $importer = $this->createMock(LegacyImporterInterface::class);
        $importer->expects($this->once())->method('import')->with(true)->willReturn(['notes' => ['Kept one.']]);
        $importer->expects($this->once())->method('describe')->with(['notes' => ['Kept one.']], true)
            ->willReturn(['Would import 1.']);

        $result = $this->legacyImport(['MageOS_Hreflang' => $importer])->run('MageOS_Hreflang', true);

        $this->assertSame(['lines' => ['Would import 1.'], 'notes' => ['Kept one.'], 'error' => null], $result);
        $this->assertNull($this->flag);
        $this->assertSame([], $this->logged);
    }

    /**
     * Pending: not finished, and the module left data behind. A finished import is not asked.
     *
     * @return void
     */
    public function testPendingListsUnfinishedImportsThatFoundData(): void
    {
        $this->flag = ['finished' => '2026-10-01 08:00:00'];
        $finished   = $this->createMock(LegacyImporterInterface::class);
        $finished->expects($this->never())->method('hasLegacyData');
        $withData = $this->createStub(LegacyImporterInterface::class);
        $withData->method('hasLegacyData')->willReturn(true);
        $withoutData = $this->createStub(LegacyImporterInterface::class);
        $withoutData->method('hasLegacyData')->willReturn(false);

        $legacyImport = $this->legacyImport([
            'finished'     => $finished,
            'with_data'    => $withData,
            'without_data' => $withoutData,
        ]);

        $this->assertSame(['with_data'], $legacyImport->pending());
        $this->assertSame(['finished', 'with_data', 'without_data'], $legacyImport->modules());
        $this->assertSame('2026-10-01 08:00:00', $legacyImport->finishedAt('finished'));
    }

    /**
     * @return void
     */
    public function testAnUnknownModuleIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no import from MageOS_Unknown. Imports: MageOS_Hreflang.');

        $this->legacyImport(['MageOS_Hreflang' => $this->createStub(LegacyImporterInterface::class)])
            ->run('MageOS_Unknown');
    }

    /**
     * An importer whose import() returns the report and describe() the lines, for a run that is not dry.
     *
     * @param array<string, mixed> $report
     * @param string[] $lines
     * @return LegacyImporterInterface
     */
    private function importer(array $report, array $lines): LegacyImporterInterface
    {
        $importer = $this->createMock(LegacyImporterInterface::class);
        $importer->expects($this->once())->method('import')->with(false)->willReturn($report);
        $importer->method('describe')->with($report, false)->willReturn($lines);

        return $importer;
    }

    /**
     * LegacyImport over a fake flag manager and a recording logger.
     *
     * @param LegacyImporterInterface[] $importers
     * @return LegacyImport
     */
    private function legacyImport(array $importers): LegacyImport
    {
        $flagManager = $this->createStub(FlagManager::class);
        $flagManager->method('getFlagData')->willReturnCallback(
            fn (string $code): mixed => $code === LegacyImport::FLAG ? $this->flag : null
        );
        $flagManager->method('saveFlag')->willReturnCallback(function (string $code, mixed $value): bool {
            $this->assertSame(LegacyImport::FLAG, $code);
            $this->flag = $value;

            return true;
        });

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtDate')->willReturnCallback(
            static fn (?string $format = null): string => $format === 'Y-m-d H:i:s' ? self::NOW : 'wrong format'
        );

        $logger = $this->createStub(LoggerInterface::class);
        foreach (['error', 'warning', 'info'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string $message, array $context = []) use ($level): void {
                    $this->logged[] = [$level, $message, $context];
                }
            );
        }

        return new LegacyImport($flagManager, $dateTime, $logger, $importers);
    }
}
