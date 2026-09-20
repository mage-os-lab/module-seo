<?php

declare(strict_types=1);

namespace MageOS\Seo\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use MageOS\Seo\Exception\FeedRebuildInProgressException;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Rebuilds the pre-generated SEO feeds immediately, in this process.
 *
 * The manual counterpart of the nightly cron: for deployment scripts that need the feeds in
 * place before traffic arrives, and for rebuilding on demand. It does not need the queue
 * consumer to be running.
 */
class RegenerateFeedsCommand extends Command
{
    private const OPTION_GROUP = 'group';

    /**
     * @param FeedRegenerator $feedRegenerator Injected as a proxy: builders load only when the command runs
     * @param State $appState
     * @param string|null $name
     */
    public function __construct(
        private readonly FeedRegenerator $feedRegenerator,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('mageos:seo:feeds:regenerate')
            ->setDescription(
                'Rebuild the SEO feeds (llms.txt, llms-full.txt, llms.jsonl, hreflang sitemap)'
                . ' for every active store view'
            )
            ->addOption(
                self::OPTION_GROUP,
                'g',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Feed group to rebuild (' . implode(', ', FeedRegenerator::GROUPS) . '); repeat for several.'
                . ' Default: all'
            );

        parent::configure();
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $groups  = array_values(array_unique(array_map('strval', (array) $input->getOption(self::OPTION_GROUP))));
        $unknown = array_diff($groups, FeedRegenerator::GROUPS);
        if ($unknown !== []) {
            $output->writeln(\sprintf(
                '<error>Unknown feed group(s): %s. Use: %s.</error>',
                implode(', ', $unknown),
                implode(', ', FeedRegenerator::GROUPS)
            ));
            return Command::INVALID;
        }

        $failures = [];
        try {
            $this->ensureAreaCode();
            foreach ($groups === [] ? [null] : $groups as $group) {
                $label = $group ?? 'all feeds';
                $output->writeln(\sprintf('Rebuilding %s...', $label));
                foreach ($this->feedRegenerator->regenerate($group) as $storeId => $message) {
                    $failures[] = \sprintf('%s, store view %d: %s', $label, $storeId, $message);
                }
            }
        } catch (FeedRebuildInProgressException $e) {
            // Distinguished from a crash for the operator's sake — nothing is broken, the cron or
            // the queue consumer is mid-rebuild. Still a failure: the feeds asked for were not
            // written, and a deployment script must not read this as "done".
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            $output->writeln('<comment>Wait for the running rebuild to finish, then retry.</comment>');
            return Command::FAILURE;
        } catch (\Throwable $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($failures !== []) {
            foreach ($failures as $failure) {
                $output->writeln('<error>' . $failure . '</error>');
            }
            return Command::FAILURE;
        }

        $output->writeln('<info>Feeds rebuilt.</info>');
        return Command::SUCCESS;
    }

    /**
     * Set the global area, as the queue consumer does, unless an area is already set.
     *
     * Not State::emulateAreaCode(): while an area is emulated, the theme design model resolves
     * themes against the emulated area instead of the frontend area the store emulation sets,
     * and loading the store's theme translations fails ("Required parameter 'theme_dir' was
     * not passed").
     *
     * @return void
     * @throws LocalizedException
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
