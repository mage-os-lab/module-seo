<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Rebuild;

use Magento\Framework\FlagManager;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use MageOS\Seo\Model\Rebuild\ProblemFormatter;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What the admin is shown about out-of-date files, and when it changes.
 */
class ProblemLogTest extends TestCase
{
    private const T0 = 1_800_000_000;

    /**
     * The flags, as the flag table would hold them.
     *
     * @var array<string, mixed>
     */
    private array $flags = [];

    /**
     * Flag writes made.
     *
     * @var int
     */
    private int $writes = 0;

    /**
     * Inbox notifications added, as [title, description, url].
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private array $notified = [];

    /**
     * The time, as a GMT timestamp.
     *
     * @var int
     */
    private int $now = 0;

    /**
     * Whether writing the flag throws, as a lost database connection would.
     *
     * @var bool
     */
    private bool $saveFails = false;

    protected function setUp(): void
    {
        $this->flags     = [];
        $this->writes    = 0;
        $this->notified  = [];
        $this->now       = self::T0;
        $this->saveFails = false;
    }

    public function testFailuresBecomeTheGroupsProblems(): void
    {
        $log = $this->log();

        $log->rebuilding('llms');
        $log->rebuilt('llms', [1 => 'Disk full']);

        $this->assertSame(
            ['llms' => [1 => ['kind' => 'failed', 'reason' => 'Disk full', 'arguments' => null, 'since' => self::T0]]],
            $log->all()
        );
    }

    public function testACleanRebuildClearsTheGroup(): void
    {
        $log = $this->log();
        $log->rebuilding('llms');
        $log->rebuilt('llms', [1 => 'Disk full']);

        $log->rebuilding('llms');
        $log->rebuilt('llms', []);

        $this->assertSame([], $log->all());
    }

    public function testAContinuingProblemKeepsWhenItBeganAndIsNotNotifiedAgain(): void
    {
        $log = $this->log();
        $log->rebuilding('llms');
        $log->rebuilt('llms', [1 => 'Disk full']);

        $this->now += 3600;
        $log->rebuilding('llms');
        $log->rebuilt('llms', [1 => 'Disk still full']);

        $this->assertSame(self::T0, $log->all()['llms'][1]['since']);
        $this->assertSame('Disk still full', $log->all()['llms'][1]['reason']);
        $this->assertCount(1, $this->notified, 'One inbox entry, when the problem first appeared.');
    }

    public function testTheInboxEntryIsWordedAsTheAdminMessageIs(): void
    {
        $log = $this->log();

        $log->rebuilding('llms');
        $log->rebuilt('llms', [1 => 'Disk full', 2 => 'Disk full']);

        [$title, $description, $url] = $this->notified[0];
        $this->assertSame('Some SEO files are out of date: label of llms', $title);
        $this->assertSame("plain line llms/1/failed\nplain line llms/2/failed", $description);
        $this->assertStringEndsWith('docs/rebuild-problems.md', $url);
    }

    public function testNothingToRecordWritesNothing(): void
    {
        $log = $this->log();

        $log->rebuilding('llms');
        $log->rebuilt('llms', []);

        $this->assertSame(0, $this->writes);
    }

    public function testADegradationIsKeptAsAPhraseToTranslateWhenShown(): void
    {
        $log = $this->log();

        $log->rebuilding('jsonl');
        $log->degraded('jsonl', 2, __('The lookup of %1 failed.', 'stock'));
        $log->rebuilt('jsonl', []);

        $this->assertSame(
            [
                'kind'      => 'degraded',
                'reason'    => 'The lookup of %1 failed.',
                'arguments' => ['stock'],
                'since'     => self::T0,
            ],
            $log->all()['jsonl'][2]
        );
    }

    public function testADegradationOutsideARebuildIsIgnored(): void
    {
        $log = $this->log();

        $log->degraded('jsonl', 2, __('The stock lookup failed.'));

        $this->assertSame([], $log->all());
        $this->assertSame(0, $this->writes);
    }

    public function testAFailureOutranksADegradationOfTheSameStoreView(): void
    {
        $log = $this->log();

        $log->rebuilding('jsonl');
        $log->degraded('jsonl', 2, __('The stock lookup failed.'));
        $log->rebuilt('jsonl', [2 => 'Disk full']);

        $this->assertSame(ProblemLog::KIND_FAILED, $log->all()['jsonl'][2]['kind']);
    }

    /**
     * The consumer brackets the group it hands a handler, and the handler brackets it again: only
     * the outermost bracket writes, with what both found.
     */
    public function testNestedBracketsWriteOnceWithWhatBothFound(): void
    {
        $log = $this->log();

        $log->rebuilding('jsonl');
        $log->rebuilding('jsonl');
        $log->degraded('jsonl', 2, __('The stock lookup failed.'));
        $log->rebuilt('jsonl', []);
        $this->assertSame(0, $this->writes, 'The inner bracket does not write.');

        $log->rebuilt('jsonl', [3 => 'Disk full']);

        $this->assertSame(1, $this->writes);
        $this->assertSame([3, 2], array_keys($log->all()['jsonl']));
    }

