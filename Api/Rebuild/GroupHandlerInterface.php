<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Rebuild;

use MageOS\Seo\Exception\RebuildInProgressException;

/**
 * Pre-generated output another module rebuilds through this module's rebuild queue.
 *
 * The queue (`mageos.seo.feed.regenerate`, consumer `mageosSeoFeedRegenerate`) carries a group name
 * per message. This module keeps the sitemap groups; a handler registered in
 * MageOS\Seo\Model\Rebuild\HandlerPool's `handlers` argument owns the others it names — the llms
 * documents, for example. What the queue gives a handler for free:
 *
 * - requests collapse: however many times a group is invalidated before the consumer gets to it,
 *   one rebuild runs (Model\Rebuild\RegenerationRequester);
 * - a request is queued only when the handler says the group is enabled somewhere;
 * - a rebuild refused with RebuildInProgressException is queued again rather than lost;
 * - the `seo:rebuild` command lists and rebuilds the handler's groups, and every
 *   `setup:upgrade` queues them.
 *
 * Invalidate a group with Model\Rebuild\Invalidator::invalidate().
 *
 * @api
 */
interface GroupHandlerInterface
{
    /**
     * The groups this handler rebuilds.
     *
     * Names must be unique across handlers and cannot be a sitemap group's (`sitemap-…`,
     * `sitemaps-missing`).
     *
     * @return string[]
     */
    public function getGroups(): array;

    /**
     * Whether at least one active store view can build the group; nothing is queued otherwise.
     *
     * @param string $group One of getGroups()
     * @return bool
     */
    public function isEnabled(string $group): bool;

    /**
     * Rebuild the group now, in this process — or every group of this handler, when null.
     *
     * A failing store view should be logged and skipped so the others are still built, and
     * reported in the result.
     *
     * @param string|null $group One of getGroups(), or null for all of them
     * @throws RebuildInProgressException When another process is already rebuilding it
     * @return array<int,string> Error message per store view ID that failed
     */
    public function rebuild(?string $group): array;
}
