<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Feed;

use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Feed\FeedCache;
use MageOS\Seo\Model\Feed\FeedFileWriter;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\FeedStorage;
use MageOS\Seo\Model\Hreflang\SitemapFileWriter;
use MageOS\Seo\Model\Hreflang\SitemapGenerator;
use MageOS\Seo\Model\Hreflang\StoreLocaleMap;
use MageOS\Seo\Model\LlmsJsonl\JsonlBuilder;
use MageOS\Seo\Model\LlmsTxt\LlmsTxtBuilder;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FeedRegeneratorTest extends TestCase
{
    /**
     * @var StoreManagerInterface&Stub
     */
    private StoreManagerInterface&Stub $storeManager;

    /**
     * @var Emulation&Stub
     */
    private Emulation&Stub $emulation;

    /**
     * @var Config&Stub
     */
    private Config&Stub $seoConfig;

    /**
     * @var LlmsTxtBuilder&Stub
     */
    private LlmsTxtBuilder&Stub $llmsTxtBuilder;

    /**
     * @var JsonlBuilder&Stub
     */
    private JsonlBuilder&Stub $jsonlBuilder;

    /**
     * @var SitemapFileWriter&Stub
     */
    private SitemapFileWriter&Stub $sitemapFileWriter;

    /**
     * @var StoreLocaleMap&Stub
     */
    private StoreLocaleMap&Stub $storeLocaleMap;

    /**
     * @var FeedStorage&Stub
     */
    private FeedStorage&Stub $feedStorage;

    /**
     * @var FeedCache&Stub
     */
    private FeedCache&Stub $feedCache;

    /**
     * @var LoggerInterface&Stub
     */
    private LoggerInterface&Stub $logger;

    /**
     * Storage, sitemap and cache calls in the order they happened.
     *
     * @var list<string>
     */
    private array $calls = [];

    /**
     * Chunk file names the sitemap writer reports per generated store view.
     *
     * @var string[]
     */
    private array $chunkNames = [];

    /**
     * Store view IDs the storage reports as having a feed directory.
     *
     * @var int[]
     */
    private array $storeDirectories = [];

    protected function setUp(): void
    {
        $this->storeManager      = $this->createStub(StoreManagerInterface::class);
        $this->emulation         = $this->createStub(Emulation::class);
        $this->seoConfig         = $this->createStub(Config::class);
        $this->llmsTxtBuilder    = $this->createStub(LlmsTxtBuilder::class);
        $this->jsonlBuilder      = $this->createStub(JsonlBuilder::class);
        $this->sitemapFileWriter = $this->createStub(SitemapFileWriter::class);
        $this->storeLocaleMap    = $this->createStub(StoreLocaleMap::class);
        $this->feedStorage       = $this->createStub(FeedStorage::class);
        $this->feedCache         = $this->createStub(FeedCache::class);
        $this->logger            = $this->createStub(LoggerInterface::class);
        $this->calls             = [];
        $this->chunkNames        = [];
        $this->storeDirectories  = [];

        $this->feedStorage->method('listStoreDirectories')->willReturnCallback(
            fn (): array => $this->storeDirectories
        );
        $this->feedStorage->method('deleteStoreDirectory')->willReturnCallback(
            function (int $storeId): void {
                $this->calls[] = "delete directory {$storeId}";
            }
        );

        $this->feedStorage->method('write')->willReturnCallback(
            function (string $fileName, int $storeId, string $content): void {
                $this->calls[] = "write {$storeId}/{$fileName}={$content}";
            }
        );
        $this->feedStorage->method('openForWrite')->willReturnCallback(
            fn (int $storeId): FeedFileWriter => $this->recordingFile($storeId)
        );
        $this->feedStorage->method('deleteForStore')->willReturnCallback(
            function (string $pattern, int $storeId): void {
                $this->calls[] = "delete {$storeId}/{$pattern}";
            }
        );
        $this->feedStorage->method('copyBetweenStores')->willReturnCallback(
            function (string $fileName, int $from, int $to): void {
                $this->calls[] = "copy {$fileName} {$from}->{$to}";
            }
        );
        $this->feedCache->method('purge')->willReturnCallback(
            function (array $groups): void {
                $this->calls[] = 'purge ' . implode(',', $groups);
            }
        );
        $this->sitemapFileWriter->method('write')->willReturnCallback(
            function (int $storeId): array {
                $this->calls[] = "sitemap build {$storeId}";
                return $this->chunkNames;
            }
        );
        $this->sitemapFileWriter->method('writeIndex')->willReturnCallback(
            function (int $storeId): void {
                $this->calls[] = "sitemap index {$storeId}";
            }
        );
    }

    public function testInactiveStoresAreSkipped(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getIsActive')->willReturn(false);
        $this->storeManager->method('getStores')->willReturn([$store]);
        $emulation = $this->createMock(Emulation::class);
        $emulation->expects($this->never())->method('startEnvironmentEmulation');
        $this->emulation = $emulation;

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_LLMS);

        $this->assertSame(['purge llms'], $this->calls);
    }

    public function testGroupFilterBuildsOnlyTheRequestedGroup(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore()]);
        $this->seoConfig->method('isLlmsTxtEnabled')->willReturn(true);
        $this->seoConfig->method('isLlmsFullTxtEnabled')->willReturn(true);
        $this->llmsTxtBuilder->method('buildConcise')->willReturn('concise');
        $this->llmsTxtBuilder->method('buildFull')->willReturn('full');
        $jsonlBuilder = $this->createMock(JsonlBuilder::class);
        $jsonlBuilder->expects($this->never())->method('stream');
        $this->jsonlBuilder = $jsonlBuilder;
        $sitemapFileWriter = $this->createMock(SitemapFileWriter::class);
        $sitemapFileWriter->expects($this->never())->method('write');
        $this->sitemapFileWriter = $sitemapFileWriter;

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_LLMS);

        $this->assertSame(
            ['write 1/llms.txt=concise', 'write 1/llms-full.txt=full', 'purge llms'],
            $this->calls
        );
    }

    public function testJsonlIsStreamedToItsFileInsteadOfBuiltInMemory(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore()]);
        $this->seoConfig->method('isLlmsJsonlEnabled')->willReturn(true);
        $this->jsonlBuilder->method('stream')->willReturnCallback(
            static function (): \Generator {
                yield "{\"a\":1}\n";
                yield "{\"b\":2}\n";
            }
        );

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_JSONL);

        $this->assertSame(
            ['stream 1: {"a":1}' . "\n" . '{"b":2}' . "\n" . ' -> llms.jsonl', 'purge jsonl'],
            $this->calls
        );
    }

    public function testAFailedStreamDiscardsThePartialFile(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore()]);
        $this->seoConfig->method('isLlmsJsonlEnabled')->willReturn(true);
        $this->jsonlBuilder->method('stream')->willReturnCallback(
            static function (): \Generator {
                yield "{\"a\":1}\n";
                throw new \RuntimeException('collection failed');
            }
        );

        $failures = $this->regenerator()->regenerate(FeedRegenerator::GROUP_JSONL);

        $this->assertSame([1 => 'collection failed'], $failures);
        $this->assertContains('discard 1', $this->calls);
        $this->assertNotContains('commit 1 llms.jsonl', $this->calls);
    }

    public function testDisabledFeedsAreRemovedInsteadOfWritten(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore()]);
        $this->seoConfig->method('isLlmsTxtEnabled')->willReturn(false);
        $this->seoConfig->method('isLlmsFullTxtEnabled')->willReturn(false);
        $this->seoConfig->method('isLlmsJsonlEnabled')->willReturn(false);
        $llmsTxtBuilder = $this->createMock(LlmsTxtBuilder::class);
        $llmsTxtBuilder->expects($this->never())->method('buildConcise');
        $llmsTxtBuilder->expects($this->never())->method('buildFull');
        $this->llmsTxtBuilder = $llmsTxtBuilder;

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_LLMS);
        $this->regenerator()->regenerate(FeedRegenerator::GROUP_JSONL);

        $this->assertSame(
            ['delete 1/llms.txt', 'delete 1/llms-full.txt', 'purge llms', 'delete 1/llms.jsonl', 'purge jsonl'],
            $this->calls
        );
    }

    public function testHreflangIsBuiltOncePerAlternateSetAndCopiedToTheOtherStoreViews(): void
    {
        // Store views sharing an alternate set get byte-identical chunks: building the whole
        // catalogue again per store view is what made this quadratic.
        $this->givenHreflangEligible([1, 2, 3]);
        $this->chunkNames = ['hreflang-sitemap-1.xml', 'hreflang-sitemap-2.xml'];

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_HREFLANG);

        $this->assertSame(
            [
                'sitemap build 1',
                'copy hreflang-sitemap-1.xml 1->2',
                'copy hreflang-sitemap-2.xml 1->2',
                'sitemap index 2',
                'copy hreflang-sitemap-1.xml 1->3',
                'copy hreflang-sitemap-2.xml 1->3',
                'sitemap index 3',
                'purge hreflang',
            ],
            $this->calls
        );
    }

    public function testASingleDocumentSetIsCopiedWholeIncludingTheIndexFile(): void
    {
        $this->givenHreflangEligible([1, 2]);
        $this->chunkNames = [];

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_HREFLANG);

        $this->assertSame(
            ['sitemap build 1', 'copy ' . SitemapGenerator::INDEX_FILE . ' 1->2', 'purge hreflang'],
            $this->calls
        );
    }

    public function testStoreViewsWithDifferentAlternateSetsAreBuiltSeparately(): void
    {
        // Two websites with hreflang limited to their own website: different sets, no sharing.
        $this->storeManager->method('getStores')->willReturn([$this->activeStore(1), $this->activeStore(2)]);
        $this->storeManager->method('getStore')->willReturn($this->activeStore());
        $this->seoConfig->method('isHreflangEnabled')->willReturn(true);
        $this->seoConfig->method('isHreflangSitemapEnabled')->willReturn(true);
        // The map is store-scoped and reset between emulations; each store view asks twice
        // (eligibility, then the alternate set's signature).
        $lookups = 0;
        $this->storeLocaleMap->method('getMap')->willReturnCallback(
            static function () use (&$lookups): array {
                return ++$lookups <= 2
                    ? [1 => ['locale' => 'en-GB'], 2 => ['locale' => 'de-DE']]
                    : [3 => ['locale' => 'fr-FR'], 4 => ['locale' => 'nl-NL']];
            }
        );

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_HREFLANG);

        $this->assertSame(['sitemap build 1', 'sitemap build 2', 'purge hreflang'], $this->calls);
    }

    public function testChunksTheNewSetNoLongerContainsAreRemovedAfterTheIndex(): void
    {
        $this->givenHreflangEligible([1]);
        $this->chunkNames = ['hreflang-sitemap-1.xml'];
        $this->feedStorage->method('listForStore')->willReturn([
            'hreflang-sitemap-1.xml',
            'hreflang-sitemap-2.xml',
        ]);

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_HREFLANG);

        $this->assertSame(
            ['sitemap build 1', 'delete 1/hreflang-sitemap-2.xml', 'purge hreflang'],
            $this->calls
        );
    }

    public function testHreflangFilesAreRemovedWhenFewerThanTwoLocalesQualify(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore()]);
        $this->seoConfig->method('isHreflangEnabled')->willReturn(true);
        $this->seoConfig->method('isHreflangSitemapEnabled')->willReturn(true);
        $this->storeLocaleMap->method('getMap')->willReturn([1 => ['only-one']]);
        $sitemapFileWriter = $this->createMock(SitemapFileWriter::class);
        $sitemapFileWriter->expects($this->never())->method('write');
        $this->sitemapFileWriter = $sitemapFileWriter;

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_HREFLANG);

        $this->assertSame(['delete 1/hreflang-sitemap*.xml', 'purge hreflang'], $this->calls);
    }

    public function testRebuiltGroupIsPurgedOnceAfterEveryStore(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore(1), $this->activeStore(2)]);
        $this->seoConfig->method('isLlmsJsonlEnabled')->willReturn(true);
        $this->jsonlBuilder->method('stream')->willReturnCallback(
            static function (): \Generator {
                yield 'lines';
            }
        );

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_JSONL);

        $this->assertSame(
            ['stream 1: lines -> llms.jsonl', 'stream 2: lines -> llms.jsonl', 'purge jsonl'],
            $this->calls
        );
    }

    public function testAFullRebuildSweepsTheDirectoriesOfStoreViewsThatNoLongerExist(): void
    {
        // Deleting a website or a store group takes its store views with it in the database,
        // dispatching no event, so the directories are swept rather than chased.
        $this->storeManager->method('getStores')->willReturn([$this->activeStore(1)]);
        $this->storeDirectories = [1, 7, 9];

        $this->regenerator()->regenerate();

        // The store view that still exists keeps its directory; the build's own calls come first.
        $this->assertSame(
            ['delete directory 7', 'delete directory 9', 'purge llms,jsonl,hreflang'],
            \array_slice($this->calls, -3)
        );
    }

    public function testASingleGroupRebuildDoesNotSweepDirectories(): void
    {
        $this->storeManager->method('getStores')->willReturn([]);
        $this->storeDirectories = [7];

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_HREFLANG);

        $this->assertSame(['purge hreflang'], $this->calls);
    }

    public function testFullRebuildPurgesEveryGroup(): void
    {
        $this->storeManager->method('getStores')->willReturn([]);

        $this->regenerator()->regenerate();

        $this->assertSame(['purge llms,jsonl,hreflang'], $this->calls);
    }

    public function testPurgeFailureIsLoggedNotThrown(): void
    {
        $this->storeManager->method('getStores')->willReturn([]);
        $feedCache = $this->createStub(FeedCache::class);
        $feedCache->method('purge')->willThrowException(new \RuntimeException('varnish down'));
        $this->feedCache = $feedCache;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('varnish down'));
        $this->logger = $logger;

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_LLMS);
    }

    public function testAFailingStoreIsLoggedAndTheOtherStoresAreStillBuilt(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore(1), $this->activeStore(2)]);
        $this->seoConfig->method('isLlmsTxtEnabled')->willReturn(true);
        $builds = 0;
        $this->llmsTxtBuilder->method('buildConcise')->willReturnCallback(
            static function () use (&$builds): string {
                if (++$builds === 1) {
                    throw new \RuntimeException('build failed');
                }
                return 'concise';
            }
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('store 1'));
        $this->logger = $logger;

        $failures = $this->regenerator()->regenerate(FeedRegenerator::GROUP_LLMS);

        // llms-full.txt is disabled in this test, so it is removed for the store that built.
        $this->assertSame(['write 2/llms.txt=concise', 'delete 2/llms-full.txt', 'purge llms'], $this->calls);
        $this->assertSame([1 => 'build failed'], $failures);
    }

    public function testASuccessfulRebuildReportsNoFailures(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore()]);

        $this->assertSame([], $this->regenerator()->regenerate(FeedRegenerator::GROUP_JSONL));
    }

    public function testEmulationStoppedAndLocaleMapResetInFinallyOnThrow(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore()]);
        $this->seoConfig->method('isLlmsTxtEnabled')->willReturn(true);
        $this->llmsTxtBuilder->method('buildConcise')->willThrowException(new \RuntimeException('build failed'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $this->logger = $logger;
        $storeLocaleMap = $this->createMock(StoreLocaleMap::class);
        $storeLocaleMap->expects($this->once())->method('reset');
        $this->storeLocaleMap = $storeLocaleMap;
        $emulation = $this->createMock(Emulation::class);
        $emulation->expects($this->once())->method('stopEnvironmentEmulation');
        $this->emulation = $emulation;

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_LLMS);
    }

    /**
     * Build the regenerator from the current collaborators.
     *
     * @return FeedRegenerator
     */
    private function regenerator(): FeedRegenerator
    {
        return new FeedRegenerator(
            $this->storeManager,
            $this->emulation,
            $this->seoConfig,
            $this->llmsTxtBuilder,
            $this->jsonlBuilder,
            $this->sitemapFileWriter,
            $this->storeLocaleMap,
            $this->feedStorage,
            $this->feedCache,
            $this->logger
        );
    }

    /**
     * An active store view.
     *
     * @param int $id
     * @return Store
     */
    private function activeStore(int $id = 1): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getIsActive')->willReturn(true);
        $store->method('getId')->willReturn($id);
        $store->method('getBaseUrl')->willReturn('https://example.com/');

        return $store;
    }

    /**
     * Active store views for which the hreflang sitemap is enabled and has two locales.
     *
     * @param int[] $storeIds
     * @return void
     */
    private function givenHreflangEligible(array $storeIds): void
    {
        $this->storeManager->method('getStores')->willReturn(
            array_map(fn (int $id): Store => $this->activeStore($id), $storeIds)
        );
        $this->storeManager->method('getStore')->willReturn($this->activeStore());
        $this->seoConfig->method('isHreflangEnabled')->willReturn(true);
        $this->seoConfig->method('isHreflangSitemapEnabled')->willReturn(true);
        $this->storeLocaleMap->method('getMap')->willReturn([1 => ['x'], 2 => ['y']]);
    }

    /**
     * A file writer that records what was streamed into it and how it ended.
     *
     * @param int $storeId
     * @return FeedFileWriter
     */
    private function recordingFile(int $storeId): FeedFileWriter
    {
        $content = '';

        $file = $this->createStub(FeedFileWriter::class);
        $file->method('write')->willReturnCallback(
            static function (string $part) use (&$content): void {
                $content .= $part;
            }
        );
        $file->method('commit')->willReturnCallback(
            function (string $fileName) use (&$content, $storeId): void {
                $this->calls[] = "stream {$storeId}: {$content} -> {$fileName}";
            }
        );
        $file->method('discard')->willReturnCallback(
            function () use ($storeId): void {
                $this->calls[] = "discard {$storeId}";
            }
        );

        return $file;
    }
}
