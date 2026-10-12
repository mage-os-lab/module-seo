<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Rebuild;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Rebuild\RegenerationRequester;
use PHPUnit\Framework\TestCase;

/**
 * A rebuild requested inside a transaction is queued once it commits, and not at all if it rolls
 * back (issue #22, module-aeo #8). Published at once, a consumer outside the database could rebuild
 * from the data before the change, and a rolled-back change would still be rebuilt.
 *
 * The transactions are the test's own, so database isolation is off; the pending flag it leaves is
 * removed afterwards.
 *
 * @magentoDbIsolation disabled
 */
class CommitBoundaryTest extends TestCase
{
    private const GROUP = 'mageos-seo-commit-boundary-test';
    private const FLAG  = 'mageos_seo_feed_pending_' . self::GROUP;

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Bootstrap::getObjectManager()->get(FlagManager::class)->deleteFlag(self::FLAG);
    }

    /**
     * Nothing is queued until the commit; then the request is.
     *
     * @return void
     */
    public function testARequestInsideATransactionIsQueuedAtTheCommit(): void
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();

        $connection->beginTransaction();
        $this->requester()->request(self::GROUP);
        $this->assertFalse($this->isPending(), 'Nothing is queued before the commit.');
        $connection->commit();

        $this->assertTrue($this->isPending(), 'The request is queued once committed.');
    }

    /**
     * A rolled-back transaction queues nothing, and leaves later requests working.
     *
     * @return void
     */
    public function testARolledBackRequestQueuesNothing(): void
    {
        $connection = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection();

        $connection->beginTransaction();
        $this->requester()->request(self::GROUP);
        $connection->rollBack();

        $this->assertFalse($this->isPending(), 'A change that never committed is not rebuilt.');

        $this->requester()->request(self::GROUP);
        $this->assertTrue($this->isPending(), 'A request outside a transaction is queued at once.');
    }

    /**
     * @return RegenerationRequester
     */
    private function requester(): RegenerationRequester
    {
        return Bootstrap::getObjectManager()->get(RegenerationRequester::class);
    }

    /**
     * @return bool
     */
    private function isPending(): bool
    {
        return Bootstrap::getObjectManager()->get(FlagManager::class)->getFlagData(self::FLAG) !== null;
    }
}
