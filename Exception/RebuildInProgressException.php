<?php

declare(strict_types=1);

namespace MageOS\Seo\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Raised when a rebuild is refused because another process is already doing it.
 *
 * The common parent of every "already running" refusal the rebuild queue can meet — the sitemap's
 * (SitemapRebuildInProgressException) and any registered group handler's — so the queue consumer
 * and the CLI command can react the same way to all of them without knowing who threw it: the
 * consumer puts the request back, the command tells the operator to retry.
 *
 * An exception rather than an empty result: "someone else is building" and "there was nothing to
 * build" must not look alike to a caller.
 */
class RebuildInProgressException extends LocalizedException
{
}
