<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap;

use Magento\Framework\App\Area;
use Magento\Sitemap\Model\Sitemap;
use Magento\Store\Model\App\Emulation;
use MageOS\Seo\Exception\SitemapRebuildInProgressException;
use Psr\Log\LoggerInterface;

/**
 * Rewrites one type of page — or all of them — in every sitemap this module keeps current.
 *
 * Reached from the feed queue: a change to products, categories or pages queues `sitemap-{type}`,
 * a change that can alter every URL (a store view, the configuration) queues `sitemap-*` (see
 * RebuildGroup), and the consumer hands the type to `rebuild()`, which rewrites every sitemap a
 * change rebuilds. The CLI command's `rebuildOnDemand()` rewrites every sitemap that can be rebuilt,
 * Rebuild on Change or not (see RebuildableSitemaps). Only that type's files are rewritten, the
 * others kept; every type for `*`, and every type of a sitemap that has no file yet.
 * `buildMissing()` writes only the sitemaps that have none (RebuildGroup::MISSING).
 *
 * Each is written as core's cron writes it, emulating its store view's frontend. One that fails is
 * logged and the rest carry on; one being written by another process is left for the retry the
 * caller queues.
 */
class Rebuilder
{
    /**
     * @param RebuildableSitemaps $rebuildableSitemaps
     * @param Emulation $emulation
     * @param Generator $generator
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RebuildableSitemaps $rebuildableSitemaps,
        private readonly Emulation           $emulation,
        private readonly Generator           $generator,
        private readonly LoggerInterface     $logger
    ) {
    }

    /**
     * Whether a request for the type can be carried out: the generator writes it, or it is `*`.
     *
     * @param string $type
     * @return bool
     */
    public function hasType(string $type): bool
    {
        return $type === RebuildGroup::ALL_TYPES || \in_array($type, $this->types(), true);
    }

    /**
     * The types the generator writes, in order.
     *
     * @return string[]
     */
    public function types(): array
    {
        return $this->generator->getTypes();
    }

    /**
     * After a change: rewrite the type — every type for `*` — in every sitemap a change rebuilds.
     *
     * @param string $type A type the generator writes, or RebuildGroup::ALL_TYPES
     * @throws SitemapRebuildInProgressException When one was being written by another process —
     *                                           after all the others are done
     * @return array<int,string> Error message per sitemap ID that failed
     */
    public function rebuild(string $type): array
    {
        return $this->rebuildEach($type, $this->rebuildableSitemaps->onChange());
    }

    /**
     * Asked for by hand: rewrite the type in every sitemap that can be rebuilt, Rebuild on Change or not.
     *
     * @param string $type A type the generator writes, or RebuildGroup::ALL_TYPES
     * @throws SitemapRebuildInProgressException When one was being written by another process —
     *                                           after all the others are done
     * @return array<int,string> Error message per sitemap ID that failed
     */
    public function rebuildOnDemand(string $type): array
    {
        return $this->rebuildEach($type, $this->rebuildableSitemaps->all());
    }

    /**
     * A first build: write, whole, every sitemap a change rebuilds that has no file yet.
     *
     * The files are looked for now, not when the build was queued: one generated meanwhile — by
     * the admin's Save & Generate, say — is left as it is.
     *
     * @throws SitemapRebuildInProgressException When one was being written by another process —
     *                                           after all the others are done
     * @return array<int,string> Error message per sitemap ID that failed
     */
    public function buildMissing(): array
    {
        return $this->rebuildEach(RebuildGroup::ALL_TYPES, $this->rebuildableSitemaps->missing());
    }

    /**
     * Rewrite the type in each of the sitemaps.
     *
     * @param string $type
     * @param Sitemap[] $sitemaps
     * @throws SitemapRebuildInProgressException
     * @return array<int,string> Error message per sitemap ID that failed
     */
    private function rebuildEach(string $type, array $sitemaps): array
    {
        $types    = $type === RebuildGroup::ALL_TYPES ? null : [$type];
        $failures = [];
        $busy     = [];

        foreach ($sitemaps as $sitemap) {
            $this->emulation->startEnvironmentEmulation((int) $sitemap->getStoreId(), Area::AREA_FRONTEND, true);
            try {
                $this->generator->regenerate($sitemap, $types);
            } catch (SitemapRebuildInProgressException) {
                $busy[] = (string) $sitemap->getSitemapFilename();
            } catch (\Throwable $e) {
                $failures[(int) $sitemap->getId()] = $e->getMessage();
                $this->logger->error(
                    \sprintf(
                        'MageOS_Seo: rebuilding the %s of sitemap %s failed: %s',
                        $type === RebuildGroup::ALL_TYPES ? 'files' : $type,
                        $sitemap->getSitemapFilename(),
                        $e->getMessage()
                    ),
                    ['exception' => $e]
                );
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }
        }

        if ($busy !== []) {
            throw new SitemapRebuildInProgressException(__(
                'Sitemaps being written by another process were not rebuilt: %1.',
                implode(', ', $busy)
            ));
        }

        return $failures;
    }
}
