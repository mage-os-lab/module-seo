<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Log;

use Magento\Framework\App\CacheInterface;
use MageOS\Seo\Model\Log\OccasionalLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A fault every page meets is logged once in a while, per subject.
 */
class OccasionalLogTest extends TestCase
{
    /**
     * What the cache holds, by key.
     *
     * @var array<string,string>
     */
    private array $cached = [];

    /**
     * The lifetimes the cache was given.
     *
     * @var int[]
     */
    private array $lifetimes = [];

    /**
     * @return void
     */
    public function testTheSameSubjectIsLoggedOnceWithinTheInterval(): void
    {
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');
        $logger->method('warning')->willReturnCallback(
            static function (string $message, array $context) use (&$logged): void {
                $logged[] = [$message, $context];
            }
        );
        $log = new OccasionalLog($this->cache(), $logger);

        $log->warning('store 1 handling time', 'first', ['store_id' => 1]);
        $log->warning('store 1 handling time', 'again');
        $log->warning('store 2 handling time', 'another store view');

        $this->assertSame([['first', ['store_id' => 1]], ['another store view', []]], $logged);
        $this->assertSame([OccasionalLog::INTERVAL, OccasionalLog::INTERVAL], $this->lifetimes);
    }

    /**
     * @return void
     */
    public function testErrorsAreLoggedOnceWithinTheIntervalToo(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');
        $logger->expects($this->once())->method('error')->with('could not convert', ['store_id' => 1]);
        $log = new OccasionalLog($this->cache(), $logger);

        $log->error('store 1 rate', 'could not convert', ['store_id' => 1]);
        $log->error('store 1 rate', 'could not convert', ['store_id' => 1]);
    }

    /**
     * A cache that cannot be used does not keep a fault from being logged.
     *
     * @return void
     */
    public function testACacheThatCannotBeUsedDoesNotKeepAFaultFromTheLog(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willThrowException(new \RuntimeException('cache down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning');
        $log = new OccasionalLog($cache, $logger);

        $log->warning('store 1 handling time', 'first');
        $log->warning('store 1 handling time', 'again');
    }

    /**
     * A cache that keeps what it is given.
     *
     * @return CacheInterface
     */
    private function cache(): CacheInterface
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key): string|false => $this->cached[$key] ?? false);
        $cache->method('save')->willReturnCallback(
            function (string $data, string $key, array $tags = [], ?int $lifetime = null): bool {
                $this->cached[$key] = $data;
                $this->lifetimes[]  = (int) $lifetime;

                return true;
            }
        );

        return $cache;
    }
}
