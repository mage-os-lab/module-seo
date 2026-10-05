<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Rebuild;

use Magento\Cron\Model\ConfigInterface as CronConfig;
use Magento\Cron\Model\Schedule;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use MageOS\Seo\Api\Rebuild\GroupDescriptionInterface;
use MageOS\Seo\Api\Rebuild\GroupHandlerInterface;
use MageOS\Seo\Model\Rebuild\CronExpressionMatcher;
use MageOS\Seo\Model\Rebuild\HandlerPool;
use MageOS\Seo\Model\Rebuild\RetrySchedule;
use MageOS\Seo\Model\Sitemap\RebuildGroup;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * When a failed rebuild is retried without anyone acting: the next run of the cron job that rebuilds
 * the group, as core's scheduler would run it, in the configured timezone.
 */
class RetryScheduleTest extends TestCase
{
    private const ZONE = 'Europe/Amsterdam';

    /**
     * The cron jobs, as core's cron configuration lists them per cron group.
     *
     * @var array<string, array<string, array<string, string>>>
     */
    private array $jobs = [];

    /**
     * Configuration values by path.
     *
     * @var array<string, string>
     */
    private array $config = [];

    protected function setUp(): void
    {
        $this->jobs   = ['default' => ['feeds_nightly' => ['schedule' => '30 2 * * *']]];
        $this->config = [];
    }

    public function testAFeedIsRetriedAtItsJobsNextRunTomorrow(): void
    {
        $this->assertSame(
            $this->at('2027-01-16 02:30'),
            $this->schedule('2027-01-15 11:00')->nextAttempt('feeds')
        );
    }

    public function testAFeedIsRetriedLaterTodayWhenTheRunIsStillToCome(): void
    {
        $this->assertSame(
            $this->at('2027-01-15 02:30'),
            $this->schedule('2027-01-15 01:00')->nextAttempt('feeds')
        );
    }

    public function testARunAMinuteAwayIsTheNextOne(): void
    {
        $this->assertSame(
            $this->at('2027-01-15 02:30'),
            $this->schedule('2027-01-15 02:29')->nextAttempt('feeds')
        );
        $this->assertSame(
            $this->at('2027-01-15 02:30'),
            $this->schedule('2027-01-15 02:29:30')->nextAttempt('feeds'),
            'Part-way through a minute, the next minute is still to come.'
        );
    }

    public function testTheRunDueThisMinuteHasAlreadyBeenTaken(): void
    {
        // The cron starts a minute's jobs at the top of it: one due now is running or done.
        $this->assertSame(
            $this->at('2027-01-16 02:30'),
            $this->schedule('2027-01-15 02:30')->nextAttempt('feeds')
        );
    }

    public function testAScheduleSetInTheConfigurationIsTheOneThatRuns(): void
    {
        $this->jobs['default']['feeds_nightly'] = ['schedule' => '30 2 * * *', 'config_path' => 'feeds/cron'];
        $this->config['feeds/cron']             = '0 4 * * 1';

        // 2027-01-15 is a Friday: the next Monday is the 18th.
        $this->assertSame(
            $this->at('2027-01-18 04:00'),
            $this->schedule('2027-01-15 11:00')->nextAttempt('feeds')
        );
    }

    public function testAnEmptyConfiguredScheduleFallsBackToTheJobsOwn(): void
    {
        $this->jobs['default']['feeds_nightly'] = ['schedule' => '30 2 * * *', 'config_path' => 'feeds/cron'];

        $this->assertSame(
            $this->at('2027-01-16 02:30'),
            $this->schedule('2027-01-15 11:00')->nextAttempt('feeds')
        );
    }

    public function testAJobInAnotherCronGroupIsFound(): void
    {
        $this->jobs = ['index' => ['feeds_nightly' => ['schedule' => '*/15 * * * *']]];

        $this->assertSame(
            $this->at('2027-01-15 11:15'),
            $this->schedule('2027-01-15 11:07')->nextAttempt('feeds')
        );
    }

    public function testAMonthlyJobIsFoundWeeksAhead(): void
    {
        $this->jobs['default']['feeds_nightly'] = ['schedule' => '0 3 1 * *'];

        $this->assertSame(
            $this->at('2027-02-01 03:00'),
            $this->schedule('2027-01-15 11:00')->nextAttempt('feeds')
        );
    }

    public function testASixthFieldIsAllowedAsCoreAllowsIt(): void
    {
        $this->jobs['default']['feeds_nightly'] = ['schedule' => '30 2 * * * 2027'];

        $this->assertSame(
            $this->at('2027-01-16 02:30'),
            $this->schedule('2027-01-15 11:00')->nextAttempt('feeds')
        );
    }

    public function testAScheduleThatNeverMatchesRetriesNothing(): void
    {
        // The 30th of February: looked for a year ahead, then given up.
        $this->jobs['default']['feeds_nightly'] = ['schedule' => '0 3 30 2 *'];

        $this->assertNull($this->schedule('2027-01-15 11:00')->nextAttempt('feeds'));
    }

