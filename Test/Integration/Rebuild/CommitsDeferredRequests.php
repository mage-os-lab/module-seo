<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Rebuild;

use Magento\Framework\Flag\FlagResource;
use Magento\Framework\Model\CallbackPool;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Model\Rebuild\DeferredRequest;

/**
 * For tests with database isolation: runs the rebuild requests waiting for a commit that never
 * comes.
 *
 * A save inside the test's own transaction defers its rebuild requests to the commit
 * (Model\Rebuild\DeferredRequest), and the test's transaction is rolled back, never committed. This
 * runs those requests as the commit would; every other commit callback stays in core's pool.
 */
trait CommitsDeferredRequests
{
    /**
     * Run the rebuild requests waiting for the commit.
     *
     * @return void
     */
    private function commitDeferredRequests(): void
    {
        $key    = spl_object_hash(Bootstrap::getObjectManager()->get(FlagResource::class)->getConnection());
        $others = [];
        foreach (CallbackPool::get($key) as $callback) {
            if ($callback instanceof DeferredRequest) {
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
