<?php

declare(strict_types=1);

namespace MageOS\Seo\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Raised when a sitemap is not written because another process is writing it.
 *
 * An exception rather than an empty result, for the reason FeedRebuildInProgressException gives:
 * the callers must act differently. The queue consumer puts the request back, or the change that
 * prompted it is lost; the admin's Generate button and core's cron report it.
 */
class SitemapRebuildInProgressException extends LocalizedException
{
}
