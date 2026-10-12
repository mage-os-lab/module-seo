<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\PageCache;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Flag\FlagResource;
use MageOS\Seo\Model\PageCache\CacheTagCleaner;
use MageOS\Seo\Model\PageCache\DeferredPurge;
use MageOS\Seo\Model\PageCache\Purger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Purges wait for the commit of a transaction, are made at once outside one, and are sent once at
 * the end of a batch.
 */
class PurgerTest extends TestCase
{
    /**
     * The tag lists the cleaner was given, in order.
     *
     * @var string[][]
     */
    private array $cleaned = [];

    /**
     * The commit callbacks registered.
     *
     * @var callable[]
     */
    private array $callbacks = [];

    /**
     * Outside a transaction the pages are purged at once, each tag once.
     *
     * @return void
     */
    public function testOutsideATransactionThePagesArePurgedAtOnce(): void
    {
        $this->purger(0)->purge(['mageos_seo_cat_3', 'mageos_seo_cat_2', 'mageos_seo_cat_3', '']);

        $this->assertSame([['mageos_seo_cat_3', 'mageos_seo_cat_2']], $this->cleaned);
        $this->assertSame([], $this->callbacks);
    }

    /**
     * Inside a transaction the purge waits for the commit, and is made when it comes.
     *
     * @return void
     */
    public function testInsideATransactionThePurgeWaitsForTheCommit(): void
    {
        $this->purger(1)->purge(['mageos_seo_cat_3']);

        $this->assertSame([], $this->cleaned, 'Nothing is purged before the commit.');
        $this->assertCount(1, $this->callbacks);
        $this->assertInstanceOf(DeferredPurge::class, $this->callbacks[0]);
        $this->assertSame(['mageos_seo_cat_3'], $this->callbacks[0]->tags());

        ($this->callbacks[0])();

        $this->assertSame([['mageos_seo_cat_3']], $this->cleaned);
    }

    /**
     * Nothing to purge, no purge.
     *
     * @return void
     */
    public function testNoTagsNoPurge(): void
    {
        $purger = $this->purger(0);

        $purger->purge([]);
        $purger->purge(['']);

        $this->assertSame([], $this->cleaned);
    }

    /**
     * A batch sends what it collected once, at its end — the outermost one's when they nest — and
     * hands back what the work returned.
     *
     * @return void
     */
    public function testABatchPurgesOnceAtItsEnd(): void
    {
        $purger = $this->purger(0);

        $result = $purger->batch(function () use ($purger): string {
            $purger->purge(['mageos_seo_cat_1']);
            $purger->batch(static function () use ($purger): void {
                $purger->purge(['mageos_seo_cat_2', 'mageos_seo_cat_1']);
            });
            $this->assertSame([], $this->cleaned, 'Nothing is purged before the outermost batch ends.');

            return 'imported';
        });

        $this->assertSame('imported', $result);
        $this->assertSame([['mageos_seo_cat_1', 'mageos_seo_cat_2']], $this->cleaned);
    }

    /**
     * A batch that ends inside a transaction leaves its purge for the commit.
     *
     * @return void
     */
    public function testABatchEndingInsideATransactionWaitsForTheCommit(): void
    {
        $purger = $this->purger(1);

        $purger->batch(static fn () => $purger->purge(['mageos_seo_prod_5']));

        $this->assertSame([], $this->cleaned);
        $this->assertCount(1, $this->callbacks);
    }

    /**
     * What a failed batch collected is still purged — the rows it wrote before failing are written
     * — and the failure goes on to the caller.
     *
     * @return void
     */
    public function testAFailedBatchStillPurgesWhatItCollected(): void
    {
        $purger = $this->purger(0);

        try {
            $purger->batch(fn () => $this->failingImport($purger));
            $this->fail('The failure was swallowed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('import failed', $e->getMessage());
        }

        $this->assertSame([['mageos_seo_cms_8']], $this->cleaned);
        $purger->purge(['mageos_seo_cms_9']);
        $this->assertSame([['mageos_seo_cms_8'], ['mageos_seo_cms_9']], $this->cleaned, 'The batch is over.');
    }

    /**
     * A purge that fails is logged with its tags, not thrown: the change it follows is saved.
     *
     * @return void
     */
    public function testAFailedPurgeIsLoggedNotThrown(): void
    {
        $cleaner = $this->createStub(CacheTagCleaner::class);
        $cleaner->method('clean')->willThrowException(new \RuntimeException('Varnish is down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(
            $this->stringContains('Varnish is down'),
            $this->callback(static fn (array $context): bool => $context['tags'] === ['mageos_seo_cat_3'])
        );

        (new Purger($this->flagResource(0), $cleaner, $logger))->purge(['mageos_seo_cat_3']);
    }

    /**
     * When whether a transaction is open cannot be told, the purge is made at once and the reason
     * logged: early rather than never.
     *
     * @return void
     */
    public function testWhenTheTransactionCannotBeToldThePurgeIsMadeAtOnce(): void
    {
        $flagResource = $this->createStub(FlagResource::class);
        $flagResource->method('getConnection')->willThrowException(new \RuntimeException('no connection'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('no connection'));

        (new Purger($flagResource, $this->cleaner(), $logger))->purge(['mageos_seo_cat_3']);

        $this->assertSame([['mageos_seo_cat_3']], $this->cleaned);
    }

    /**
     * An import that writes a row, then fails.
     *
     * @param Purger $purger
     * @throws \RuntimeException
     * @return void
     */
    private function failingImport(Purger $purger): void
    {
        $purger->purge(['mageos_seo_cms_8']);
        throw new \RuntimeException('import failed');
    }

    /**
     * No connection, no transaction to wait for.
     *
     * @return void
     */
    public function testWithoutAConnectionThePurgeIsMadeAtOnce(): void
    {
        $flagResource = $this->createStub(FlagResource::class);
        $flagResource->method('getConnection')->willReturn(false);
        // Told, not failed: no connection is an answer, not an error to log.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        (new Purger($flagResource, $this->cleaner(), $logger))->purge(['mageos_seo_cat_3']);

        $this->assertSame([['mageos_seo_cat_3']], $this->cleaned);
    }

    /**
     * A purger whose connection is at the given transaction level.
     *
     * @param int $transactionLevel
     * @return Purger
     */
    private function purger(int $transactionLevel): Purger
    {
        return new Purger(
            $this->flagResource($transactionLevel),
            $this->cleaner(),
            $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * A resource whose connection is at the given transaction level, recording commit callbacks.
     *
     * @param int $transactionLevel
     * @return FlagResource
     */
    private function flagResource(int $transactionLevel): FlagResource
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('getTransactionLevel')->willReturn($transactionLevel);

        $flagResource = $this->createStub(FlagResource::class);
        $flagResource->method('getConnection')->willReturn($connection);
        $flagResource->method('addCommitCallback')->willReturnCallback(
            function (callable $callback) use ($flagResource): FlagResource {
                $this->callbacks[] = $callback;

                return $flagResource;
            }
        );

        return $flagResource;
    }

    /**
     * A cleaner recording what it is given.
     *
     * @return CacheTagCleaner
     */
    private function cleaner(): CacheTagCleaner
    {
        $cleaner = $this->createStub(CacheTagCleaner::class);
        $cleaner->method('clean')->willReturnCallback(function (array $tags): void {
            $this->cleaned[] = $tags;
        });

        return $cleaner;
    }
}
