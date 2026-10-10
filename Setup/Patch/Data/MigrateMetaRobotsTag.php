<?php

declare(strict_types=1);

namespace MageOS\Seo\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;
use MageOS\Seo\Model\Migration\LegacyImport;

/**
 * Carries MageOS_MetaRobotsTag's per-entity robots flags into this module's settings, on
 * setup:upgrade, so a merchant who had that module keeps the directives their pages were serving.
 *
 * The import is tried once here and never fails the upgrade. Whether it finished is recorded by
 * Model\Migration\LegacyImport, not by this patch, which core records as applied either way: while
 * it has not finished, the admin says so, and `bin/magento seo:import-legacy` runs it again.
 *
 * Not run inside the patch applier's transaction. Inside it, a save that fails rolls back a nested
 * transaction, which makes the applier's own commit throw, and that fails the whole upgrade over an
 * optional import. Each save commits on its own instead. An import stopped part way keeps what it
 * wrote, and the next run fills only what is still empty.
 *
 * Not revertible. Reverting would mean deleting settings, and by then this module's own settings
 * cannot be told apart from imported ones: the merchant would lose work they did after the upgrade.
 * Uninstalling this module drops its tables anyway.
 */
class MigrateMetaRobotsTag implements DataPatchInterface, NonTransactionableInterface
{
    /**
     * @param LegacyImport $legacyImport
     */
    public function __construct(
        private readonly LegacyImport $legacyImport
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        $this->legacyImport->runIfUnfinished(LegacyImport::META_ROBOTS_TAG);

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
