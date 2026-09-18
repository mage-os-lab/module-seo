<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Hreflang;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Statement\Pdo\Mysql as PdoMysqlStatement;
use MageOS\Seo\Model\Hreflang\UrlRewriteFetcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UrlRewriteFetcherTest extends TestCase
{
    /**
     * @var AdapterInterface&MockObject
     */
    private AdapterInterface&MockObject $connection;

    /**
     * @var UrlRewriteFetcher
     */
    private UrlRewriteFetcher $fetcher;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $select           = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturn('url_rewrite');

        $this->fetcher = new UrlRewriteFetcher($resource);
    }

    public function testReturnsPathsKeyedByStore(): void
    {
        $this->connection->method('fetchAll')->willReturn([
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
        $this->connection->method('fetchAll')->willReturn([
            ['store_id' => '1', 'request_path' => 'current'],
            ['store_id' => '1', 'request_path' => 'old-history'],
        ]);
        $this->assertSame([1 => 'current'], $this->fetcher->fetchForEntity('product', 5));
    }

    public function testReturnsEmptyWhenNoRewrites(): void
    {
        $this->connection->method('fetchAll')->willReturn([]);
        $this->assertSame([], $this->fetcher->fetchForEntity('cms-page', 9));
    }

    public function testStreamAllForTypeYieldsNothingWithoutStores(): void
    {
        $this->connection->expects($this->never())->method('query');

        $this->assertSame([], $this->streamed('product', []));
    }

    public function testStreamAllForTypeYieldsOneEntryPerEntity(): void
    {
        // The rows arrive ordered by entity, and are grouped as they are walked: the whole
        // catalogue's rewrites are never held at once.
        $this->givenRows([
            ['entity_id' => '5', 'store_id' => '1', 'request_path' => 'a'],
            ['entity_id' => '5', 'store_id' => '2', 'request_path' => 'a-de'],
            ['entity_id' => '6', 'store_id' => '1', 'request_path' => 'b'],
        ]);

        $this->assertSame(
            [[1 => 'a', 2 => 'a-de'], [1 => 'b']],
            $this->streamed('product', [1, 2])
        );
    }

    public function testStreamAllForTypeKeepsTheFirstPathPerStore(): void
    {
        $this->givenRows([
            ['entity_id' => '5', 'store_id' => '1', 'request_path' => 'current'],
            ['entity_id' => '5', 'store_id' => '1', 'request_path' => 'history'],
        ]);

        $this->assertSame([[1 => 'current']], $this->streamed('product', [1]));
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
        $this->connection->method('query')->willReturn($statement);
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
