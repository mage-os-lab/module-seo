<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\LlmsTxt;

use Magento\Sitemap\Model\ResourceModel\Sitemap\Collection;
use Magento\Sitemap\Model\ResourceModel\Sitemap\CollectionFactory;
use Magento\Sitemap\Model\Sitemap;
use Magento\Store\Api\Data\StoreInterface;
use MageOS\Seo\Model\LlmsTxt\SitemapUrlResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Doubles Magento's generated sitemap CollectionFactory, so it runs in the unit job inside an
 * installation, not under Infection.
 */
#[Group('magento-generated')]
class SitemapUrlResolverTest extends TestCase
{
    /**
     * @param Sitemap[] $sitemaps
     * @param int[] $filteredStores receives the store filter
     */
    private function resolve(array $sitemaps, array &$filteredStores = []): ?string
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addStoreFilter')->willReturnCallback(
            function (array $ids) use (&$filteredStores, $collection) {
                $filteredStores = $ids;
                return $collection;
            }
        );
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getIterator')->willReturn(new \ArrayIterator($sitemaps));

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(2);

        return (new SitemapUrlResolver($factory))->getUrl($store);
    }

    private function sitemap(string $path, string $fileName, string $url): Sitemap
    {
        $sitemap = $this->getMockBuilder(Sitemap::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSitemapUrl', 'getData'])
            ->getMock();
        $sitemap->method('getData')->willReturnCallback(
            fn (string $key = '') => ['sitemap_path' => $path, 'sitemap_filename' => $fileName][$key] ?? null
        );
        $sitemap->method('getSitemapUrl')->with($path, $fileName)->willReturn($url);

        return $sitemap;
    }

    public function testUsesCoresUrlForTheStoreViewsLatestSitemap(): void
    {
        $stores = [];
        $url = $this->resolve(
            [$this->sitemap('/media/sitemap/', 'sitemap_de.xml', 'https://shop.test/media/sitemap/sitemap_de.xml')],
            $stores
        );

        $this->assertSame('https://shop.test/media/sitemap/sitemap_de.xml', $url);
        $this->assertSame([2], $stores);
    }

    public function testNoSitemapOrNoFileNameGivesNull(): void
    {
        $this->assertNull($this->resolve([]));
        $this->assertNull($this->resolve([$this->sitemap('/', '', '')]));
    }

    public function testAReadFailureIsNotSwallowed(): void
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willThrowException(new \RuntimeException('db down'));
        $store = $this->createStub(StoreInterface::class);

        $this->expectException(\RuntimeException::class);

        (new SitemapUrlResolver($factory))->getUrl($store);
    }
}
