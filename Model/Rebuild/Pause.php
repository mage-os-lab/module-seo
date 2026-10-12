<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Rebuild;

/**
 * Waits, so the rebuild consumer can wait for a lock another process holds.
 *
 * A class of its own so tests replace it rather than wait.
 */
class Pause
{
    /**
     * Wait for the given number of seconds.
     *
     * @param int $seconds
     * @return void
     */
    public function seconds(int $seconds): void
    {
        if ($seconds > 0) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- a CLI consumer waiting for a lock
            sleep($seconds);
        }
    }
}
