<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap;

use Magento\Framework\App\Area;
use Magento\Store\Model\App\Emulation;
use MageOS\Seo\Exception\SitemapRebuildInProgressException;
use Psr\Log\LoggerInterface;

/**
 * Rewrites one type of page — or all of them — in every sitemap this module keeps current.
 *
 * Reached from the feed queue: a change to products, categories or pages queues `sitemap-{type}`,
 * a change that can alter every URL (a store view, the configuration) queues `sitemap-*` (see
 * RebuildGroup), and the consumer hands the type here. Every sitemap RebuildableSitemaps names is
 * rewritten — that type's files only, the others kept; every type for `*`.
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
        return $type === RebuildGroup::ALL_TYPES || \in_array($type, $this->generator->getTypes(), true);
    }

    /**
     * Rewrite the type — every type for `*` — in every sitemap that qualifies.
     *
     * @param string $type A type the generator writes, or RebuildGroup::ALL_TYPES
     * @throws SitemapRebuildInProgressException When one was being written by another process —
     *                                           after all the others are done
     * @return array<int,string> Error message per sitemap ID that failed
     */
    public function rebuild(string $type): array
    {
        $types    = $type === RebuildGroup::ALL_TYPES ? null : [$type];
        $failures = [];
        $busy     = [];

        foreach ($this->rebuildableSitemaps->all() as $sitemap) {
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
