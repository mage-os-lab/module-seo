<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Rebuild;

use Magento\Cron\Model\ConfigInterface as CronConfig;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\CronException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Sitemap\Model\Observer as SitemapCron;
use Magento\Store\Model\ScopeInterface;
use MageOS\Seo\Api\Rebuild\GroupDescriptionInterface;
use MageOS\Seo\Model\Sitemap\RebuildGroup;
use Psr\Log\LoggerInterface;

/**
 * When a rebuild group is next rebuilt on a schedule, so the admin can be told when a failure is
 * retried without anyone acting.
 *
 * The sitemap groups are retried by core's sitemap cron (`sitemap_generate`), while Site Map
 * generation is enabled under Stores → Configuration → Catalog → XML Sitemap; another module's group
 * by the cron job its handler names (GroupDescriptionInterface). The job's schedule is read as core's
 * cron reads it, a `config_path` or a schedule override in the configuration included, and its next
 * run is worked out with core's own cron expression matcher, in the configured timezone.
 *
 * This is when the cron is due to run the job. Nothing here can tell whether the server's cron runs.
 */
class RetrySchedule
{
    private const SITEMAP_JOB = 'sitemap_generate';

    /**
     * How far ahead a next run is looked for: a yearly job still has one.
     */
    private const DAYS_AHEAD = 366;

    /**
     * @param HandlerPool $handlerPool
     * @param RebuildGroup $rebuildGroup
     * @param CronConfig $cronConfig
     * @param CronExpressionMatcher $matcher
     * @param ScopeConfigInterface $scopeConfig
     * @param TimezoneInterface $timezone
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly HandlerPool           $handlerPool,
        private readonly RebuildGroup          $rebuildGroup,
        private readonly CronConfig            $cronConfig,
        private readonly CronExpressionMatcher $matcher,
        private readonly ScopeConfigInterface  $scopeConfig,
        private readonly TimezoneInterface     $timezone,
        private readonly DateTime              $dateTime,
        private readonly LoggerInterface       $logger
    ) {
    }

    /**
     * The next scheduled rebuild of the group, as a timestamp.
     *
     * Null when nothing rebuilds the group on a schedule.
     *
     * @param string $group
     * @return int|null
     */
    public function nextAttempt(string $group): ?int
    {
        $job = $this->jobFor($group);
        if ($job === null) {
            return null;
        }

        $expression = $this->expressionOf($job);
        if ($expression === null) {
            return null;
        }

        try {
            return $this->nextRun($expression, (int) $this->dateTime->gmtTimestamp());
        } catch (\Exception $e) {
            $this->logger->warning(
                \sprintf('MageOS_Seo: cannot tell when cron job %s next runs: %s', $job, $e->getMessage())
            );
            return null;
        }
    }

    /**
     * The cron job that rebuilds the group, or null.
     *
     * @param string $group
     * @return string|null
     */
    private function jobFor(string $group): ?string
    {
        if ($group === RebuildGroup::MISSING || $this->rebuildGroup->typeOf($group) !== null) {
            // Read as core's cron reads it, before generating anything.
            return $this->scopeConfig->isSetFlag(SitemapCron::XML_PATH_GENERATION_ENABLED, ScopeInterface::SCOPE_STORE)
                ? self::SITEMAP_JOB
                : null;
        }

        $handler = $this->handlerPool->get($group);

        return $handler instanceof GroupDescriptionInterface ? $handler->getScheduledJob($group) : null;
    }

    /**
     * The job's cron expression as core's cron reads it, or null when it has none.
     *
     * @param string $job
     * @return string|null
     */
    private function expressionOf(string $job): ?string
    {
        foreach ($this->cronConfig->getJobs() as $jobs) {
            if (!isset($jobs[$job])) {
                continue;
            }
            $config     = $jobs[$job];
            $expression = isset($config['config_path'])
                ? (string) $this->scopeConfig->getValue($config['config_path'], ScopeInterface::SCOPE_STORE)
                : '';
            if ($expression === '') {
                $expression = (string) ($config['schedule'] ?? '');
            }

            return trim($expression) === '' ? null : $expression;
        }

        return null;
    }

    /**
     * The first minute after $after that the expression matches, in the configured timezone.
     *
     * Matched as core's Schedule::trySchedule() matches it. A day is checked before its hours and
     * minutes, so a year ahead costs at most a few hundred checks rather than half a million.
     *
     * @param string $expression
     * @param int $after
     * @throws CronException|\InvalidArgumentException When the expression is invalid
     * @return int|null
     */
    private function nextRun(string $expression, int $after): ?int
    {
        $fields = preg_split('#\s+#', trim($expression), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (\count($fields) < 5 || \count($fields) > 6) {
            throw new \InvalidArgumentException('expected five or six fields, got "' . $expression . '"');
        }
        [$minutes, $hours, $daysOfMonth, $months, $daysOfWeek] = $fields;

        $zone = new \DateTimeZone((string) $this->timezone->getConfigTimezone());
        $from = (new \DateTimeImmutable('@' . ($after - $after % 60 + 60)))->setTimezone($zone);
        $day  = $from; // only its date is read: each candidate sets its own time

        for ($i = 0; $i <= self::DAYS_AHEAD; $i++, $day = $day->modify('+1 day')) {
            if (!$this->matcher->matches($daysOfMonth, (int) $day->format('d'))
                || !$this->matcher->matches($months, (int) $day->format('m'))
                || !$this->matcher->matches($daysOfWeek, (int) $day->format('w'))
            ) {
                continue;
            }
            for ($hour = 0; $hour < 24; $hour++) {
                if (!$this->matcher->matches($hours, $hour)) {
                    continue;
                }
                for ($minute = 0; $minute < 60; $minute++) {
                    $at = $day->setTime($hour, $minute);
                    if ($at >= $from && $this->matcher->matches($minutes, $minute)) {
                        return $at->getTimestamp();
                    }
                }
            }
        }

        return null;
    }
}
