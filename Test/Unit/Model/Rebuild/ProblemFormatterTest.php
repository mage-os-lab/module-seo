<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Rebuild;

use Magento\Framework\Escaper;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\Rebuild\GroupDescriptionInterface;
use MageOS\Seo\Api\Rebuild\GroupHandlerInterface;
use MageOS\Seo\Model\Rebuild\HandlerPool;
use MageOS\Seo\Model\Rebuild\ProblemFormatter;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use MageOS\Seo\Model\Rebuild\RetrySchedule;
use MageOS\Seo\Model\Sitemap\RebuildGroup;
use MageOS\Seo\Model\Sitemap\SitemapFile;
use PHPUnit\Framework\TestCase;

/**
 * A rebuild problem in words an admin can act on: the files by name, which store or Site Map, since
 * when, when it is retried without anyone acting, and why.
 */
class ProblemFormatterTest extends TestCase
{
    private const SINCE = 1_800_000_000;

    private const NEXT = 1_800_050_000;

    /**
     * The next scheduled attempt per group; a group not listed has none.
     *
     * @var array<string, int>
     */
    private array $retries = [];

    protected function setUp(): void
    {
        $this->retries = ['feeds' => self::NEXT, 'sitemap-products' => self::NEXT];
    }

    public function testAFailureSaysWhatIsStillOnlineWhenItIsRetriedAndWhy(): void
    {
        $this->assertSame(
            'The feeds for store "Default Store View" could not be rebuilt (since date ' . self::SINCE . ').'
            . ' The previous version is still online.'
            . ' It will be retried automatically at date ' . self::NEXT . '.'
            . ' Reason: Disk full',
            $this->formatter()->line('feeds', '1', $this->failed('Disk full'), false)
        );
    }

    public function testAnIncompleteFileGivesItsReasonInTheAdminsLanguage(): void
    {
        $entry = [
            'kind'      => ProblemLog::KIND_DEGRADED,
            'reason'    => 'The lookup of %1 failed.',
            'arguments' => ['stock'],
            'since'     => self::SINCE,
        ];

        $this->assertSame(
            'The feeds for store "Default Store View" is incomplete (since date ' . self::SINCE . ').'
            . ' It will be retried automatically at date ' . self::NEXT . '.'
            . ' Reason: The lookup of stock failed.',
            $this->formatter()->line('feeds', '1', $entry, false)
        );
    }

    public function testAFailureIsKeptAsItWasThrownEvenWhenItLooksLikeAPhrase(): void
    {
        $line = $this->formatter()->line('feeds', '1', $this->failed('Cannot write %1'), false);

        $this->assertStringEndsWith('Reason: Cannot write %1', $line);
    }

    public function testAGroupWithNoScheduledJobIsRetriedWhenItsContentChanges(): void
    {
        $this->assertStringContainsString(
            'It will be retried automatically when the content changes.',
            $this->formatter()->line('plain', '1', $this->failed('Disk full'), false)
        );
    }

    public function testAStalledQueueSaysSinceWhenChangesAreWaitingAndWhenTheyStillArrive(): void
    {
        $entry = ['kind' => ProblemLog::KIND_STALLED, 'reason' => '', 'arguments' => null, 'since' => self::SINCE];

        $this->assertSame(
            'The feeds: changes since date ' . self::SINCE . ' are waiting, because the queue that rebuilds them'
            . ' is not running. They will still be included at the next scheduled rebuild, at date ' . self::NEXT . '.',
            $this->formatter()->line(ProblemLog::GROUP_QUEUE, 'feeds', $entry, false)
        );
    }

    public function testAStalledQueueWithNoScheduleAndNoKnownTimeSaysNeither(): void
    {
        $entry = ['kind' => ProblemLog::KIND_STALLED, 'reason' => '', 'arguments' => null, 'since' => null];

        $this->assertSame(
            'plain: changes are waiting, because the queue that rebuilds them is not running.'
            . ' They will be included once the queue runs again.',
            $this->formatter()->line(ProblemLog::GROUP_QUEUE, 'plain', $entry, false)
        );
    }

    public function testASitemapProblemNamesTheSiteMapByItsFile(): void
    {
        $this->assertStringStartsWith(
            'Site Map products for "/sitemap.xml" could not be rebuilt',
            $this->formatter()->line('sitemap-products', '3', $this->failed('Disk full'), false)
        );
    }

    public function testASiteMapOrStoreThatNoLongerExistsIsNamedByItsId(): void
    {
        $this->assertStringStartsWith(
            'Site Map products for Site Map ID 8 could not',
            $this->formatter()->line('sitemap-products', '8', $this->failed('Disk full'), false)
        );
        $this->assertStringStartsWith(
            'The feeds for store ID 9 could not',
            $this->formatter()->line('feeds', '9', $this->failed('Disk full'), false)
        );
    }

