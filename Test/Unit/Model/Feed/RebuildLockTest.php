<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Feed;

use Magento\Framework\Lock\LockManagerInterface;
use MageOS\Seo\Model\Feed\RebuildLock;
use PHPUnit\Framework\TestCase;

class RebuildLockTest extends TestCase
{
    public function testAcquiringReportsWhetherTheLockWasTaken(): void
    {
        $lockManager = $this->createStub(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);

        $this->assertTrue((new RebuildLock($lockManager))->acquire());
    }

    public function testAcquiringReportsWhenAnotherProcessHoldsIt(): void
    {
        $lockManager = $this->createStub(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(false);

        $this->assertFalse((new RebuildLock($lockManager))->acquire());
    }

    public function testItDoesNotWaitForTheLock(): void
    {
        // A zero timeout is the whole design: a caller that loses the race re-queues or drops,
        // rather than holding a worker open for a catalogue-wide build and then repeating it.
        $timeout = null;

        $lockManager = $this->createStub(LockManagerInterface::class);
        $lockManager->method('lock')->willReturnCallback(
            function (string $name, int $seconds) use (&$timeout): bool {
                $timeout = $seconds;
                return true;
            }
        );

        (new RebuildLock($lockManager))->acquire();

        $this->assertSame(0, $timeout);
    }

    public function testEveryGroupSharesOneLockName(): void
    {
        // Per-group names would let a full rebuild run beside a single-group rebuild of the very
        // files it is writing.
        $names = [];

        $lockManager = $this->createStub(LockManagerInterface::class);
        $lockManager->method('lock')->willReturnCallback(
            function (string $name) use (&$names): bool {
                $names[] = $name;
                return true;
            }
        );
        $lockManager->method('unlock')->willReturnCallback(
            function (string $name) use (&$names): bool {
                $names[] = $name;
                return true;
            }
        );

        $lock = new RebuildLock($lockManager);
        $lock->acquire();
        $lock->release();

        $this->assertCount(2, $names);
        $this->assertSame($names[0], $names[1], 'Release must target the name acquire took.');
    }
}
