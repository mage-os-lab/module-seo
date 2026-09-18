<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Feed;

use Magento\CacheInvalidate\Model\PurgeCache;
use Magento\PageCache\Model\Cache\Type as FullPageCache;
use Magento\PageCache\Model\Config as PageCacheConfig;
use MageOS\Seo\Model\Cache\CleaningMode;
use MageOS\Seo\Model\Feed\FeedCache;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use PHPUnit\Framework\TestCase;

class FeedCacheTest extends TestCase
{
    public function testVarnishIsPurgedByTheTagsOfTheRebuiltGroups(): void
    {
        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->once())->method('sendPurgeRequest')->with([
            '((^|,)MAGEOS_SEO_LLMS(,|$))',
            '((^|,)MAGEOS_SEO_LLMS_FULL(,|$))',
            '((^|,)MAGEOS_SEO_HREFLANG_SITEMAP(,|$))',
        ]);
        $fullPageCache = $this->createMock(FullPageCache::class);
        $fullPageCache->expects($this->never())->method('clean');

        $this->feedCache(true, PageCacheConfig::VARNISH, $fullPageCache, $purgeCache)
            ->purge([FeedRegenerator::GROUP_LLMS, FeedRegenerator::GROUP_HREFLANG]);
    }

    public function testBuiltInFullPageCacheIsCleanedByTag(): void
    {
        $fullPageCache = $this->createMock(FullPageCache::class);
        // Literal on purpose: 'matchingAnyTag' is the cross-version contract the
        // cache backend receives (CacheConstants on 2.4.9+, Zend_Cache before).
        $fullPageCache->expects($this->once())->method('clean')
            ->with('matchingAnyTag', ['MAGEOS_SEO_LLMS_JSONL']);
        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->never())->method('sendPurgeRequest');

        $this->feedCache(true, PageCacheConfig::BUILT_IN, $fullPageCache, $purgeCache)
            ->purge([FeedRegenerator::GROUP_JSONL]);
    }

    public function testAllGroupsAreCleanedWithEachTagOnce(): void
    {
        $fullPageCache = $this->createMock(FullPageCache::class);
        $fullPageCache->expects($this->once())->method('clean')->with('matchingAnyTag', [
            'MAGEOS_SEO_LLMS',
            'MAGEOS_SEO_LLMS_FULL',
            'MAGEOS_SEO_LLMS_JSONL',
            'MAGEOS_SEO_HREFLANG_SITEMAP',
        ]);

        $this->feedCache(true, PageCacheConfig::BUILT_IN, $fullPageCache)
            ->purge(array_merge(FeedRegenerator::GROUPS, [FeedRegenerator::GROUP_LLMS]));
    }

    public function testNothingIsPurgedWhenThePageCacheIsDisabled(): void
    {
        $fullPageCache = $this->createMock(FullPageCache::class);
        $fullPageCache->expects($this->never())->method('clean');
        $purgeCache = $this->createMock(PurgeCache::class);
        $purgeCache->expects($this->never())->method('sendPurgeRequest');

        $this->feedCache(false, PageCacheConfig::VARNISH, $fullPageCache, $purgeCache)
            ->purge(FeedRegenerator::GROUPS);
    }

    public function testUnknownOrNoGroupsPurgeNothing(): void
    {
        $fullPageCache = $this->createMock(FullPageCache::class);
        $fullPageCache->expects($this->never())->method('clean');

        $feedCache = $this->feedCache(true, PageCacheConfig::BUILT_IN, $fullPageCache);
        $feedCache->purge([]);
        $feedCache->purge(['unknown']);
    }

    /**
     * Build the cache helper; collaborators a test does not pass are stubs.
     *
     * @param bool $enabled
     * @param int $type
     * @param FullPageCache|null $fullPageCache
     * @param PurgeCache|null $purgeCache
     * @return FeedCache
     */
    private function feedCache(
        bool $enabled,
        int $type,
        ?FullPageCache $fullPageCache = null,
        ?PurgeCache $purgeCache = null
    ): FeedCache {
        $config = $this->createStub(PageCacheConfig::class);
        $config->method('isEnabled')->willReturn($enabled);
        $config->method('getType')->willReturn((string) $type);

        return new FeedCache(
            $config,
            $fullPageCache ?? $this->createStub(FullPageCache::class),
            $purgeCache ?? $this->createStub(PurgeCache::class),
            new CleaningMode()
        );
    }
}
