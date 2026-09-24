<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap;

use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sitemap\Model\Sitemap;

/**
 * Keeps two processes from writing the same sitemap at once.
 *
 * One lock per sitemap, so different sitemaps are written side by side and a feed rebuild never
 * holds up a sitemap. The writers it keeps apart: a queued rebuild of one type, core's cron, and
 * the admin's Generate button — each can reach the same sitemap while another is still writing its
 * files, and two writers would each move files into place over the other's.
 *
 * A sitemap is its files, so the lock is named after them — its path and file name — rather than
 * its ID: a sitemap generated before it is saved has none, and two Site Map entries pointing at
 * the same file are one sitemap to the file system.
 *
 * LockManagerInterface is the framework's own abstraction, so the lock is shared across processes
 * and hosts wherever the install keeps its locks (see Feed\RebuildLock).
 */
class GenerationLock
{
    /**
     * Prefixed so the name is recognisable in a lock store shared with the rest of the install.
     */
    private const PREFIX = 'mageos_seo_sitemap_';

    /**
     * @param LockManagerInterface $lockManager
     */
    public function __construct(
        private readonly LockManagerInterface $lockManager
    ) {
    }

    /**
     * Take the sitemap's lock, waiting at most the given time for another writer to finish.
     *
     * @param Sitemap $sitemap
     * @param int $waitSeconds 0 to not wait at all
     * @return bool Whether the lock was taken
     */
    public function acquire(Sitemap $sitemap, int $waitSeconds): bool
    {
        return $this->lockManager->lock($this->name($sitemap), max(0, $waitSeconds));
    }

    /**
     * Release the sitemap's lock.
     *
     * @param Sitemap $sitemap
     * @return void
     */
    public function release(Sitemap $sitemap): void
    {
        $this->lockManager->unlock($this->name($sitemap));
    }

    /**
     * The lock's name: short enough for any lock store, and the same for the same files.
     *
     * @param Sitemap $sitemap
     * @return string
     */
    private function name(Sitemap $sitemap): string
    {
        $file = rtrim((string) $sitemap->getSitemapPath(), '/') . '/' . $sitemap->getSitemapFilename();

        return self::PREFIX . substr(hash('sha256', $file), 0, 16);
    }
}
