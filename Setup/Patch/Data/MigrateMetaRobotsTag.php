<?php

declare(strict_types=1);

namespace MageOS\Seo\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use MageOS\Seo\Model\Migration\MetaRobotsTagImporter;
use Psr\Log\LoggerInterface;

/**
 * Carries MageOS_MetaRobotsTag's per-entity robots flags into this module's overrides.
 *
 * Runs once, on setup:upgrade, so a merchant who had that module keeps the directives their pages
 * were serving instead of silently falling back to store defaults the first time this module's
 * robots settings are used.
 *
 * Not revertible. Reverting would mean deleting overrides, and by then this module's own settings
 * are indistinguishable from the imported ones — the merchant would lose work they did after the
 * upgrade. Uninstalling this module drops its tables anyway.
 */
class MigrateMetaRobotsTag implements DataPatchInterface
{
    /**
     * @param MetaRobotsTagImporter $importer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly MetaRobotsTagImporter $importer,
        private readonly LoggerInterface       $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        try {
            $written = $this->importer->import();
        } catch (\Throwable $e) {
            // A failed import must not abort setup:upgrade and leave the installation half
            // migrated: the flags stay where they are and can be imported again.
            $this->logger->error(
                'MageOS_Seo: could not import MageOS_MetaRobotsTag settings: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $this;
        }

        if (array_sum($written) > 0) {
            $this->logger->info('MageOS_Seo: imported MageOS_MetaRobotsTag settings', $written);
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
