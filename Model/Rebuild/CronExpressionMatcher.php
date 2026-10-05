<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Rebuild;

use Magento\Cron\Model\Schedule;
use Magento\Cron\Model\ScheduleFactory;
use Magento\Framework\Exception\CronException;

/**
 * Core's cron expression matching, one field at a time, so RetrySchedule works out a job's next run
 * exactly as core's scheduler decides it.
 *
 * Kept to this one call through the generated ScheduleFactory, so RetrySchedule's own logic stays
 * in the mutation run (see infection.json5).
 */
class CronExpressionMatcher
{
    /**
     * Core's schedule model, built on first use; matching keeps no state on it.
     *
     * @var Schedule|null
     */
    private ?Schedule $schedule = null;

    /**
     * @param ScheduleFactory $scheduleFactory
     */
    public function __construct(
        private readonly ScheduleFactory $scheduleFactory
    ) {
    }

    /**
     * Whether one field of a cron expression matches the value, as Schedule::trySchedule() matches it.
     *
     * @param string $field One field: "30", "*", "1-5", "mon-fri", a list or a step of these
     * @param int $value The minute, hour, day of the month, month or day of the week
     * @throws CronException When the field is invalid
     * @return bool
     */
    public function matches(string $field, int $value): bool
    {
        $this->schedule ??= $this->scheduleFactory->create();

        return (bool) $this->schedule->matchCronExpression($field, $value);
    }
}
