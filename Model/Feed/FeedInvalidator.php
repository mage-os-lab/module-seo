<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Feed;

use MageOS\Seo\Model\Sitemap\RebuildGroup;

/**
 * Marks pre-generated feeds as outdated by queueing their rebuild.
 *
 * Used by the save observers and the FAQ/Organisation admin controllers. Invalidation
 * deliberately touches neither the feed files nor the page cache: the served files stay in
 * place until the queue consumer has written their replacements, and the consumer purges
 * the cached responses afterwards. Deleting on every save made a single catalog save take
 * a feed offline (503) until the rebuild ran, and repeated the file sweep and the cache
 * purge for every save in a burst.
 *
 * A group that no store view can build is not queued at all (see InvalidationPolicy).
 */
class FeedInvalidator
{
    /**
     * @param RegenerationRequester $regenerationRequester
     * @param InvalidationPolicy $invalidationPolicy
     * @param RebuildGroup $rebuildGroup
     */
    public function __construct(
        private readonly RegenerationRequester $regenerationRequester,
        private readonly InvalidationPolicy    $invalidationPolicy,
        private readonly RebuildGroup          $rebuildGroup
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
        $this->invalidate($this->rebuildGroup->forType($type));
    }

    /**
     * Queue a rebuild of the llms.txt / llms-full.txt feeds.
     *
     * @return void
     */
    public function invalidateLlms(): void
    {
        $this->invalidate(FeedRegenerator::GROUP_LLMS);
    }

    /**
     * Queue a rebuild of the llms.jsonl feed.
     *
     * @return void
     */
    public function invalidateJsonl(): void
    {
        $this->invalidate(FeedRegenerator::GROUP_JSONL);
    }

    /**
     * Queue a rebuild of the group when at least one store view can build it.
     *
     * @param string $group
     * @return void
     */
    private function invalidate(string $group): void
    {
        if ($this->invalidationPolicy->isGroupEnabled($group)) {
            $this->regenerationRequester->request($group);
        }
    }
}
