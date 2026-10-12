<?php

declare(strict_types=1);

namespace MageOS\Seo\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\NonTransactionableInterface;
use MageOS\Seo\Model\Migration\LegacyImport;

/**
 * Carries MageOS_Hreflang's settings and CMS page links into this module and switches its head
 * output off, on setup:upgrade, so a store moving over keeps the alternates it was publishing,
 * published once.
 *
 * What is carried and what is switched off is described on Model\Migration\HreflangImporter. The
 * values removed to switch MageOS_Hreflang off are written to the log, so they can be put back by
 * hand.
 *
 * Tried once and never fails the upgrade, and not run inside the patch applier's transaction, for
 * the reasons MigrateMetaRobotsTag gives. MageOS_Hreflang is switched off only once everything else
 * has been carried over, so an import that fails leaves it serving.
 *
 * Not revertible, for the reason MigrateMetaRobotsTag gives: by the time anyone reverts, settings
 * made in this module since cannot be told apart from imported ones.
 */
class MigrateHreflang implements DataPatchInterface, NonTransactionableInterface
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
        $this->legacyImport->runIfUnfinished(LegacyImport::HREFLANG);

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
