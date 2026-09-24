<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Feed;

use MageOS\Seo\Exception\FeedRebuildInProgressException;
use MageOS\Seo\Exception\SitemapRebuildInProgressException;
use MageOS\Seo\Model\Sitemap\RebuildGroup;
use MageOS\Seo\Model\Sitemap\Rebuilder as SitemapRebuilder;
use Psr\Log\LoggerInterface;

/**
 * Queue consumer that rebuilds one feed group, or one type of every sitemap, after an invalidation.
 *
 * A message is a feed group (FeedRegenerator::GROUPS) or `sitemap-{type}` (Sitemap\Rebuilder).
 *
 * Messages are taken serially, so one consumer process never overlaps itself. That is not the
 * same as a guarantee: `consumers_runner` can be configured to run several processes of this
 * consumer, on a multi-server install the nightly cron runs on every node, and the CLI command
 * writes the same files on demand. RebuildLock and each sitemap's GenerationLock are what actually
 * keep two writers apart; this class's part is to put the request back when it loses the race.
 */
class RegenerateConsumer
{
    /**
     * @param FeedRegenerator $feedRegenerator
     * @param RegenerationRequester $regenerationRequester
     * @param LoggerInterface $logger
     * @param SitemapRebuilder $sitemapRebuilder
     * @param RebuildGroup $rebuildGroup
     */
    public function __construct(
        private readonly FeedRegenerator       $feedRegenerator,
        private readonly RegenerationRequester $regenerationRequester,
        private readonly LoggerInterface       $logger,
        private readonly SitemapRebuilder      $sitemapRebuilder,
        private readonly RebuildGroup          $rebuildGroup
    ) {
    }

    /**
     * Rebuild the requested feed group for all stores, or the requested type of every sitemap.
     *
     * @param string $group One of FeedRegenerator::GROUPS, or `sitemap-{type}`
     * @return void
     */
    public function process(string $group): void
    {
        $sitemapType = $this->rebuildGroup->typeOf($group);
        if ($sitemapType === null && !\in_array($group, FeedRegenerator::GROUPS, true)) {
            $this->logger->warning('MageOS_Seo: unknown feed group in regeneration queue: ' . $group);
            return;
        }
        if ($sitemapType !== null && !$this->sitemapRebuilder->hasType($sitemapType)) {
            $this->logger->warning('MageOS_Seo: no sitemap provider has the type in regeneration queue: ' . $group);
            return;
        }

        // Clear the pending flag BEFORE building: invalidations arriving while we
        // build must queue exactly one follow-up rebuild, not be lost.
        $this->regenerationRequester->acknowledge($group);

        try {
            if ($sitemapType === null) {
                $this->feedRegenerator->regenerate($group);
            } else {
                $this->sitemapRebuilder->rebuild($sitemapType);
            }
        } catch (FeedRebuildInProgressException | SitemapRebuildInProgressException) {
            // The flag was cleared a moment ago, so dropping this message would lose the
            // invalidation that caused it: the build that holds the lock may already have passed
            // the data this message was about. Ask again instead.
            $this->regenerationRequester->request($group);
            $this->logger->info(
                'MageOS_Seo: a rebuild of ' . $group . ' is already running; re-queued.'
            );
        }
    }
}
