<?php

declare(strict_types=1);

namespace MageOS\Seo\Setup;

use Magento\Framework\Setup\InstallDataInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use MageOS\Seo\Model\Rebuild\HandlerPool;
use MageOS\Seo\Model\Rebuild\Invalidator;
use Psr\Log\LoggerInterface;

/**
 * Queues a rebuild of every SEO feed after each `setup:install` / `setup:upgrade`.
 *
 * A fresh install has no feed files (the feeds would answer 503 until the nightly cron),
 * and a deployment can change what the feeds contain or clear the storage directory. The
 * queue consumer builds the feeds shortly afterwards; nothing is built inside setup itself.
 * The feeds are every group registered with the rebuild queue (Model\Rebuild\HandlerPool); those no
 * store view can build are skipped (see Invalidator::invalidate()).
 *
 * Of the XML sitemaps, only those with no file yet are queued (Invalidator::
 * invalidateMissingSitemaps()): a fresh install with Site Map entries already configured, or a node
 * whose pub/media was not carried over. The rest live in pub/, which a deployment does not clear,
 * and Magento's cron regenerates them; rewriting every sitemap on every deployment is not worth it.
 */
class RecurringData implements InstallDataInterface
{
    /**
     * @param Invalidator $invalidator
     * @param HandlerPool $handlerPool
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Invalidator     $invalidator,
        private readonly HandlerPool     $handlerPool,
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
            foreach ($this->handlerPool->getGroups() as $group) {
                $this->invalidator->invalidate($group);
            }
            $this->invalidator->invalidateMissingSitemaps();
        } catch (\Throwable $e) {
            $this->logger->error(
                'MageOS_Seo: could not queue the SEO feed rebuild after setup: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