    public function testAProblemWithTheWholeGroupNamesEveryStoreOrEverySiteMap(): void
    {
        $this->assertStringStartsWith(
            'The feeds for every store is incomplete',
            $this->formatter()->line('feeds', ProblemLog::ALL, $this->degraded(), false)
        );
        $this->assertStringStartsWith(
            'Site Map products for all Site Maps could not',
            $this->formatter()->line('sitemap-products', ProblemLog::ALL, $this->failed('Out of memory'), false)
        );
    }

    public function testForTheBarEverythingThatCameFromOutsideIsEscaped(): void
    {
        $line = $this->formatter()->line('feeds', '2', $this->failed('<script>x</script>'), true);

        $this->assertStringContainsString('store &quot;&lt;b&gt;Shop&lt;/b&gt;&quot;', $line);
        $this->assertStringContainsString('Reason: &lt;script&gt;x&lt;/script&gt;', $line);
        $this->assertStringNotContainsString('<', $line);
    }

    public function testEachGroupHasALabel(): void
    {
        $formatter = $this->formatter();

        $this->assertSame('The feeds', $formatter->label('feeds'));
        $this->assertSame('plain', $formatter->label('plain'), 'A handler that gives no label is shown by its group.');
        $this->assertSame('Site Map CMS pages', $formatter->label('sitemap-pages'));
        $this->assertSame('Site Map categories', $formatter->label('sitemap-categories'));
        $this->assertSame('Site Map products', $formatter->label('sitemap-products'));
        $this->assertSame('Site Map other links', $formatter->label('sitemap-other'));
        $this->assertSame('Site Map blog', $formatter->label('sitemap-blog'));
        $this->assertSame('Site Map (all files)', $formatter->label('sitemap-*'));
        $this->assertSame('Site Map (first build)', $formatter->label(RebuildGroup::MISSING));
    }

    public function testTheCommandQuotesAGroupTheShellWouldExpand(): void
    {
        $formatter = $this->formatter();

        $this->assertSame('bin/magento seo:rebuild -g feeds', $formatter->command('feeds'));
        $this->assertSame("bin/magento seo:rebuild -g 'sitemap-*'", $formatter->command('sitemap-*'));
        $this->assertSame("bin/magento seo:rebuild -g 'my feed'", $formatter->command('my feed'));
        $this->assertNull($formatter->command(ProblemLog::GROUP_QUEUE));
    }

    public function testTimesAreShownWithAMediumDateAndAShortTime(): void
    {
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->expects($this->atLeastOnce())->method('formatDateTime')
            ->with(
                $this->isInstanceOf(\DateTimeInterface::class),
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT
            )
            ->willReturn('15 jan 2027 08:00');

        $line = $this->formatter($timezone)->line('feeds', '1', $this->failed('Disk full'), false);

        $this->assertStringContainsString('(since 15 jan 2027 08:00)', $line);
    }

    /**
     * @param string $reason
     * @return array{kind: string, reason: string, arguments: null, since: int}
     */
    private function failed(string $reason): array
    {
        return ['kind' => ProblemLog::KIND_FAILED, 'reason' => $reason, 'arguments' => null, 'since' => self::SINCE];
    }

    /**
     * @return array{kind: string, reason: string, arguments: string[], since: int}
     */
    private function degraded(): array
    {
        return ['kind' => ProblemLog::KIND_DEGRADED, 'reason' => 'Missing.', 'arguments' => [], 'since' => self::SINCE];
    }

    /**
     * The formatter, with a handler that labels `feeds` and one that gives `plain` no label.
     *
     * Store 1 is "Default Store View", store 2 has markup in its name, others do not exist; Site Map 3
     * is /sitemap.xml, others do not exist. Times read "date TIMESTAMP".
     *
     * @param TimezoneInterface|null $timezone
     * @return ProblemFormatter
     */
    private function formatter(?TimezoneInterface $timezone = null): ProblemFormatter
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

        $retrySchedule = $this->createStub(RetrySchedule::class);
        $retrySchedule->method('nextAttempt')->willReturnCallback(
            fn (string $group): ?int => $this->retries[$group] ?? null
        );

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(
            function (int $storeId): StoreInterface {
                $names = [1 => 'Default Store View', 2 => '<b>Shop</b>'];
                if (!isset($names[$storeId])) {
                    throw new NoSuchEntityException(__('No such store.'));
                }
                $store = $this->createStub(StoreInterface::class);
                $store->method('getName')->willReturn($names[$storeId]);

                return $store;
            }
        );

        $sitemapFile = $this->createStub(SitemapFile::class);
        $sitemapFile->method('pathOf')->willReturnCallback(
            static fn (int $sitemapId): ?string => $sitemapId === 3 ? '/sitemap.xml' : null
        );

        if ($timezone === null) {
            $timezone = $this->createStub(TimezoneInterface::class);
            $timezone->method('formatDateTime')->willReturnCallback(
                static fn (\DateTimeInterface $date): string => 'date ' . $date->getTimestamp()
            );
        }

        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeHtml')->willReturnCallback(
            static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES)
        );

        return new ProblemFormatter(
            new HandlerPool([$described, $plain]),
            new RebuildGroup(),
            $retrySchedule,
            $storeManager,
            $sitemapFile,
            $timezone,
            $escaper
        );
    }
}