    public function testStorageTroubleIsAProblemOfEveryGroupBeingRebuilt(): void
    {
        $log = $this->log();

        $log->rebuilding('llms');
        $log->rebuilding('jsonl');
        $log->degradedWhileRebuilding(__('The storage directory was refused.'));
        $log->rebuilt('llms', []);
        $log->rebuilt('jsonl', []);

        $this->assertSame(ProblemLog::KIND_DEGRADED, $log->all()['llms'][ProblemLog::ALL]['kind']);
        $this->assertSame(ProblemLog::KIND_DEGRADED, $log->all()['jsonl'][ProblemLog::ALL]['kind']);
    }

    public function testAnAbandonedRebuildRecordsNothing(): void
    {
        $log = $this->log();

        $log->rebuilding('llms');
        $log->degraded('llms', 1, __('Something.'));
        $log->abandoned('llms');
        $log->degraded('llms', 1, __('After the rebuild.'));

        // The next rebuild starts clean: nothing of the abandoned one is carried into it.
        $log->rebuilding('llms');
        $log->rebuilt('llms', []);

        $this->assertSame([], $log->all());
    }

    public function testAnInnerRebuildThatIsAbandonedLeavesTheOuterOnesFindings(): void
    {
        $log = $this->log();

        $log->rebuilding('jsonl');
        $log->rebuilding('jsonl');
        $log->degraded('jsonl', 2, __('The stock lookup failed.'));
        $log->abandoned('jsonl');
        $log->rebuilt('jsonl', [3 => 'Disk full']);

        $this->assertSame([3, 2], array_keys($log->all()['jsonl']));
    }

    public function testAResultWithNoRebuildOpenIsARebuildOfItsOwn(): void
    {
        $log = $this->log();

        $log->rebuilt('llms', [1 => 'Disk full']);

        $this->assertSame(1, $this->writes);
        $this->assertSame([1], array_keys($log->all()['llms']));
    }

    public function testANamedPlaceholderKeepsItsKeyAndEveryArgumentIsText(): void
    {
        $log = $this->log();

        $log->rebuilding('jsonl');
        $log->degraded('jsonl', 2, __('The %what lookup failed %tries times.', ['what' => 'stock', 'tries' => 3]));
        $log->rebuilt('jsonl', []);

        $this->assertSame(['what' => 'stock', 'tries' => '3'], $log->all()['jsonl'][2]['arguments']);
    }

    public function testAStalledRequestIsShownSinceItWasQueuedUntilTheQueueRunsAgain(): void
    {
        $log      = $this->log();
        $queuedAt = self::T0 - 7200;

        $log->stalled('llms', $queuedAt);
        $this->assertSame(
            ['kind' => 'stalled', 'reason' => '', 'arguments' => null, 'since' => $queuedAt],
            $log->all()[ProblemLog::GROUP_QUEUE]['llms']
        );

        $log->resumed();
        $this->assertSame([], $log->all());
    }

    public function testStallsOfSeveralGroupsAreShownTogether(): void
    {
        $log = $this->log();

        $log->stalled('llms', self::T0 - 7200);
        $log->stalled('jsonl', self::T0 - 3600);

        $this->assertSame(['llms', 'jsonl'], array_keys($log->all()[ProblemLog::GROUP_QUEUE]));
    }

    public function testAStallSeenAgainKeepsTheFirstQueuedTime(): void
    {
        // Each stale request is queued again, so the second stall is of the re-queued request.
        $log = $this->log();

        $log->stalled('llms', self::T0 - 7200);
        $log->stalled('llms', self::T0);

        $this->assertSame(self::T0 - 7200, $log->all()[ProblemLog::GROUP_QUEUE]['llms']['since']);
    }

    public function testAStallWithNoKnownQueuedTimeSaysSo(): void
    {
        $log = $this->log();

        $log->stalled('llms', null);

        $this->assertNull($log->all()[ProblemLog::GROUP_QUEUE]['llms']['since']);
    }

    public function testResumingAQueueThatHadNotStalledWritesNothing(): void
    {
        $this->log()->resumed();

        $this->assertSame(0, $this->writes);
    }

    public function testAWholeRebuildSettlesTheIdInEveryGroupItCovers(): void
    {
        $log = $this->log();
        foreach (['sitemap-products', 'sitemap-pages', 'jsonl'] as $group) {
            $log->rebuilding($group);
            $log->rebuilt($group, [3 => 'Disk full', 4 => 'Disk full']);
        }

        $log->rebuiltWhole(
            'sitemap-*',
            3,
            null,
            static fn (string $group): bool => str_starts_with($group, 'sitemap-')
        );

        $this->assertSame([4], array_keys($log->all()['sitemap-products']));
        $this->assertSame([4], array_keys($log->all()['sitemap-pages']));
        $this->assertSame([3, 4], array_keys($log->all()['jsonl']), 'A group it does not cover is left alone.');
    }

