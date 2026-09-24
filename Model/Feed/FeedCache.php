<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Feed;

use Magento\CacheInvalidate\Model\PurgeCache;
use Magento\PageCache\Model\Cache\Type as FullPageCache;
use Magento\PageCache\Model\Config as PageCacheConfig;
use MageOS\Seo\Model\Cache\CleaningMode;

/**
 * HTTP cache policy of the served feeds, and purging of their cached responses.
 *
 * The feed controllers send CACHE_CONTROL and tag their responses; FeedRegenerator purges
 * those tags once a feed group has been rebuilt, so cached copies are replaced by the new
 * files rather than dropped while the rebuild is still pending.
 */
class FeedCache
{
    /**
     * Cache lifetime of every served feed: 24 hours for browsers and shared caches.
     */
    public const CACHE_CONTROL = 'public, max-age=86400, s-maxage=86400';

    public const TAG_LLMS       = 'MAGEOS_SEO_LLMS';
    public const TAG_LLMS_FULL  = 'MAGEOS_SEO_LLMS_FULL';
    public const TAG_LLMS_JSONL = 'MAGEOS_SEO_LLMS_JSONL';

    private const GROUP_TAGS = [
        FeedRegenerator::GROUP_LLMS  => [self::TAG_LLMS, self::TAG_LLMS_FULL],
        FeedRegenerator::GROUP_JSONL => [self::TAG_LLMS_JSONL],
    ];

    /**
     * @param PageCacheConfig $pageCacheConfig
     * @param FullPageCache $fullPageCache
     * @param PurgeCache $purgeCache
     * @param CleaningMode $cleaningMode
     */
    public function __construct(
        private readonly PageCacheConfig $pageCacheConfig,
        private readonly FullPageCache   $fullPageCache,
        private readonly PurgeCache      $purgeCache,
        private readonly CleaningMode    $cleaningMode
    ) {
    }

    /**
     * Purge the cached responses of the given feed groups.
     *
     * Varnish is purged by tag; the built-in full page cache is cleaned by tag (mirrors
     * core FlushCacheByTags). Nothing happens when the full page cache is disabled.
     *
     * @param string[] $groups FeedRegenerator::GROUP_* values
     * @return void
     */
    public function purge(array $groups): void
    {
        $tags = [];
        foreach ($groups as $group) {
            foreach (self::GROUP_TAGS[$group] ?? [] as $tag) {
                $tags[$tag] = $tag;
            }
        }

        $this->purgeTags(array_values($tags));
    }

    /**
     * Purge the cached responses carrying any of the tags.
     *
     * Also a tag no group has any more, such as a retired feed's (see
     * Setup\Patch\Data\RemoveHreflangSitemap).
     *
     * @param string[] $tags
     * @return void
     */
    public function purgeTags(array $tags): void
    {
        $tags = array_values(array_unique($tags));
        if ($tags === [] || !$this->pageCacheConfig->isEnabled()) {
            return;
        }

        if ((int) $this->pageCacheConfig->getType() === PageCacheConfig::VARNISH) {
            $purgeTags = [];
            foreach ($tags as $tag) {
                $purgeTags[] = \sprintf('((^|,)%s(,|$))', $tag);
            }
            $this->purgeCache->sendPurgeRequest($purgeTags);
            return;
        }

        $this->fullPageCache->clean($this->cleaningMode->matchingAnyTag(), $tags);
    }
}
