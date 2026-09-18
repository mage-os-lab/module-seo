<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Hreflang;

use MageOS\Seo\Model\Feed\FeedFileWriter;
use MageOS\Seo\Model\Feed\FeedStorage;

/**
 * Writes one store view's hreflang sitemap file set from the generator's block stream.
 *
 * A catalogue below the protocol's 50k-URL cap produces a single urlset served at
 * hreflang-sitemap.xml; above it, numbered chunk files plus a sitemap index at that name. Which
 * of the two it is only becomes clear when the stream ends, so each file is written to a
 * temporary file and named when it is committed — the document is never held in memory.
 */
class SitemapFileWriter
{
    /**
     * @param SitemapGenerator $sitemapGenerator
     * @param FeedStorage $feedStorage
     * @param int $maxUrlsPerFile URLs per chunk file; the protocol caps a file at 50,000
     */
    public function __construct(
        private readonly SitemapGenerator $sitemapGenerator,
        private readonly FeedStorage      $feedStorage,
        private readonly int              $maxUrlsPerFile = SitemapGenerator::MAX_URLS_PER_FILE
    ) {
    }

    /**
     * Write the store's sitemap files and return the chunk file names.
     *
     * Chunks are written before the index, so the served index never references a chunk that
     * does not exist yet.
     *
     * @param int $storeId
     * @param string $baseUrl
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return string[] Chunk file names, empty when the whole sitemap fits in one document
     */
    public function write(int $storeId, string $baseUrl): array
    {
        $chunkFileNames = [];
        $writer         = null;
        $blocksInChunk  = 0;

        try {
            foreach ($this->sitemapGenerator->streamBlocks() as $block) {
                if ($writer === null) {
                    $writer = $this->feedStorage->openForWrite($storeId);
                    $writer->write($this->sitemapGenerator->documentHeader());
                    $blocksInChunk = 0;
                }

                $writer->write($block . "\n");
                $blocksInChunk++;

                if ($blocksInChunk === $this->maxUrlsPerFile) {
                    $chunkFileNames[] = $this->closeChunk($writer, \count($chunkFileNames) + 1);
                    $writer = null;
                }
            }

            if ($writer !== null && $chunkFileNames !== []) {
                // A further chunk followed the first: this is the last one.
                $chunkFileNames[] = $this->closeChunk($writer, \count($chunkFileNames) + 1);
                $writer = null;
            }

            if ($chunkFileNames !== []) {
                $this->writeIndex($storeId, $baseUrl, $chunkFileNames);
                return $chunkFileNames;
            }

            // One document (possibly empty): it is served under the index file name.
            $writer ??= $this->openEmptyDocument($storeId);
            $writer->write($this->sitemapGenerator->documentFooter());
            $writer->commit(SitemapGenerator::INDEX_FILE);

            return [];
        } catch (\Exception $e) {
            $writer?->discard();
            throw $e;
        }
    }

    /**
     * Write the sitemap index of one store view, pointing at its chunk files.
     *
     * @param int $storeId
     * @param string $baseUrl
     * @param string[] $chunkFileNames
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return void
     */
    public function writeIndex(int $storeId, string $baseUrl, array $chunkFileNames): void
    {
        $this->feedStorage->write(
            SitemapGenerator::INDEX_FILE,
            $storeId,
            $this->sitemapGenerator->indexDocument($baseUrl, $chunkFileNames)
        );
    }

    /**
     * Finish a chunk file and return the name it was committed under.
     *
     * @param FeedFileWriter $writer
     * @param int $number
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return string
     */
    private function closeChunk(FeedFileWriter $writer, int $number): string
    {
        $fileName = \sprintf(SitemapGenerator::CHUNK_FORMAT, $number);
        $writer->write($this->sitemapGenerator->documentFooter());
        $writer->commit($fileName);

        return $fileName;
    }

    /**
     * A started document for a catalogue that produced no blocks at all.
     *
     * @param int $storeId
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return FeedFileWriter
     */
    private function openEmptyDocument(int $storeId): FeedFileWriter
    {
        $writer = $this->feedStorage->openForWrite($storeId);
        $writer->write($this->sitemapGenerator->documentHeader());

        return $writer;
    }
}