    public function testAWholeRebuildThatFailsIsRecordedUnderItsOwnGroup(): void
    {
        $log = $this->log();
        $log->rebuilding('sitemap-products');
        $log->rebuilt('sitemap-products', [3 => 'Disk full']);

        $log->rebuiltWhole('sitemap-*', 3, 'Out of memory', static fn (): bool => true);

        $this->assertArrayNotHasKey('sitemap-products', $log->all());
        $this->assertSame('Out of memory', $log->all()['sitemap-*'][3]['reason']);
    }

    public function testAFailedWholeRebuildReplacesTheIdsEarlierFailureAndKeepsTheOthers(): void
    {
        $log = $this->log();
        $log->rebuilt('sitemap-*', [3 => 'Disk full', 4 => 'Disk full']);

        $log->rebuiltWhole('sitemap-*', 3, 'Out of memory', static fn (): bool => false);

        $this->assertSame('Out of memory', $log->all()['sitemap-*'][3]['reason']);
        $this->assertSame('Disk full', $log->all()['sitemap-*'][4]['reason']);
    }

    public function testAFailedWholeRebuildIsAddedToTheOtherProblemsOfItsGroup(): void
    {
        $log = $this->log();
        $log->rebuilt('sitemap-*', [4 => 'Disk full']);

        $log->rebuiltWhole('sitemap-*', 3, 'Out of memory', static fn (): bool => false);

        $this->assertSame([4, 3], array_keys($log->all()['sitemap-*']));
    }

    public function testAGroupLeftAsItWasDoesNotStopTheOthersChanging(): void
    {
        $log = $this->log();
        $log->rebuilt('sitemap-*', [3 => 'Disk full']);
        $log->rebuilt('sitemap-products', [3 => 'Disk full']);

        // The same failure again under sitemap-*, which comes first: that group does not change.
        $log->rebuiltWhole('sitemap-*', 3, 'Disk full', static fn (): bool => true);

        $this->assertArrayNotHasKey('sitemap-products', $log->all());
        $this->assertSame([3], array_keys($log->all()['sitemap-*']));
    }

    public function testABusyLockRecordsNothingAndDoesNotFailTheRebuild(): void
    {
        $lockManager = $this->createStub(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(false);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->stringContains('lock busy'));
        $log = $this->log($lockManager, $logger);

        $log->rebuilding('llms');
        $log->rebuilt('llms', [1 => 'Disk full']);

        $this->assertSame([], $log->all());
    }

    public function testTheLockIsReleasedAfterEveryChangeEvenOneThatFails(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);
        $lockManager->expects($this->exactly(2))->method('unlock');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('MageOS_Seo: could not record the rebuild problems: Database gone away');
        $log = $this->log($lockManager, $logger);

        $log->rebuilt('llms', [1 => 'Disk full']);
        $this->saveFails = true;
        $log->rebuilt('jsonl', [1 => 'Disk full']);

        $this->assertSame(['llms'], array_keys($log->all()), 'The failed write left the problems as they were.');
    }

    /**
     * @param LockManagerInterface|null $lockManager
     * @param LoggerInterface|null $logger
     * @return ProblemLog
     */
    private function log(?LockManagerInterface $lockManager = null, ?LoggerInterface $logger = null): ProblemLog
    {
        $flagManager = $this->createStub(FlagManager::class);
        $flagManager->method('getFlagData')->willReturnCallback(fn (string $code) => $this->flags[$code] ?? null);
        $flagManager->method('saveFlag')->willReturnCallback(
            function (string $code, mixed $value): bool {
                if ($this->saveFails) {
                    throw new \RuntimeException('Database gone away');
                }
                $this->flags[$code] = $value;
                $this->writes++;
                return true;
            }
        );

        if ($lockManager === null) {
            $lockManager = $this->createStub(LockManagerInterface::class);
            $lockManager->method('lock')->willReturn(true);
        }

        $notifier = $this->createStub(NotifierInterface::class);
        $notifier->method('addMajor')->willReturnCallback(
            function (string $title, string $description, string $url = '') use ($notifier): NotifierInterface {
                $this->notified[] = [$title, $description, $url];
                return $notifier;
            }
        );

        $dateTime = $this->createStub(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturnCallback(fn (): int => $this->now);

        $formatter = $this->createStub(ProblemFormatter::class);
        $formatter->method('label')->willReturnCallback(static fn (string $group): string => 'label of ' . $group);
        $formatter->method('line')->willReturnCallback(
            static fn (string $group, string $id, array $entry, bool $html): string
                => ($html ? 'html' : 'plain') . " line {$group}/{$id}/{$entry['kind']}"
        );

        return new ProblemLog(
            $flagManager,
            $lockManager,
            $notifier,
            $dateTime,
            $logger ?? $this->createStub(LoggerInterface::class),
            $formatter
        );
    }
}
