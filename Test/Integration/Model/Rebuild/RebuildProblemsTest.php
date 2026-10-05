<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Integration\Model\Rebuild;

use Magento\AdminNotification\Model\ResourceModel\Inbox\Collection as InboxCollection;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\FlagManager;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Phrase;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Api\Rebuild\GroupDescriptionInterface;
use MageOS\Seo\Api\Rebuild\GroupHandlerInterface;
use MageOS\Seo\Model\Config\Source\SitemapGenerator;
use MageOS\Seo\Model\Rebuild\HandlerPool;
use MageOS\Seo\Model\Rebuild\ProblemFormatter;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use MageOS\Seo\Model\Rebuild\RegenerateConsumer;
use MageOS\Seo\Model\Rebuild\RegenerationRequester;
use MageOS\Seo\Model\Rebuild\RetrySchedule;
use MageOS\Seo\Model\System\Message\RebuildProblems;
use MageOS\Seo\Test\Integration\Model\Sitemap\GeneratesSitemaps;
use MageOS\Seo\Test\Integration\Rebuild\CommitsDeferredRequests;
use PHPUnit\Framework\TestCase;

/**
 * A rebuild problem reaches the admin, in the System Messages bar and once in the inbox, and goes
 * once a rebuild gets through — whether the queue, core's sitemap cron or the Generate button ran it.
 *
 * @magentoAppArea adminhtml
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RebuildProblemsTest extends TestCase
{
    use GeneratesSitemaps;
    use CommitsDeferredRequests;

    private const GROUP = 'test-feeds';

    private const PENDING = 'mageos_seo_feed_pending_test-feeds';

    private const INBOX_TITLE = 'Some SEO files are out of date: Test feeds';

    /**
     * What the test handler's next rebuild fails with, per store view ID.
     *
     * @var array<int, string>
     */
    private array $failures = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->setUpSitemaps();
        $this->failures = [];
        $this->flags()->deleteFlag(ProblemLog::FLAG);
        $this->flags()->deleteFlag(self::PENDING);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->flags()->deleteFlag(ProblemLog::FLAG);
        $this->removeGeneratedSitemaps();
    }

    /**
     * @return void
     */
    public function testAFailedRebuildIsShownInTheBarAndOnceInTheInboxUntilARebuildSucceeds(): void
    {
        $this->failures = [1 => 'Disk full'];
        $this->consumer()->process(self::GROUP);

        $message = $this->message();
        $this->assertTrue($message->isDisplayed());
        $text = $message->getText();
        $this->assertStringContainsString(
            'Test feeds for store &quot;Default Store View&quot; could not be rebuilt',
            $text
        );
        $this->assertStringContainsString('It will be retried automatically when the content changes.', $text);
        $this->assertStringContainsString('Reason: Disk full', $text);
        $this->assertStringContainsString('<code>bin/magento seo:rebuild -g test-feeds</code>', $text);
        $this->assertSame(1, $this->inboxEntries());

        // Failing again is the same problem: no second inbox entry.
        $this->consumer()->process(self::GROUP);
        $this->assertSame(1, $this->inboxEntries());

        $this->failures = [];
        $this->consumer()->process(self::GROUP);
        $this->assertFalse($this->message()->isDisplayed());
    }

    /**
     * A request nobody picks up within the hour: the admin is told, with the time it was queued in
     * the admin's language and the configured timezone, and the consumer to check.
     *
     * @magentoConfigFixture default/general/locale/timezone Europe/Amsterdam
     * @return void
     */
    public function testAStalledQueueIsShownSinceTheRebuildWasQueuedUntilTheQueueRunsAgain(): void
    {
        $queuedAt = (int) strtotime('2026-01-15 07:00:00 UTC');
        $this->flags()->saveFlag(self::PENDING, $queuedAt);

        $this->requester()->request(self::GROUP);
        $this->commitDeferredRequests();

        $text = $this->message()->getText();
        $this->assertStringContainsString(
            'Test feeds: changes since Jan 15, 2026, 8:00 AM are waiting, because the queue that rebuilds them'
            . ' is not running.',
            $text
        );
        $this->assertStringContainsString('check that the mageosSeoFeedRegenerate consumer is started', $text);

        $this->consumer()->process(self::GROUP);
        $this->assertFalse($this->message()->isDisplayed());
    }

    /**
     * The Generate button and core's cron write the whole sitemap through generateXml(), which
     * settles that sitemap's problems; another sitemap's are left.
     *
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testGeneratingASiteMapSettlesItsProblems(): void
    {
        $this->useGenerator(SitemapGenerator::MAGEOS_SEO);
        $sitemap = $this->sitemapFor($this->defaultStoreId());
        $sitemap->save();
        $sitemapId = (int) $sitemap->getId();

        $log = Bootstrap::getObjectManager()->get(ProblemLog::class);
        $log->rebuilding('sitemap-products');
        $log->rebuilt('sitemap-products', [$sitemapId => 'Disk full', $sitemapId + 1000 => 'Disk full']);

        $this->generateSitemap($sitemap);

        $this->assertSame([$sitemapId + 1000], array_keys($log->all()['sitemap-products'] ?? []));
    }

    /**
     * The consumer, with a registered handler for the test group that fails as the test says.
     *
     * @return RegenerateConsumer
     */
    private function consumer(): RegenerateConsumer
    {
        return Bootstrap::getObjectManager()->create(RegenerateConsumer::class, [
            'handlerPool' => $this->handlerPool(),
            'problemLog'  => $this->problemLog(),
        ]);
    }

    /**
     * @return RegenerationRequester
     */
    private function requester(): RegenerationRequester
    {
        return Bootstrap::getObjectManager()->create(RegenerationRequester::class, [
            'publisher'  => $this->createStub(PublisherInterface::class),
            'problemLog' => $this->problemLog(),
        ]);
    }

    /**
     * The System Messages bar's message, for an admin who may manage SEO.
     *
     * @return RebuildProblems
     */
    private function message(): RebuildProblems
    {
        $authorization = $this->createStub(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);

        return Bootstrap::getObjectManager()->create(RebuildProblems::class, [
            'problemLog'    => $this->problemLog(),
            'formatter'     => $this->formatter(),
            'authorization' => $authorization,
        ]);
    }

    /**
     * The problem log, wording its inbox entries with the test handler's label.
     *
     * @return ProblemLog
     */
    private function problemLog(): ProblemLog
    {
        return Bootstrap::getObjectManager()->create(ProblemLog::class, ['formatter' => $this->formatter()]);
    }

    /**
     * @return ProblemFormatter
     */
    private function formatter(): ProblemFormatter
    {
        $objectManager = Bootstrap::getObjectManager();
        $pool          = $this->handlerPool();

        return $objectManager->create(ProblemFormatter::class, [
            'handlerPool'   => $pool,
            'retrySchedule' => $objectManager->create(RetrySchedule::class, ['handlerPool' => $pool]),
        ]);
    }

    /**
     * A pool with one handler: the test group, labelled "Test feeds", with no scheduled job.
     *
     * @return HandlerPool
     */
    private function handlerPool(): HandlerPool
    {
        $test    = $this;
        $handler = new class ($test) implements GroupHandlerInterface, GroupDescriptionInterface {
            /**
             * @param RebuildProblemsTest $test
             */
            public function __construct(private readonly RebuildProblemsTest $test)
            {
            }

            public function getGroups(): array
            {
                return ['test-feeds'];
            }

            public function isEnabled(string $group): bool
            {
                return true;
            }

            public function rebuild(?string $group): array
            {
                return $this->test->failuresOfTheNextRebuild();
            }

            public function getLabel(string $group): Phrase
            {
                return __('Test feeds');
            }

            public function getScheduledJob(string $group): ?string
            {
                return null;
            }
        };

        return new HandlerPool([$handler]);
    }

    /**
     * What the test handler's rebuild returns.
     *
     * @return array<int, string>
     */
    public function failuresOfTheNextRebuild(): array
    {
        return $this->failures;
    }

    /**
     * @return int
     */
    private function inboxEntries(): int
    {
        return Bootstrap::getObjectManager()->create(InboxCollection::class)
            ->addFieldToFilter('title', self::INBOX_TITLE)
            ->getSize();
    }

    /**
     * @return FlagManager
     */
    private function flags(): FlagManager
    {
        return Bootstrap::getObjectManager()->get(FlagManager::class);
    }
}
