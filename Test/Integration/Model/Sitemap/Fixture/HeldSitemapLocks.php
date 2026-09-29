<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Sitemap\Fixture;

use Magento\Framework\Lock\LockManagerInterface;

/**
 * A lock store in which every sitemap's lock is held by some other process.
 *
 * MySQL's GET_LOCK is re-entrant within one connection, so a test cannot make a lock look taken by
 * taking it itself; this stands in for the other process. Any other lock is granted.
 */
class HeldSitemapLocks implements LockManagerInterface
{
    /**
     * @inheritdoc
     */
    public function lock(string $name, int $timeout = -1): bool
    {
        return !str_starts_with($name, 'mageos_seo_sitemap_');
    }

    /**
     * @inheritdoc
     */
    public function unlock(string $name): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function isLocked(string $name): bool
    {
        return str_starts_with($name, 'mageos_seo_sitemap_');
    }
}
