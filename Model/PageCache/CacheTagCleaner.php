<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\PageCache;

use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Indexer\CacheContextFactory;

/**
 * Purges the cached pages carrying given tags, the way core does.
 *
 * A fresh CacheContext holding the tags — so nothing registered elsewhere in the request goes with
 * it — dispatched as clean_cache_by_tags, which the built-in page cache, Varnish invalidation and
 * CDN modules all observe; core's inventory cache flush does the same. When to purge is Purger's
 * business; this is only how.
 */
class CacheTagCleaner
{
    /**
     * @param CacheContextFactory $cacheContextFactory
     * @param EventManagerInterface $eventManager
     */
    public function __construct(
        private readonly CacheContextFactory   $cacheContextFactory,
        private readonly EventManagerInterface $eventManager
    ) {
    }

    /**
     * Purge the pages carrying any of the tags.
     *
     * @param string[] $tags
     * @return void
     */
    public function clean(array $tags): void
    {
        $cacheContext = $this->cacheContextFactory->create();
        $cacheContext->registerTags($tags);
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $cacheContext]);
    }
}
