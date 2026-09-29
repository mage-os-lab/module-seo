<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Rebuild;

use MageOS\Seo\Exception\RebuildInProgressException;
use MageOS\Seo\Model\Sitemap\Rebuilder as SitemapRebuilder;
use MageOS\Seo\Model\Sitemap\RebuildGroup;
use Psr\Log\LoggerInterface;

/**
 * Queue consumer that rebuilds one group after an invalidation.
 *
 * A message is `sitemap-{type}` (one type of every sitemap), `sitemaps-missing` (the first build of
 * the sitemaps that have no file — Sitemap\Rebuilder), or a group a registered handler owns
 * (HandlerPool) — the llms documents, for example.
 *
 * Messages are taken serially, so one consumer process never overlaps itself. That is not the
 * same as a guarantee: `consumers_runner` can be configured to run several processes of this
 * consumer, on a multi-server install the nightly cron runs on every node, and the CLI command
 * writes the same files on demand. The handlers' locks and each sitemap's GenerationLock are what
 * actually keep two writers apart; this class's part is to put the request back when it loses the
 * race.
 */
class RegenerateConsumer
{
    /**
     * @param HandlerPool $handlerPool
     * @param RegenerationRequester $regenerationRequester
     * @param LoggerInterface $logger
     * @param SitemapRebuilder $sitemapRebuilder
     * @param RebuildGroup $rebuildGroup
     */
    public function __construct(
        private readonly HandlerPool           $handlerPool,
        private readonly RegenerationRequester $regenerationRequester,
        private readonly LoggerInterface       $logger,
        private readonly SitemapRebuilder      $sitemapRebuilder,
        private readonly RebuildGroup          $rebuildGroup
    ) {
    }

    /**
     * Rebuild the requested group: a sitemap type, the sitemaps that have no file, or a handler's.
     *
     * A handler's group is built for all store views; a sitemap type in every sitemap a change
     * rebuilds.
     *
     * @param string $group A handler's group, `sitemap-{type}`, or RebuildGroup::MISSING
     * @return void
     */
    public function process(string $group): void
    {
        $firstBuild  = $group === RebuildGroup::MISSING;
        $sitemapType = $this->rebuildGroup->typeOf($group);
        $handler     = $firstBuild || $sitemapType !== null ? null : $this->handlerPool->get($group);
        if (!$firstBuild && $sitemapType === null && $handler === null) {
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
            if ($firstBuild) {
                $this->sitemapRebuilder->buildMissing();
            } elseif ($sitemapType !== null) {
                $this->sitemapRebuilder->rebuild($sitemapType);
            } elseif ($handler !== null) {
                $handler->rebuild($group);
            }
        } catch (RebuildInProgressException) {
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
