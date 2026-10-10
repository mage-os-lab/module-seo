<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Log;

use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Logs a fault that every page meets at most once every five minutes per subject.
 *
 * For configuration the storefront cannot use — an hreflang code that fails the check, a shipping
 * time that breaks Google's rules — read on every uncached page and wrong until someone changes it:
 * once in a while is enough to find it, and once per page would bury everything else in the log.
 * The subject names the fault, such as a store view and a field; the message says what it is.
 *
 * A cache that cannot be read or written does not keep the fault from being logged.
 */
class OccasionalLog
{
    /**
     * Seconds between two entries for the same subject.
     */
    public const INTERVAL = 300;

    /**
     * @param CacheInterface $cache Remembers which subjects were logged lately
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CacheInterface  $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Log a warning, unless the same subject was logged within the interval.
     *
     * @param string $subject
     * @param string $message
     * @param mixed[] $context
     * @return void
     */
    public function warning(string $subject, string $message, array $context = []): void
    {
        if (!$this->loggedLately($subject)) {
            $this->logger->warning($message, $context);
        }
    }

    /**
     * Log an error, unless the same subject was logged within the interval.
     *
     * @param string $subject
     * @param string $message
     * @param mixed[] $context
     * @return void
     */
    public function error(string $subject, string $message, array $context = []): void
    {
        if (!$this->loggedLately($subject)) {
            $this->logger->error($message, $context);
        }
    }

    /**
     * Whether the subject was logged within the interval; if not, remember that it is now.
     *
     * @param string $subject
     * @return bool
     */
    private function loggedLately(string $subject): bool
    {
        $key = 'mageos_seo_log_' . sha1($subject);
        try {
            if ($this->cache->load($key) !== false) {
                return true;
            }
            $this->cache->save('1', $key, [], self::INTERVAL);
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- log it anyway
        }

        return false;
    }
}
