<?php

declare(strict_types=1);

namespace MageOS\Seo\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Raised when a feed rebuild is refused because another process is already building that group.
 *
 * Deliberately an exception rather than an empty result. A caller has to decide what to do about
 * it, and the decisions differ: the queue consumer must put the request back, or the invalidation
 * that prompted it is lost; the cron can drop it, because the process holding the lock is doing
 * the same work; the CLI has an operator to tell. A null or an empty array would let all three
 * treat "someone else is building" as "there was nothing to build".
 */
class FeedRebuildInProgressException extends LocalizedException
{
}
