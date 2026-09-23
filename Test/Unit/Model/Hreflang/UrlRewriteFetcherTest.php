<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Hreflang;

use Magento\Framework\DB\Statement\Pdo\Mysql as PdoMysqlStatement;
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

    public function testStreamAllForTypeYieldsNothingWithoutStores(): void
    {
        $this->resource->expects($this->never())->method('queryPathsForType');

        $this->assertSame([], $this->streamed('product', []));
    }

    public function testStreamAllForTypeYieldsOneEntryPerEntity(): void
    {
        // The rows arrive ordered by entity, and are grouped as they are walked: the whole
        // catalogue's rewrites are never held at once.
        $this->givenRows([
            ['entity_id' => '5', 'group_key' => '5', 'store_id' => '1', 'request_path' => 'a'],
            ['entity_id' => '5', 'group_key' => '5', 'store_id' => '2', 'request_path' => 'a-de'],
            ['entity_id' => '6', 'group_key' => '6', 'store_id' => '1', 'request_path' => 'b'],
        ]);

        $this->assertSame(
            [[1 => 'a', 2 => 'a-de'], [1 => 'b']],
            $this->streamed('product', [1, 2])
        );
    }

    public function testStreamAllForTypeKeepsTheFirstPathPerStore(): void
    {
        $this->givenRows([
            ['entity_id' => '5', 'group_key' => '5', 'store_id' => '1', 'request_path' => 'current'],
            ['entity_id' => '5', 'group_key' => '5', 'store_id' => '1', 'request_path' => 'history'],
        ]);

        $this->assertSame([[1 => 'current']], $this->streamed('product', [1]));
    }

    public function testCmsTranslationsAreStreamedAsOneEntry(): void
    {
        // Different pages, one group: each store view contributes its own translation.
        $this->givenRows([
            ['entity_id' => '11', 'group_key' => 'g:about-us', 'store_id' => '1', 'request_path' => 'about-us'],
            ['entity_id' => '12', 'group_key' => 'g:about-us', 'store_id' => '2', 'request_path' => 'ueber-uns'],
            ['entity_id' => '13', 'group_key' => 'p:13', 'store_id' => '1', 'request_path' => 'contact'],
        ]);

        $this->assertSame(
            [[1 => 'about-us', 2 => 'ueber-uns'], [1 => 'contact']],
            $this->streamed('cms-page', [1, 2])
        );
    }

    public function testTheFirstCandidateForAStoreInAGroupWins(): void
    {
        // The query puts the page assigned to the store view ahead of an all-store-views page.
        $this->givenRows([
            ['entity_id' => '12', 'group_key' => 'g:about-us', 'store_id' => '2', 'request_path' => 'ueber-uns'],
            ['entity_id' => '11', 'group_key' => 'g:about-us', 'store_id' => '2', 'request_path' => 'about-us'],
        ]);

        $this->assertSame([[2 => 'ueber-uns']], $this->streamed('cms-page', [2]));
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

    public function testStreamAllForTypeYieldsNothingForAnEmptyResult(): void
    {
        $this->givenRows([]);

        $this->assertSame([], $this->streamed('product', [1]));
    }

    /**
     * The query returns the given rows, one per fetch().
     *
     * @param array<int, array<string, string>> $rows
     * @return void
     */
    private function givenRows(array $rows): void
    {
        $statement = $this->createStub(PdoMysqlStatement::class);
        $statement->method('fetch')->willReturnCallback(
            static function () use (&$rows): array|false {
                return array_shift($rows) ?? false;
            }
        );
        $this->resource->method('queryPathsForType')->willReturn($statement);
    }

    /**
     * Collect what the generator yields.
     *
     * @param string $entityType
     * @param int[] $storeIds
     * @return array<int, array<int, string>>
     */
    private function streamed(string $entityType, array $storeIds): array
    {
        return iterator_to_array($this->fetcher->streamAllForType($entityType, $storeIds), false);
    }
}
