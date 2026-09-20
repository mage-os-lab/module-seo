<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Feed;

use MageOS\Seo\Exception\FeedRebuildInProgressException;
use Psr\Log\LoggerInterface;

/**
 * Queue consumer that rebuilds one feed group after an invalidation.
 *
 * Messages are taken serially, so one consumer process never overlaps itself. That is not the
 * same as a guarantee: `consumers_runner` can be configured to run several processes of this
 * consumer, on a multi-server install the nightly cron runs on every node, and the CLI command
 * writes the same files on demand. RebuildLock is what actually keeps two whole-catalog builds
 * apart; this class's part is to put the request back when it loses the race.
 */
class RegenerateConsumer
{
    /**
     * @param FeedRegenerator $feedRegenerator
     * @param RegenerationRequester $regenerationRequester
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly FeedRegenerator       $feedRegenerator,
        private readonly RegenerationRequester $regenerationRequester,
        private readonly LoggerInterface       $logger
    ) {
    }

    /**
     * Rebuild the requested feed group for all stores.
     *
     * @param string $group One of FeedRegenerator::GROUPS
     * @return void
     */
    public function process(string $group): void
    {
        if (!\in_array($group, FeedRegenerator::GROUPS, true)) {
            $this->logger->warning('MageOS_Seo: unknown feed group in regeneration queue: ' . $group);
            return;
        }

        // Clear the pending flag BEFORE building: invalidations arriving while we
        // build must queue exactly one follow-up rebuild, not be lost.
        $this->regenerationRequester->acknowledge($group);

        try {
            $this->feedRegenerator->regenerate($group);
        } catch (FeedRebuildInProgressException) {
            // The flag was cleared a moment ago, so dropping this message would lose the
            // invalidation that caused it: the build that holds the lock may already have passed
            // the data this message was about. Ask again instead.
            $this->regenerationRequester->request($group);
            $this->logger->info(
                'MageOS_Seo: a rebuild of the ' . $group . ' feeds is already running; re-queued.'
            );
        }
    }
}
