<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Rebuild;

use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Makes a long-running rebuild consumer see current data before each build (module-aeo #9).
 *
 * Core's queue loop runs every message in one process and resets nothing between them: memoised
 * services keep what the first message loaded (the Organization, stock IDs, the sitemap list), and
 * so does the configuration, which is loaded once per process. A consumer running for hours would
 * build from data changed long since. Before each build this:
 *
 *  - resets the services listed in di.xml (`services`), each a ResetAfterRequestInterface — the
 *    contract they already keep for FrankenPHP worker mode. Other modules add theirs there;
 *  - reloads the configuration and the store list when a configuration change was committed since
 *    this process last loaded them (CONFIG_CHANGED_FLAG, written by Observer\MarkConfigChanged).
 *    Reloading rebuilds core's configuration cache, so it is done only when needed — and once in
 *    a process that has not loaded it yet since a change was recorded, as the configuration it
 *    read at start-up may predate it.
 */
class BuildFreshness
{
    /**
     * The flag a committed configuration change writes, a new value each time.
     */
    public const CONFIG_CHANGED_FLAG = 'mageos_seo_config_changed';

    /**
     * The flag value this process last reloaded the configuration for; null before the first build.
     *
     * @var string|null
     */
    private ?string $seen = null;

    /**
     * @param FlagManager $flagManager
     * @param ReinitableConfigInterface $config
     * @param StoreManagerInterface $storeManager
     * @param mixed[] $services ResetAfterRequestInterface instances, by name
     */
    public function __construct(
        private readonly FlagManager               $flagManager,
        private readonly ReinitableConfigInterface $config,
        private readonly StoreManagerInterface     $storeManager,
        private readonly array                     $services = []
    ) {
    }

    /**
     * Bring the process up to date before a build.
     *
     * @return void
     */
    public function refresh(): void
    {
        $mark    = $this->flagManager->getFlagData(self::CONFIG_CHANGED_FLAG);
        $changed = \is_string($mark) ? $mark : '';
        if ($changed !== '' && $changed !== $this->seen) {
            $this->config->reinit();
            $this->storeManager->reinitStores();
        }
        $this->seen = $changed;

        foreach ($this->services as $service) {
            if ($service instanceof ResetAfterRequestInterface) {
                $service->_resetState();
            }
        }
    }
}
