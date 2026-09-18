<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Feed;

use Magento\Framework\FlagManager;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * Queues a feed-group rebuild, collapsing duplicate requests.
 *
 * A burst of invalidations (a catalog import saving thousands of products, for
 * example) must not queue thousands of identical builds: a pending flag per feed
 * group ensures at most one message is queued until the consumer starts working,
 * and the consumer clears the flag *before* building so changes arriving during a
 * build queue exactly one follow-up rebuild.
 *
 * The flag records when the request was queued. A request still pending after
 * STALE_AFTER_SECONDS is treated as lost (consumer not running, message purged) and
 * queued again, so a single lost message cannot switch event-driven rebuilds off for good.
 */
class RegenerationRequester
{
    public const TOPIC = 'mageos.seo.feed.regenerate';

    /**
     * How long a queued request may wait for the consumer before it is queued again.
     *
     * The consumer clears the flag before it starts building, so this only has to cover
     * the time a message waits in the queue, not the build itself.
     */
    public const STALE_AFTER_SECONDS = 3600;

    private const FLAG_PREFIX = 'mageos_seo_feed_pending_';

    /**
     * @param FlagManager $flagManager
     * @param PublisherInterface $publisher
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly FlagManager        $flagManager,
        private readonly PublisherInterface $publisher,
        private readonly DateTime           $dateTime,
        private readonly LoggerInterface    $logger
    ) {
    }

    /**
     * Queue a rebuild of the given feed group unless one is already pending.
     *
     * Best effort: a queue/flag failure is logged, never thrown — feed freshness
     * must not break saves or frontend requests (the nightly cron is the backstop).
     *
     * @param string $group One of FeedRegenerator::GROUPS
     * @return void
     */
    public function request(string $group): void
    {
        try {
            $now          = (int) $this->dateTime->gmtTimestamp();
            $pendingSince = $this->flagManager->getFlagData(self::FLAG_PREFIX . $group);

            if ($pendingSince !== null && $pendingSince !== false && $pendingSince !== '') {
                if (is_numeric($pendingSince) && $now - (int) $pendingSince < self::STALE_AFTER_SECONDS) {
                    return;
                }
                $this->logger->warning(
                    \sprintf(
                        'MageOS_Seo: the "%s" feed rebuild queued at %s was never picked up; queueing it again.'
                        . ' Check that the mageosSeoFeedRegenerate consumer is running.',
                        $group,
                        is_numeric($pendingSince) ? gmdate('c', (int) $pendingSince) : 'an unknown time'
                    ),
                    ['group' => $group]
                );
            }

            $this->flagManager->saveFlag(self::FLAG_PREFIX . $group, $now);
            try {
                $this->publisher->publish(self::TOPIC, $group);
            } catch (\Throwable $e) {
                // No message was queued: release the flag so the next change can try again
                // instead of waiting for it to go stale.
                $this->acknowledge($group);
                throw $e;
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'MageOS_Seo: could not queue feed regeneration: ' . $e->getMessage(),
                ['exception' => $e, 'group' => $group]
            );
        }
    }

    /**
     * Mark a queued request as picked up so later invalidations queue a fresh build.
     *
     * Called by the consumer before it starts building.
     *
     * @param string $group
     * @return void
     */
    public function acknowledge(string $group): void
    {
        try {
            $this->flagManager->deleteFlag(self::FLAG_PREFIX . $group);
        } catch (\Throwable $e) {
            $this->logger->error(
                'MageOS_Seo: could not clear feed regeneration flag: ' . $e->getMessage(),
                ['exception' => $e, 'group' => $group]
            );
        }
    }
}
