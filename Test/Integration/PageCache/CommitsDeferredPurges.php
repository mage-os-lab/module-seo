<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\PageCache;

use Magento\Framework\Flag\FlagResource;
use Magento\Framework\Model\CallbackPool;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\PageCache\DeferredPurge;

/**
 * For tests with database isolation: runs the page cache purges waiting for a commit that never
 * comes.
 *
 * A write inside the test's own transaction defers its purge to the commit
 * (Model\PageCache\DeferredPurge), and the test's transaction is rolled back, never committed. This
 * runs those purges as the commit would; every other commit callback stays in core's pool.
 */
trait CommitsDeferredPurges
{
    /**
     * Run the purges waiting for the commit.
     *
     * @return void
     */
    private function commitDeferredPurges(): void
    {
        $key    = spl_object_hash(Bootstrap::getObjectManager()->get(FlagResource::class)->getConnection());
        $others = [];
        foreach (CallbackPool::get($key) as $callback) {
            if ($callback instanceof DeferredPurge) {
                $callback();
            } else {
                $others[] = $callback;
            }
        }
        foreach ($others as $callback) {
            CallbackPool::attach($key, $callback);
        }
    }
}
