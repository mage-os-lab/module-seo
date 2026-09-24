<?php

declare(strict_types=1);

namespace MageOS\Seo\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use MageOS\Seo\Model\Migration\HreflangImporter;
use Psr\Log\LoggerInterface;

/**
 * Carries MageOS_Hreflang's settings and CMS page links into this module and switches its head
 * output off, so a store moving over keeps the alternates it was publishing, published once.
 *
 * What is carried and what is switched off is described on Model\Migration\HreflangImporter. The
 * values removed to switch MageOS_Hreflang off are written to the log, so they can be put back by
 * hand.
 *
 * Not revertible, for the reason MigrateMetaRobotsTag gives: by the time anyone reverts, settings
 * made in this module since cannot be told apart from imported ones.
 */
class MigrateHreflang implements DataPatchInterface
{
    /**
     * @param HreflangImporter $importer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly HreflangImporter $importer,
        private readonly LoggerInterface  $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        try {
            $report = $this->importer->import();
        } catch (\Throwable $e) {
            // A failed import must not abort setup:upgrade. MageOS_Hreflang is switched off only
            // once everything else has been carried over, so it is still serving.
            $this->logger->error(
                'MageOS_Seo: could not import MageOS_Hreflang settings: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return $this;
        }

        foreach ($report['notes'] as $note) {
            $this->logger->warning('MageOS_Seo: MageOS_Hreflang import: ' . $note);
        }

        if ($report['switched_off'] !== []) {
            $this->logger->info(
                'MageOS_Seo: switched MageOS_Hreflang output off; the values it replaced follow.',
                ['removed' => $report['switched_off']]
            );
        }

        unset($report['notes'], $report['switched_off']);
        $this->logger->info('MageOS_Seo: imported MageOS_Hreflang settings', $report);

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
