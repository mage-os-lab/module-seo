<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Rebuild;

/**
 * Queues the rebuild of a group of generated documents (the sitemaps, a module's feeds).
 *
 * A request made inside a database transaction waits for the transaction to commit, so the consumer
 * never builds from data that is then rolled back; one made outside is queued at once. Requests for
 * a group already pending are not queued again.
 *
 * @api
 */
interface RegenerationRequesterInterface
{
    /**
     * Queue a rebuild of the group.
     *
     * @param string $group A group a GroupHandlerInterface owns
     * @return void
     */
    public function request(string $group): void;

    /**
     * Mark the group's pending request as taken, so the next change queues a new one.
     *
     * Called by the consumer as it starts a group's rebuild.
     *
     * @param string $group
     * @return void
     */
    public function acknowledge(string $group): void;
}
