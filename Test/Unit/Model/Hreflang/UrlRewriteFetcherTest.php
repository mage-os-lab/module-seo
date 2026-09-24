<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Hreflang;

use MageOS\Seo\Model\Hreflang\UrlRewriteFetcher;
use MageOS\Seo\Model\ResourceModel\UrlRewrite as UrlRewriteResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Grouping and store precedence only. Which rows the queries return — including the published
 * filter that keeps disabled entities out — belongs to the resource model and is covered against
 * a real database by Test/Integration/Model/Hreflang/PublishedEntitiesOnlyTest.
 */
class UrlRewriteFetcherTest extends TestCase
{
    /**
     * @var UrlRewriteResource&MockObject
     */
    private UrlRewriteResource&MockObject $resource;

    /**
     * @var UrlRewriteFetcher
     */
    private UrlRewriteFetcher $fetcher;

    protected function setUp(): void
    {
        $this->resource = $this->createMock(UrlRewriteResource::class);
        $this->fetcher  = new UrlRewriteFetcher($this->resource);
    }

    public function testReturnsPathsKeyedByStore(): void
    {
        $this->resource->method('getPathsForEntity')->willReturn([
            ['store_id' => '1', 'request_path' => 'uk-path'],
            ['store_id' => '2', 'request_path' => 'us-path'],
        ]);
        $this->assertSame(
            [1 => 'uk-path', 2 => 'us-path'],
            $this->fetcher->fetchForEntity('product', 5)
        );
    }

    public function testFirstRowPerStoreWins(): void
    {
        $this->resource->method('getPathsForEntity')->willReturn([
            ['store_id' => '1', 'request_path' => 'current'],
            ['store_id' => '1', 'request_path' => 'old-history'],
        ]);
        $this->assertSame([1 => 'current'], $this->fetcher->fetchForEntity('product', 5));
    }

    public function testReturnsEmptyWhenNoRewrites(): void
    {
        $this->resource->method('getPathsForEntity')->willReturn([]);
        $this->assertSame([], $this->fetcher->fetchForEntity('cms-page', 9));
    }

    public function testFetchForEntitiesGivesEachEntityItsFirstPathPerStore(): void
    {
        $this->resource->method('getPathsForEntities')->with('product', [5, 6])->willReturn([
            ['entity_id' => '5', 'store_id' => '1', 'request_path' => 'a'],
            ['entity_id' => '5', 'store_id' => '1', 'request_path' => 'a-history'],
            ['entity_id' => '5', 'store_id' => '2', 'request_path' => 'a-de'],
            ['entity_id' => '6', 'store_id' => '1', 'request_path' => 'b'],
        ]);

        $this->assertSame(
            [5 => [1 => 'a', 2 => 'a-de'], 6 => [1 => 'b']],
            $this->fetcher->fetchForEntities('product', [5, 6])
        );
    }

    public function testFetchForCmsGroupsGivesEachGroupItsBestPathPerStore(): void
    {
        $this->resource->method('getPathsForCmsGroups')->with(['about-us', 'contact'])->willReturn([
            ['hreflang_group' => 'about-us', 'store_id' => '2', 'request_path' => 'ueber-uns'],
            ['hreflang_group' => 'about-us', 'store_id' => '2', 'request_path' => 'about-us'],
            ['hreflang_group' => 'contact', 'store_id' => '1', 'request_path' => 'contact'],
        ]);

        $this->assertSame(
            ['about-us' => [2 => 'ueber-uns'], 'contact' => [1 => 'contact']],
            $this->fetcher->fetchForCmsGroups(['about-us', 'contact'])
        );
    }

    public function testFetchForCmsGroupKeepsTheFirstPathPerStore(): void
    {
        $this->resource->method('getPathsForCmsGroup')->with('about-us')->willReturn([
            ['entity_id' => '12', 'store_id' => '2', 'request_path' => 'ueber-uns'],
            ['entity_id' => '11', 'store_id' => '1', 'request_path' => 'about-us'],
            ['entity_id' => '11', 'store_id' => '2', 'request_path' => 'about-us'],
        ]);

        $this->assertSame([2 => 'ueber-uns', 1 => 'about-us'], $this->fetcher->fetchForCmsGroup('about-us'));
    }
}
