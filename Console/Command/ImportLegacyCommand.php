<?php

declare(strict_types=1);

namespace MageOS\Seo\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use MageOS\Seo\Model\Migration\LegacyImport;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Imports the settings of the modules this one replaces, MageOS_MetaRobotsTag and MageOS_Hreflang,
 * or repeats the import.
 *
 * setup:upgrade tries each import once (Setup\Patch\Data); this is how one that failed is run
 * again, and how settings made in those modules since are carried over. An import never replaces a
 * value set in this module, so a repeat fills only what is still empty. `--dry-run` reports what
 * would be written, and writes nothing.
 */
class ImportLegacyCommand extends Command
{
    private const OPTION_DRY_RUN = 'dry-run';

    /**
     * @param LegacyImport $legacyImport Injected as a proxy: the imports are built only when the command runs
     * @param State $appState
     * @param string|null $name
     */
    public function __construct(
        private readonly LegacyImport $legacyImport,
        private readonly State        $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('seo:import-legacy')
            ->setDescription(
                'Import the settings of MageOS_MetaRobotsTag and MageOS_Hreflang, never replacing a value set in'
                . ' MageOS SEO'
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Report what would be imported, and write nothing'
            );

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption(self::OPTION_DRY_RUN);
        $this->ensureAreaCode();

        $failed = false;
        foreach ($this->legacyImport->modules() as $module) {
            $output->writeln('<info>' . $module . '</info>');

            $finishedAt = $this->legacyImport->finishedAt($module);
            if ($finishedAt !== null) {
                $output->writeln(\sprintf(
                    '  Imported before, on %s UTC; only what is still empty is filled.',
                    $finishedAt
                ));
            }

            $result = $this->legacyImport->run($module, $dryRun);
            if ($result['error'] !== null) {
                $output->writeln(\sprintf(
                    '  <error>Failed: %s. The system log has the details.</error>',
                    rtrim($result['error'], '.')
                ));
                $failed = true;
                continue;
            }

            foreach ($result['lines'] === [] ? ['Nothing to import.'] : $result['lines'] as $line) {
                $output->writeln('  ' . $line);
            }
            foreach ($result['notes'] as $note) {
                $output->writeln('  <comment>Note: ' . $note . '</comment>');
            }
        }

        if ($dryRun) {
            $output->writeln('Dry run: nothing was written.');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Set the global area when none is, as bin/magento leaves it.
     *
     * @return void
     */
    private function ensureAreaCode(): void
    {
        try {
            $this->appState->getAreaCode();
        } catch (LocalizedException) {
            $this->appState->setAreaCode(Area::AREA_GLOBAL);
        }
    }
}
