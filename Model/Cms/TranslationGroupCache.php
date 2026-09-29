<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Cms;

use Magento\Cms\Model\Page;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Indexer\CacheContextFactory;

/**
 * Purges the cached pages of CMS translation groups.
 *
 * Every page in a group lists the others as hreflang alternates in its head. When a page joins,
 * leaves or is deleted from a group, the other pages' cached copies still carry the old list — and
 * each is a different entity, so the change never touches their cache tags. This clears them.
 *
 * Done the way core's inventory cache flush does it: a fresh CacheContext, so nothing registered
 * elsewhere in the request is flushed with it, dispatched as clean_cache_by_tags, which both the
 * built-in full page cache and Varnish invalidation observe.
 */
class TranslationGroupCache
{
    /**
     * @param ConfigRepository $configRepository
     * @param CacheContextFactory $cacheContextFactory
     * @param EventManagerInterface $eventManager
     */
    public function __construct(
        private readonly ConfigRepository      $configRepository,
        private readonly CacheContextFactory   $cacheContextFactory,
        private readonly EventManagerInterface $eventManager
    ) {
    }

    /**
     * Purge the cached pages of every page in the given groups.
     *
     * @param array<int,string|null> $groups Normalised groups; nulls (no group) are ignored
     * @return void
     */
    public function purge(array $groups): void
    {
        $pageIds = $this->configRepository->getPageIdsInGroups(array_values(array_filter($groups, 'is_string')));
        if ($pageIds === []) {
            return;
        }

        $cacheContext = $this->cacheContextFactory->create();
        $cacheContext->registerEntities(Page::CACHE_TAG, $pageIds);
        $this->eventManager->dispatch('clean_cache_by_tags', ['object' => $cacheContext]);
    }
}
