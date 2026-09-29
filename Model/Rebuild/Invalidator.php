<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Rebuild;

use MageOS\Seo\Model\Sitemap\InvalidationPolicy as SitemapInvalidationPolicy;
use MageOS\Seo\Model\Sitemap\RebuildGroup;

/**
 * Marks pre-generated output as outdated by queueing its rebuild.
 *
 * Used by the change observers, the setup run and the sitemap's own requests, and by any module
 * whose group handler is registered in HandlerPool. Invalidation deliberately touches neither the
 * files nor the page cache: the served files stay in place until the queue consumer has written
 * their replacements, and the rebuild purges the cached responses afterwards. Deleting on every
 * save made a single catalog save take a feed offline (503) until the rebuild ran, and repeated the
 * file sweep and the cache purge for every save in a burst.
 *
 * A group that nothing can build is not queued at all: a sitemap group while no sitemap would be
 * rebuilt, a handler's group while its handler reports it disabled everywhere.
 */
class Invalidator
{
    /**
     * @param RegenerationRequester $regenerationRequester
     * @param SitemapInvalidationPolicy $sitemapInvalidationPolicy
     * @param RebuildGroup $rebuildGroup
     * @param HandlerPool $handlerPool
     */
    public function __construct(
        private readonly RegenerationRequester     $regenerationRequester,
        private readonly SitemapInvalidationPolicy $sitemapInvalidationPolicy,
        private readonly RebuildGroup              $rebuildGroup,
        private readonly HandlerPool               $handlerPool
    ) {
    }

    /**
     * Queue a rebuild of one type of every sitemap this module keeps current.
     *
     * @param string $type A sitemap type, or RebuildGroup::ALL_TYPES for every type
     * @return void
     */
    public function invalidateSitemap(string $type): void
    {
        if ($this->sitemapInvalidationPolicy->isEnabled()) {
            $this->regenerationRequester->request($this->rebuildGroup->forType($type));
        }
    }

    /**
     * Queue the first build of the sitemaps this module keeps current that have no file yet.
     *
     * Which ones is decided when the build runs, so asking when there are none costs one message.
     *
     * @return void
     */
    public function invalidateMissingSitemaps(): void
    {
        if ($this->sitemapInvalidationPolicy->isEnabled()) {
            $this->regenerationRequester->request(RebuildGroup::MISSING);
        }
    }

    /**
     * Queue a rebuild of a registered handler's group, when at least one store view can build it.
     *
     * @param string $group A group a handler in HandlerPool owns
     * @throws \InvalidArgumentException When no registered handler owns the group
     * @return void
     */
    public function invalidate(string $group): void
    {
        $handler = $this->handlerPool->get($group);
        if ($handler === null) {
            throw new \InvalidArgumentException(\sprintf(
                'No rebuild handler owns the group "%s". Registered groups: %s.',
                $group,
                implode(', ', $this->handlerPool->getGroups()) ?: 'none'
            ));
        }

        if ($handler->isEnabled($group)) {
            $this->regenerationRequester->request($group);
        }
    }
}
