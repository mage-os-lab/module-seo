<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Feed;

use Magento\Framework\App\Area;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Hreflang\SitemapFileWriter;
use MageOS\Seo\Model\Hreflang\SitemapGenerator;
use MageOS\Seo\Model\Hreflang\StoreLocaleMap;
use MageOS\Seo\Model\LlmsJsonl\JsonlBuilder;
use MageOS\Seo\Model\LlmsTxt\LlmsTxtBuilder;
use Psr\Log\LoggerInterface;

/**
 * Builds the pre-generated SEO feeds to storage for every active store view.
 *
 * Shared by the nightly cron (full rebuild) and the queue consumer (single feed
 * group after an invalidation). Whole-catalog builds therefore happen only in
 * background processes, never inside anonymous web requests.
 *
 * Served files are replaced in place (see FeedStorage::write()), so a feed stays
 * available while it is rebuilt; the cached responses of the rebuilt groups are purged
 * once all stores are done. Files of a feed that is disabled for a store are removed,
 * so re-enabling it later triggers a fresh build instead of serving an outdated file.
 */
class FeedRegenerator
{
    public const GROUP_LLMS     = 'llms';
    public const GROUP_JSONL    = 'jsonl';
    public const GROUP_HREFLANG = 'hreflang';

    public const GROUPS = [self::GROUP_LLMS, self::GROUP_JSONL, self::GROUP_HREFLANG];

    private const FILE_LLMS           = 'llms.txt';
    private const FILE_LLMS_FULL      = 'llms-full.txt';
    private const FILE_JSONL          = 'llms.jsonl';
    private const HREFLANG_ALL_FILES  = 'hreflang-sitemap*.xml';
    private const HREFLANG_CHUNKS     = 'hreflang-sitemap-*.xml';

    /**
     * @param StoreManagerInterface $storeManager
     * @param Emulation $emulation
     * @param Config $seoConfig
     * @param LlmsTxtBuilder $llmsTxtBuilder
     * @param JsonlBuilder $jsonlBuilder
     * @param SitemapFileWriter $sitemapFileWriter
     * @param StoreLocaleMap $storeLocaleMap
     * @param FeedStorage $feedStorage
     * @param FeedCache $feedCache
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Emulation             $emulation,
        private readonly Config                $seoConfig,
        private readonly LlmsTxtBuilder        $llmsTxtBuilder,
        private readonly JsonlBuilder          $jsonlBuilder,
        private readonly SitemapFileWriter     $sitemapFileWriter,
        private readonly StoreLocaleMap        $storeLocaleMap,
        private readonly FeedStorage           $feedStorage,
        private readonly FeedCache             $feedCache,
        private readonly LoggerInterface       $logger
    ) {
    }

    /**
     * Regenerate one feed group (or all, when null) for every active store view.
     *
     * A failing store view is logged and skipped so the others are still built; the
     * failures are returned for callers that report them (the CLI command).
     *
     * @param string|null $group One of self::GROUPS, or null for all
     * @return array<int, string> Error message per failed store view ID
     */
    public function regenerate(?string $group = null): array
    {
        $failures = [];
        // Hreflang sitemap file sets already built in this run, keyed by alternate set.
        $builtSets = [];
        foreach ($this->storeManager->getStores() as $store) {
            if (!$store->getIsActive()) {
                continue;
            }
            $storeId = (int) $store->getId();

            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
            try {
                $this->generateForStore($storeId, $group, $builtSets);
            } catch (\Throwable $e) {
                $failures[$storeId] = $e->getMessage();
                $this->logger->error(
                    \sprintf('MageOS_Seo: feed regeneration failed for store %d: %s', $storeId, $e->getMessage()),
                    ['exception' => $e, 'group' => $group]
                );
            } finally {
                // The locale map memoises per store scope; reset between emulations.
                $this->storeLocaleMap->reset();
                $this->emulation->stopEnvironmentEmulation();
            }
        }

        if ($group === null) {
            $this->removeOrphanedStoreDirectories();
        }

        $this->purgeCachedResponses($group === null ? self::GROUPS : [$group]);

        return $failures;
    }

    /**
     * Remove feed directories of store views that no longer exist.
     *
     * Deleting a store view directly removes its directory straight away (see
     * Observer\RemoveFeedFilesOnStoreDelete), but deleting a store group or a website takes its
     * store views with it through a database-level cascade that dispatches no store_delete
     * event. Nothing serves the leftover files — a request resolves feeds for the current store
     * view — so they are swept here on the full rebuild rather than chased through events.
     *
     * Inactive store views keep their files: they are still store views, and re-activating one
     * should not have to wait for a rebuild.
     *
     * @return void
     */
    private function removeOrphanedStoreDirectories(): void
    {
        $existing = [];
        // With the default store view (admin, ID 0): nothing writes its feeds, but a directory
        // of that name is not an orphan either.
        foreach ($this->storeManager->getStores(true) as $store) {
            $existing[(int) $store->getId()] = true;
        }

        foreach ($this->feedStorage->listStoreDirectories() as $storeId) {
            if (!isset($existing[$storeId])) {
                $this->feedStorage->deleteStoreDirectory($storeId);
            }
        }
    }