    public function testABlankScheduleIsNoScheduleRatherThanAnError(): void
    {
        $this->jobs['default']['feeds_nightly'] = ['schedule' => '   '];
        $logger                                 = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $this->assertNull($this->schedule('2027-01-15 11:00', $logger)->nextAttempt('feeds'));
    }

    public function testAJobWithoutAScheduleRetriesNothing(): void
    {
        $this->jobs['default']['feeds_nightly'] = [];

        $this->assertNull($this->schedule('2027-01-15 11:00')->nextAttempt('feeds'));
    }

    public function testAJobThatIsNotConfiguredRetriesNothing(): void
    {
        $this->jobs = [];

        $this->assertNull($this->schedule('2027-01-15 11:00')->nextAttempt('feeds'));
    }

    public function testAGroupWhoseHandlerNamesNoJobRetriesNothing(): void
    {
        $this->assertNull($this->schedule('2027-01-15 11:00')->nextAttempt('plain'));
    }

    public function testAnUnknownGroupRetriesNothing(): void
    {
        $this->assertNull($this->schedule('2027-01-15 11:00')->nextAttempt('nobody-owns-this'));
    }

    public function testSitemapsAreRetriedByCoresSitemapCronWhileGenerationIsEnabled(): void
    {
        $this->jobs['default']['sitemap_generate'] = ['schedule' => '0 3 * * *'];
        $this->config['sitemap/generate/enabled']  = '1';

        $schedule = $this->schedule('2027-01-15 11:00');

        $this->assertSame($this->at('2027-01-16 03:00'), $schedule->nextAttempt('sitemap-products'));
        $this->assertSame($this->at('2027-01-16 03:00'), $schedule->nextAttempt(RebuildGroup::MISSING));
    }

    public function testSitemapsAreNotRetriedOnAScheduleWhileGenerationIsDisabled(): void
    {
        $this->jobs['default']['sitemap_generate'] = ['schedule' => '0 3 * * *'];

        $this->assertNull($this->schedule('2027-01-15 11:00')->nextAttempt('sitemap-products'));
    }

    public function testAnInvalidScheduleIsLoggedAndRetriesNothing(): void
    {
        $this->jobs['default']['feeds_nightly'] = ['schedule' => 'every night'];
        $logger                                 = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->logicalAnd(
            $this->stringContains('feeds_nightly'),
            $this->stringContains('expected five or six fields, got "every night"')
        ));

        $this->assertNull($this->schedule('2027-01-15 11:00', $logger)->nextAttempt('feeds'));
    }

    /**
     * A wall-clock time in the configured timezone, as a timestamp.
     *
     * @param string $localTime
     * @return int
     */
    private function at(string $localTime): int
    {
        return (new \DateTimeImmutable($localTime, new \DateTimeZone(self::ZONE)))->getTimestamp();
    }

    /**
     * The schedule at a wall-clock time, with a handler that names a job for `feeds` and one that
     * implements only the handler contract for `plain`.
     *
     * @param string $now
     * @param LoggerInterface|null $logger
     * @return RetrySchedule
     */
    private function schedule(string $now, ?LoggerInterface $logger = null): RetrySchedule
    {
        $described = new class () implements GroupHandlerInterface, GroupDescriptionInterface {
            public function getGroups(): array
            {
                return ['feeds'];
            }

            public function isEnabled(string $group): bool
            {
                return true;
            }

            public function rebuild(?string $group): array
            {
                return [];
            }

            public function getLabel(string $group): Phrase
            {
                return __('The feeds');
            }

            public function getScheduledJob(string $group): string
            {
                return 'feeds_nightly';
            }
        };
        $plain = $this->createStub(GroupHandlerInterface::class);
        $plain->method('getGroups')->willReturn(['plain']);

        $cronConfig = $this->createStub(CronConfig::class);
        $cronConfig->method('getJobs')->willReturnCallback(fn (): array => $this->jobs);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(fn (string $path) => $this->config[$path] ?? null);
        $scopeConfig->method('isSetFlag')->willReturnCallback(fn (string $path): bool => !empty($this->config[$path]));

        // Core's own matcher: the schedule model's matching keeps no state and needs no collaborator.
        $coreSchedule = (new \ReflectionClass(Schedule::class))->newInstanceWithoutConstructor();
        $matcher      = $this->createStub(CronExpressionMatcher::class);
        $matcher->method('matches')->willReturnCallback(
            static fn (string $field, int $value): bool => (bool) $coreSchedule->matchCronExpression($field, $value)
        );

        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn(self::ZONE);

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn($this->at($now));

        return new RetrySchedule(
            new HandlerPool([$described, $plain]),
            new RebuildGroup(),
            $cronConfig,
            $matcher,
            $scopeConfig,
            $timezone,
            $dateTime,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }
}
