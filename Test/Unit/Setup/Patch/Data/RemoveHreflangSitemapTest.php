<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Setup\Patch\Data;

use MageOS\Seo\Model\Feed\FeedCache;
use MageOS\Seo\Model\Feed\FeedStorage;
use MageOS\Seo\Model\Feed\RegenerationRequester;
use MageOS\Seo\Setup\Patch\Data\RemoveHreflangSitemap;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The cached responses; the files and the flag are covered against a real install by
 * Test/Integration/Setup/Patch/Data/RemoveHreflangSitemapTest.
 */
class RemoveHreflangSitemapTest extends TestCase
{
    public function testCachedCopiesOfTheRetiredPathArePurged(): void
    {
        $feedCache = $this->createMock(FeedCache::class);
        $feedCache->expects($this->once())->method('purgeTags')->with(['MAGEOS_SEO_HREFLANG_SITEMAP']);

        $this->patch($feedCache)->apply();
    }

    public function testAPurgeFailureIsLoggedAndSetupCarriesOn(): void
    {
        $feedCache = $this->createStub(FeedCache::class);
        $feedCache->method('purgeTags')->willThrowException(new \RuntimeException('varnish down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('varnish down'));

        $this->patch($feedCache, $logger)->apply();
    }

    /**
     * @param FeedCache $feedCache
     * @param LoggerInterface|null $logger
     * @return RemoveHreflangSitemap
     */
    private function patch(FeedCache $feedCache, ?LoggerInterface $logger = null): RemoveHreflangSitemap
    {
        return new RemoveHreflangSitemap(
            $this->createStub(FeedStorage::class),
            $this->createStub(RegenerationRequester::class),
            $feedCache,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }
}
