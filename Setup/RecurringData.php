<?php

declare(strict_types=1);

namespace MageOS\Seo\Setup;

use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use MageOS\Seo\Model\Feed\FeedInvalidator;
use Psr\Log\LoggerInterface;

/**
 * Queues a rebuild of every SEO feed after each `setup:install` / `setup:upgrade`.
 *
 * A fresh install has no feed files (the feeds would answer 503 until the nightly cron),
 * and a deployment can change what the feeds contain or clear the storage directory. The
 * queue consumer builds the feeds shortly afterwards; nothing is built inside setup itself.
 * Feeds no store view can build are skipped (see InvalidationPolicy).
 *
 * The XML sitemaps are not queued: they live in pub/, which a deployment does not clear, and
 * Magento's cron regenerates them. Queueing every type on every deployment would rewrite every
 * sitemap each time.
 */
class RecurringData implements InstallDataInterface
{
    /**
     * @param FeedInvalidator $feedInvalidator
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly FeedInvalidator $feedInvalidator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Queue the feed rebuilds; a failure is logged and never breaks the setup run.
     *
     * @param ModuleDataSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function install(ModuleDataSetupInterface $setup, ModuleContextInterface $context): void
    {
        try {
            $this->feedInvalidator->invalidateLlms();
            $this->feedInvalidator->invalidateJsonl();
        } catch (\Throwable $e) {
            $this->logger->error(
                'MageOS_Seo: could not queue the SEO feed rebuild after setup: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
