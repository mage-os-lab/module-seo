<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\PageCache;

/**
 * A page cache purge asked for inside a transaction, waiting for the commit.
 *
 * Registered by Purger as a commit callback: it runs once the outermost transaction commits, and
 * core drops it when the transaction rolls back, so a change that never committed purges nothing.
 * A class of its own rather than a closure, as Rebuild\DeferredRequest is, so a test that runs
 * inside an isolation transaction can find it in core's callback pool and run it as the commit
 * would.
 */
class DeferredPurge
{
    /**
     * @param Purger $purger
     * @param string[] $tags
     */
    public function __construct(
        private readonly Purger $purger,
        private readonly array  $tags
    ) {
    }

    /**
     * Purge, now that the change is committed.
     *
     * @return void
     */
    public function __invoke(): void
    {
        $this->purger->purgeNow($this->tags);
    }

    /**
     * The tags to purge.
     *
     * @return string[]
     */
    public function tags(): array
    {
        return $this->tags;
    }
}
