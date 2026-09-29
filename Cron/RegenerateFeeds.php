<?php

declare(strict_types=1);

namespace MageOS\Seo\Cron;

use MageOS\Seo\Exception\FeedRebuildInProgressException;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use Psr\Log\LoggerInterface;

/**
 * Nightly full rebuild of all pre-generated SEO feeds.
 *
 * Safety net alongside the event-driven queue consumer: catches drift from
 * changes that carry no invalidation event (config edits, imports, URL suffix
 * changes) and re-creates files lost to deployments or cache clears.
 */
class RegenerateFeeds
{
    /**
     * @param FeedRegenerator $feedRegenerator
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly FeedRegenerator $feedRegenerator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Regenerate every enabled feed for every active store view.
     *
     * @return void
     */
    public function execute(): void
    {
        try {
            $this->feedRegenerator->regenerate();
        } catch (FeedRebuildInProgressException) {
            // Nothing to recover: the process holding the lock is doing this same work, and the
            // cron comes round again. On a multi-server install every node runs this, so one of
            // them losing the race is the normal case rather than a fault.
            $this->logger->info('MageOS_Seo: nightly feed rebuild skipped, another is running.');
        }
    }
}