    /**
     * Generate the requested feeds for the currently emulated store.
     *
     * @param int $storeId
     * @param string|null $group
     * @param array<string,array{store_id:int,chunks:string[]}> $builtSets
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return void
     */
    private function generateForStore(int $storeId, ?string $group, array &$builtSets): void
    {
        if ($group === null || $group === self::GROUP_LLMS) {
            $this->writeOrRemove(
                self::FILE_LLMS,
                $storeId,
                $this->seoConfig->isLlmsTxtEnabled($storeId),
                fn (): string => $this->llmsTxtBuilder->buildConcise()
            );
            $this->writeOrRemove(
                self::FILE_LLMS_FULL,
                $storeId,
                $this->seoConfig->isLlmsFullTxtEnabled($storeId),
                fn (): string => $this->llmsTxtBuilder->buildFull()
            );
        }

        if ($group === null || $group === self::GROUP_JSONL) {
            if ($this->seoConfig->isLlmsJsonlEnabled($storeId)) {
                $this->writeStream(self::FILE_JSONL, $storeId, $this->jsonlBuilder->stream());
            } else {
                $this->feedStorage->deleteForStore(self::FILE_JSONL, $storeId);
            }
        }

        if ($group === null || $group === self::GROUP_HREFLANG) {
            $eligible = $this->seoConfig->isHreflangEnabled($storeId)
                && $this->seoConfig->isHreflangSitemapEnabled()
                && \count($this->storeLocaleMap->getMap()) >= 2;
            if ($eligible) {
                $this->writeHreflangFiles($storeId, $builtSets);
            } else {
                $this->feedStorage->deleteForStore(self::HREFLANG_ALL_FILES, $storeId);
            }
        }
    }

    /**
     * Write a feed from a stream of content, without holding the document in memory.
     *
     * @param string $fileName
     * @param int $storeId
     * @param iterable<string> $content
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return void
     */
    private function writeStream(string $fileName, int $storeId, iterable $content): void
    {
        $writer = $this->feedStorage->openForWrite($storeId);

        try {
            foreach ($content as $part) {
                $writer->write($part);
            }
            $writer->commit($fileName);
        } catch (\Exception $e) {
            $writer->discard();
            throw $e;
        }
    }

    /**
     * Write a single-file feed when it is enabled for the store, otherwise remove it.
     *
     * @param string $fileName
     * @param int $storeId
     * @param bool $enabled
     * @param callable $build Returns the file content
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return void
     */
    private function writeOrRemove(string $fileName, int $storeId, bool $enabled, callable $build): void
    {
        if ($enabled) {
            $this->feedStorage->write($fileName, $storeId, $build());
            return;
        }

        $this->feedStorage->deleteForStore($fileName, $storeId);
    }

    /**
     * Replace the store's hreflang sitemap file set without a gap in availability.
     *
     * The sitemap lists every store view of the alternate set, so store views sharing that set
     * (a website, or the whole install when hreflang is not limited to one website) produce
     * byte-identical chunk files. The first store view of a set generates them; the rest copy
     * those files and only write their own index, which carries their base URL. That turns a
     * build that cost "whole catalogue × store views" into one per set.
     *
     * Chunks land before the index, so the served index never references a chunk that does not
     * exist; chunks the new set no longer contains (the catalogue shrank) go once it is in place.
     *
     * @param int $storeId
     * @param array<string,array{store_id:int,chunks:string[]}> $builtSets
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return void
     */
    private function writeHreflangFiles(int $storeId, array &$builtSets): void
    {
        $baseUrl   = (string) $this->storeManager->getStore()->getBaseUrl();
        $signature = hash('sha256', (string) json_encode($this->storeLocaleMap->getMap()));

        if (isset($builtSets[$signature])) {
            $built  = $builtSets[$signature];
            $chunks = $built['chunks'];
            foreach ($chunks as $fileName) {
                $this->feedStorage->copyBetweenStores($fileName, $built['store_id'], $storeId);
            }
            if ($chunks === []) {
                // One document, identical for every store view of the set.
                $this->feedStorage->copyBetweenStores(SitemapGenerator::INDEX_FILE, $built['store_id'], $storeId);
            } else {
                $this->sitemapFileWriter->writeIndex($storeId, $baseUrl, $chunks);
            }
        } else {
            $chunks = $this->sitemapFileWriter->write($storeId, $baseUrl);
            $builtSets[$signature] = ['store_id' => $storeId, 'chunks' => $chunks];
        }

        $current = array_flip($chunks);
        foreach ($this->feedStorage->listForStore(self::HREFLANG_CHUNKS, $storeId) as $existing) {
            if (!isset($current[$existing])) {
                $this->feedStorage->deleteForStore($existing, $storeId);
            }
        }
    }

    /**
     * Purge the cached responses of the rebuilt groups (best effort).
     *
     * A purge failure is logged: the new files are in place and the cached copies
     * expire on their own.
     *
     * @param string[] $groups
     * @return void
     */
    private function purgeCachedResponses(array $groups): void
    {
        try {
            $this->feedCache->purge($groups);
        } catch (\Throwable $e) {
            $this->logger->error(
                'MageOS_Seo: could not purge cached feed responses: ' . $e->getMessage(),
                ['exception' => $e, 'groups' => $groups]
            );
        }
    }
}
