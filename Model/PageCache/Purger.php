<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\PageCache;

use Magento\Framework\Flag\FlagResource;
use Psr\Log\LoggerInterface;

/**
 * Purges the cached pages carrying given tags, once the change that calls for it is committed.
 *
 * It waits for the commit because a page requested between a purge and the commit is rebuilt from
 * the old settings and cached again, until it expires. Core purges a saved model's pages inside its
 * transaction, and only the built-in cache purges again after the commit; Varnish hears the first
 * purge alone. Inside a transaction the purge is registered as a commit callback (DeferredPurge):
 * it runs once the outermost transaction commits through a resource model, and a rollback drops it.
 * A transaction committed past the resource models — a data patch's, which setup:upgrade opens on
 * the connection itself — leaves it for the next commit that is not; upgrades are followed by a
 * cache flush.
 *
 * How a purge is made is CacheTagCleaner's. One that fails is logged and not thrown: the change it
 * follows is already saved.
 */
class Purger
{
    /**
     * How many batch() calls are running.
     *
     * @var int
     */
    private int $batchDepth = 0;

    /**
     * The tags collected by the running batch.
     *
     * @var array<string,true>
     */
    private array $batched = [];

    /**
     * @param FlagResource $flagResource A resource on the default connection, for its transaction
     * @param CacheTagCleaner $cacheTagCleaner
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly FlagResource    $flagResource,
        private readonly CacheTagCleaner $cacheTagCleaner,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Purge the pages carrying any of the tags.
     *
     * After the commit inside a transaction, now outside one, and at the end of a running batch().
     *
     * @param string[] $tags
     * @return void
     */
    public function purge(array $tags): void
    {
        $tags = array_values(array_unique(array_filter($tags, static fn (string $tag): bool => $tag !== '')));
        if ($tags === []) {
            return;
        }

        if ($this->batchDepth > 0) {
            foreach ($tags as $tag) {
                $this->batched[$tag] = true;
            }
            return;
        }

        if ($this->inTransaction()) {
            $this->flagResource->addCommitCallback(new DeferredPurge($this, $tags));
            return;
        }

        $this->purgeNow($tags);
    }

    /**
     * Purge the pages carrying any of the tags, now.
     *
     * For DeferredPurge, run at the commit; anything else calls purge().
     *
     * @param string[] $tags
     * @return void
     */
    public function purgeNow(array $tags): void
    {
        try {
            $this->cacheTagCleaner->clean($tags);
        } catch (\Throwable $e) {
            $this->logger->error(
                'MageOS_Seo: could not purge the pages built from changed SEO settings from the page cache: '
                . $e->getMessage(),
                ['exception' => $e, 'tags' => $tags]
            );
        }
    }

    /**
     * Run the work, purging once at its end everything it would have purged as it went.
     *
     * For bulk writes — an import of thousands of rows — which would otherwise send a purge, and a
     * Varnish ban, per row. The collected tags are purged by purge(), so after the commit when a
     * transaction is still open.
     *
     * @template T
     * @param callable $work
     * @phpstan-param callable(): T $work
     * @return mixed What the work returned
     * @phpstan-return T
     */
    public function batch(callable $work): mixed
    {
        $this->batchDepth++;
        try {
            return $work();
        } finally {
            $this->batchDepth--;
            if ($this->batchDepth === 0 && $this->batched !== []) {
                $tags          = array_keys($this->batched);
                $this->batched = [];
                $this->purge($tags);
            }
        }
    }

    /**
     * Whether a transaction is open on the default connection.
     *
     * When that cannot be told, the purge is made at once — early rather than never — and the
     * reason logged.
     *
     * @return bool
     */
    private function inTransaction(): bool
    {
        try {
            $connection = $this->flagResource->getConnection();

            return $connection !== false && $connection->getTransactionLevel() > 0;
        } catch (\Throwable $e) {
            $this->logger->error(
                'MageOS_Seo: could not tell whether a page cache purge has to wait for a commit, so it'
                . ' is made at once: ' . $e->getMessage(),
                ['exception' => $e]
            );

            return false;
        }
    }
}
