<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Migration;

use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Runs the imports from the modules this one replaces, and records which have finished (issue #27).
 *
 * Whether an import finished is this module's own record, a flag, and not the setup patch history.
 * Core records a data patch as applied once it has run, whether or not the import inside it
 * worked, and never runs it again.
 *
 * Three callers:
 * - **The data patches, on setup:upgrade.** Each import is tried once, and never fails the upgrade.
 * - **`bin/magento seo:import-legacy [--dry-run]`.** It runs the imports, or repeats them; since an
 *   import never replaces a value, a repeat fills only what is still empty.
 * - **The admin's System Messages.** It shows while an import has not finished and its module left
 *   data behind.
 *
 * The importers are listed in di.xml, keyed by the name of the module each imports from.
 */
class LegacyImport
{
    public const FLAG = 'mageos_seo_legacy_import';

    public const META_ROBOTS_TAG = 'MageOS_MetaRobotsTag';

    public const HREFLANG = 'MageOS_Hreflang';

    /**
     * @param FlagManager $flagManager
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     * @param LegacyImporterInterface[] $importers By the name of the module each imports from
     */
    public function __construct(
        private readonly FlagManager     $flagManager,
        private readonly DateTime        $dateTime,
        private readonly LoggerInterface $logger,
        private readonly array           $importers = []
    ) {
    }

    /**
     * The modules there is an import from.
     *
     * @return string[]
     */
    public function modules(): array
    {
        return array_keys($this->importers);
    }

    /**
     * When the import from a module last finished, in UTC, or null when it never has.
     *
     * @param string $module
     * @return string|null
     */
    public function finishedAt(string $module): ?string
    {
        return $this->finished()[$module] ?? null;
    }

    /**
     * The modules whose import has not finished, and which left data behind.
     *
     * @return string[]
     */
    public function pending(): array
    {
        $finished = $this->finished();

        return array_values(array_filter(
            $this->modules(),
            fn (string $module): bool => !isset($finished[$module]) && $this->importers[$module]->hasLegacyData()
        ));
    }

    /**
     * Run the import from a module unless it has finished: the data patches' run.
     *
     * Never throws. A failure is logged, and the import stays unfinished for the admin message and
     * the command.
     *
     * @param string $module
     * @return void
     */
    public function runIfUnfinished(string $module): void
    {
        if ($this->finishedAt($module) === null) {
            $this->run($module);
        }
    }

    /**
     * Run the import from a module, record that it finished, and log what it did.
     *
     * Returns what was written (or would be), the values kept and the input dropped, and the
     * failure. A failure is logged and returned rather than thrown, so the next module's import
     * still runs. A dry run records nothing and logs only a failure.
     *
     * @param string $module
     * @param bool $dryRun
     * @throws \InvalidArgumentException When there is no import from the module
     * @return array{lines: string[], notes: string[], error: string|null}
     */
    public function run(string $module, bool $dryRun = false): array
    {
        $importer = $this->importers[$module] ?? throw new \InvalidArgumentException(
            \sprintf('There is no import from %s. Imports: %s.', $module, implode(', ', $this->modules()))
        );

        try {
            $report = $importer->import($dryRun);
        } catch (\Throwable $e) {
            $this->logger->error(
                \sprintf('MageOS_Seo: could not import %s settings: %s', $module, $e->getMessage()),
                ['exception' => $e]
            );

            return ['lines' => [], 'notes' => [], 'error' => $e->getMessage()];
        }

        $lines = $importer->describe($report, $dryRun);
        $notes = $report['notes'];
        if ($dryRun) {
            return ['lines' => $lines, 'notes' => $notes, 'error' => null];
        }

        $this->finish($module);
        foreach ($notes as $note) {
            $this->logger->warning(\sprintf('MageOS_Seo: %s import: %s', $module, $note));
        }
        unset($report['notes']);
        $this->logger->info(\sprintf('MageOS_Seo: imported %s settings', $module), $report);

        return ['lines' => $lines, 'notes' => $notes, 'error' => null];
    }

    /**
     * Record that the import from a module finished, now.
     *
     * @param string $module
     * @return void
     */
    private function finish(string $module): void
    {
        $finished          = $this->finished();
        $finished[$module] = $this->dateTime->gmtDate('Y-m-d H:i:s');
        $this->flagManager->saveFlag(self::FLAG, $finished);
    }

    /**
     * When each finished import finished, by module.
     *
     * @return array<string, string>
     */
    private function finished(): array
    {
        $finished = $this->flagManager->getFlagData(self::FLAG);

        // No flag until the first import finishes.
        return \is_array($finished) ? $finished : [];
    }
}
