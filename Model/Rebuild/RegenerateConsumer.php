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
 * actually keep two writers apart. When another process holds the lock, this one waits for it,
 * up to lockWaitSeconds, rather than putting the message straight back: put back at once, a free
 * consumer takes it again at once and spins for as long as the other build runs. Only a wait that
 * runs out puts the request back.
 *
 * Each message is one long-running process's next build, so it starts from current data
 * (BuildFreshness).
 */
class RegenerateConsumer
{
    /**
     * @param HandlerPool $handlerPool
     * @param RegenerationRequester $regenerationRequester
     * @param LoggerInterface $logger
     * @param SitemapRebuilder $sitemapRebuilder
     * @param RebuildGroup $rebuildGroup
     * @param ProblemLog $problemLog
     * @param BuildFreshness $buildFreshness
     * @param Pause $pause
     * @param int $lockWaitSeconds How long to wait for a rebuild another process is running
     * @param int $lockRetrySeconds How often to try again while waiting
     */
    public function __construct(
        private readonly HandlerPool           $handlerPool,
        private readonly RegenerationRequester $regenerationRequester,
        private readonly LoggerInterface       $logger,
        private readonly SitemapRebuilder      $sitemapRebuilder,
        private readonly RebuildGroup          $rebuildGroup,
        private readonly ProblemLog            $problemLog,
        private readonly BuildFreshness        $buildFreshness,
        private readonly Pause                 $pause,
        private readonly int                   $lockWaitSeconds = 600,
        private readonly int                   $lockRetrySeconds = 15
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
        // A message taken is the queue being processed: whatever had stalled is moving again.
        $this->problemLog->resumed();

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

        $this->buildFreshness->refresh();

        // Clear the pending flag BEFORE building: invalidations arriving while we
        // build must queue exactly one follow-up rebuild, not be lost.
        $this->regenerationRequester->acknowledge($group);

        // What the rebuild reports — its failures, and what a handler marks degraded while it
        // runs — becomes the group's problems in the admin (see ProblemLog).
        $this->problemLog->rebuilding($group);
        $waited = 0;
        while (true) {
            try {
                if ($firstBuild) {
                    $failures = $this->sitemapRebuilder->buildMissing();
                } elseif ($sitemapType !== null) {
                    $failures = $this->sitemapRebuilder->rebuild($sitemapType);
                } else {
                    $failures = $handler !== null ? $handler->rebuild($group) : [];
                }
                $this->problemLog->rebuilt($group, $failures);
                return;
            } catch (RebuildInProgressException) {
                if ($waited < $this->lockWaitSeconds) {
                    $retry = max(1, $this->lockRetrySeconds);
                    $this->pause->seconds($retry);
                    $waited += $retry;
                    continue;
                }

                $this->problemLog->abandoned($group);
                // The flag was cleared before the wait, so dropping this message would lose the
                // invalidation that caused it: the build that holds the lock may already have
                // passed the data this message was about. Ask again instead.
                $this->regenerationRequester->request($group);
                $this->logger->info(
                    'MageOS_Seo: a rebuild of ' . $group . ' was still running after ' . $waited
                    . ' seconds; re-queued.'
                );
                return;
            } catch (\Throwable $e) {
                $this->problemLog->rebuilt($group, [ProblemLog::ALL => $e->getMessage()]);
                throw $e;
            }
        }
    }
}
