<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Feed;

use Magento\Framework\Lock\LockManagerInterface;

/**
 * Keeps two processes from rebuilding feeds at once.
 *
 * The queue consumer is not the only writer: the nightly cron and the CLI command build the same
 * files, and a single consumer is not guaranteed either — `consumers_runner` can be configured to
 * run several processes of the same consumer, and on a multi-server install the cron runs on each
 * node. Two whole-catalog builds writing the same feed at the same time waste the work of
 * whichever finishes first and, while they overlap, double the load of the heaviest job this
 * module has.
 *
 * One lock covers every group rather than one per group. A full rebuild writes the files of all
 * three, so per-group locks would leave it free to run alongside a single-group rebuild of the
 * files it is already writing — which is the case worth preventing. The groups do not run
 * concurrently today in any case: the consumer takes its queue serially.
 *
 * LockManagerInterface is the framework's own abstraction, so the lock lives wherever the install
 * has configured it (database by default, Zookeeper, Redis or the filesystem otherwise) and is
 * therefore shared across processes and hosts — which a PHP-level guard could never be.
 */
class RebuildLock
{
    /**
     * Prefixed so the name is recognisable in a lock store shared with the rest of the install.
     */
    private const NAME = 'mageos_seo_feed_rebuild';

    /**
     * @param LockManagerInterface $lockManager
     */
    public function __construct(
        private readonly LockManagerInterface $lockManager
    ) {
    }

    /**
     * Take the rebuild lock, or report that another process holds it.
     *
     * Does not wait: a caller that cannot build now either re-queues the request or drops it, and
     * holding a worker open for the length of a catalogue-wide build only to then repeat that
     * build helps nobody.
     *
     * @return bool
     */
    public function acquire(): bool
    {
        return $this->lockManager->lock(self::NAME, 0);
    }

    /**
     * Release the rebuild lock.
     *
     * @return void
     */
    public function release(): void
    {
        $this->lockManager->unlock(self::NAME);
    }
}
