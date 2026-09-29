<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap;

use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sitemap\Model\Sitemap;
use MageOS\Seo\Model\Sitemap\GenerationLock;
use PHPUnit\Framework\TestCase;

class GenerationLockTest extends TestCase
{
    /**
     * Lock names asked for, in order.
     *
     * @var string[]
     */
    private array $names = [];

    /**
     * Waits asked for, in order.
     *
     * @var int[]
     */
    private array $waits = [];

    public function testTheSameFilesAreTheSameLockAndOtherFilesAnother(): void
    {
        $lock = $this->lock();

        $lock->acquire($this->sitemap('/media/sitemap/', 'sitemap.xml'), 0);
        $lock->acquire($this->sitemap('/media/sitemap', 'sitemap.xml'), 0);
        $lock->acquire($this->sitemap('/media/sitemap/', 'sitemap_de.xml'), 0);

        $this->assertSame($this->names[0], $this->names[1], 'A trailing slash is the same directory.');
        $this->assertNotSame($this->names[0], $this->names[2]);
        $this->assertStringStartsWith('mageos_seo_sitemap_', $this->names[0]);
        $this->assertLessThanOrEqual(64, \strlen($this->names[0]), 'Short enough for MySQL\'s GET_LOCK.');
    }

    public function testTheWaitIsPassedOnAndNeverInfinite(): void
    {
        $lock    = $this->lock();
        $sitemap = $this->sitemap('/media/sitemap/', 'sitemap.xml');

        $lock->acquire($sitemap, 60);
        $lock->acquire($sitemap, -1);

        // The lock manager reads a negative timeout as "wait forever".
        $this->assertSame([60, 0], $this->waits);
    }

    public function testReleasingUnlocksTheSameName(): void
    {
        $unlocked = [];
        $manager  = $this->createStub(LockManagerInterface::class);
        $manager->method('lock')->willReturnCallback(function (string $name): bool {
            $this->names[] = $name;
            return true;
        });
        $manager->method('unlock')->willReturnCallback(function (string $name) use (&$unlocked): bool {
            $unlocked[] = $name;
            return true;
        });
        $lock    = new GenerationLock($manager);
        $sitemap = $this->sitemap('/media/sitemap/', 'sitemap.xml');

        $lock->acquire($sitemap, 0);
        $lock->release($sitemap);

        $this->assertSame($this->names, $unlocked);
    }

    /**
     * A lock over a manager that records what it is asked for and grants it.
     *
     * @return GenerationLock
     */
    private function lock(): GenerationLock
    {
        $manager = $this->createStub(LockManagerInterface::class);
        $manager->method('lock')->willReturnCallback(function (string $name, int $timeout): bool {
            $this->names[] = $name;
            $this->waits[] = $timeout;
            return true;
        });

        return new GenerationLock($manager);
    }

    /**
     * @param string $path
     * @param string $fileName
     * @return Sitemap
     */
    private function sitemap(string $path, string $fileName): Sitemap
    {
        $sitemap = $this->createStub(Sitemap::class);
        $sitemap->method('__call')->willReturnCallback(
            static fn (string $method): ?string => match ($method) {
                'getSitemapPath'     => $path,
                'getSitemapFilename' => $fileName,
                default              => null,
            }
        );

        return $sitemap;
    }
}
