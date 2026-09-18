<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Hreflang;

use MageOS\Seo\Model\Feed\FeedFileWriter;
use MageOS\Seo\Model\Feed\FeedStorage;
use MageOS\Seo\Model\Hreflang\SitemapFileWriter;
use MageOS\Seo\Model\Hreflang\SitemapGenerator;
use PHPUnit\Framework\TestCase;

class SitemapFileWriterTest extends TestCase
{
    /**
     * Everything written, per opened file, in order.
     *
     * @var array<int, array{content: string, committed: string|null, discarded: bool}>
     */
    private array $files = [];

    /**
     * Files written whole through FeedStorage::write(), as name => content.
     *
     * @var array<string, string>
     */
    private array $written = [];

    protected function setUp(): void
    {
        $this->files   = [];
        $this->written = [];
    }

    public function testACatalogueBelowTheCapBecomesOneUrlsetUnderTheIndexName(): void
    {
        $writer = $this->writer(['<url>a</url>', '<url>b</url>'], 45000);

        $chunks = $writer->write(1, 'https://uk/');

        $this->assertSame([], $chunks);
        $this->assertCount(1, $this->files);
        $this->assertSame(SitemapGenerator::INDEX_FILE, $this->files[0]['committed']);
        $this->assertStringContainsString('<url>a</url>', $this->files[0]['content']);
        $this->assertStringContainsString('<url>b</url>', $this->files[0]['content']);
        $this->assertStringEndsWith("</urlset>\n", $this->files[0]['content']);
        $this->assertSame([], $this->written, 'A single document needs no sitemap index.');
    }

    public function testAnEmptyCatalogueStillReplacesTheServedFile(): void
    {
        $writer = $this->writer([], 45000);

        $this->assertSame([], $writer->write(1, 'https://uk/'));
        $this->assertCount(1, $this->files);
        $this->assertSame(SitemapGenerator::INDEX_FILE, $this->files[0]['committed']);
    }

    public function testABigCatalogueIsSplitIntoChunksWithAnIndexLast(): void
    {
        $writer = $this->writer(['<url>1</url>', '<url>2</url>', '<url>3</url>', '<url>4</url>', '<url>5</url>'], 2);

        $chunks = $writer->write(2, 'https://de/');

        $this->assertSame(
            ['hreflang-sitemap-1.xml', 'hreflang-sitemap-2.xml', 'hreflang-sitemap-3.xml'],
            $chunks
        );
        $this->assertSame(
            ['hreflang-sitemap-1.xml', 'hreflang-sitemap-2.xml', 'hreflang-sitemap-3.xml'],
            array_column($this->files, 'committed')
        );
        // Two URLs per chunk, the remainder in the last one.
        $this->assertSame(2, substr_count($this->files[0]['content'], '<url>'));
        $this->assertSame(1, substr_count($this->files[2]['content'], '<url>'));
        // The index is written after its chunks, and points at this store's base URL.
        $this->assertArrayHasKey(SitemapGenerator::INDEX_FILE, $this->written);
        $this->assertStringContainsString(
            '<loc>https://de/hreflang-sitemap-1.xml</loc>',
            $this->written[SitemapGenerator::INDEX_FILE]
        );
    }

    public function testAFailureMidStreamLeavesTheServedFilesAlone(): void
    {
        $blocks = static function (): \Generator {
            yield '<url>a</url>';
            throw new \RuntimeException('rewrite query failed');
        };
        $writer = $this->writer($blocks(), 45000);

        try {
            $writer->write(1, 'https://uk/');
            $this->fail('The failure should propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('rewrite query failed', $e->getMessage());
        }

        $this->assertTrue($this->files[0]['discarded']);
        $this->assertNull($this->files[0]['committed']);
    }

    public function testWriteIndexRendersTheChunkListForAStore(): void
    {
        $this->writer([], 45000)->writeIndex(3, 'https://fr/', ['hreflang-sitemap-1.xml']);

        $this->assertStringContainsString(
            '<loc>https://fr/hreflang-sitemap-1.xml</loc>',
            $this->written[SitemapGenerator::INDEX_FILE]
        );
    }

    /**
     * Build the writer over a real generator's framing and a storage that records everything.
     *
     * @param iterable<string> $blocks
     * @param int $maxUrlsPerFile
     * @return SitemapFileWriter
     */
    private function writer(iterable $blocks, int $maxUrlsPerFile): SitemapFileWriter
    {
        $generator = $this->createStub(SitemapGenerator::class);
        $generator->method('streamBlocks')->willReturnCallback(
            static function () use ($blocks): \Generator {
                yield from $blocks;
            }
        );
        $generator->method('documentHeader')->willReturn("<urlset>\n");
        $generator->method('documentFooter')->willReturn("</urlset>\n");
        $generator->method('indexDocument')->willReturnCallback(
            static function (string $baseUrl, array $chunkFileNames): string {
                $locs = array_map(
                    static fn (string $name): string => '<loc>' . rtrim($baseUrl, '/') . '/' . $name . '</loc>',
                    $chunkFileNames
                );
                return '<sitemapindex>' . implode('', $locs) . '</sitemapindex>';
            }
        );

        $storage = $this->createStub(FeedStorage::class);
        $storage->method('openForWrite')->willReturnCallback(fn (): FeedFileWriter => $this->recordingFile());
        $storage->method('write')->willReturnCallback(
            function (string $fileName, int $storeId, string $content): void {
                $this->written[$fileName] = $content;
            }
        );

        return new SitemapFileWriter($generator, $storage, $maxUrlsPerFile);
    }

    /**
     * A file writer that records its content and how it ended.
     *
     * @return FeedFileWriter
     */
    private function recordingFile(): FeedFileWriter
    {
        $index               = \count($this->files);
        $this->files[$index] = ['content' => '', 'committed' => null, 'discarded' => false];

        $file = $this->createStub(FeedFileWriter::class);
        $file->method('write')->willReturnCallback(
            function (string $content) use ($index): void {
                $this->files[$index]['content'] .= $content;
            }
        );
        $file->method('commit')->willReturnCallback(
            function (string $fileName) use ($index): void {
                $this->files[$index]['committed'] = $fileName;
            }
        );
        $file->method('discard')->willReturnCallback(
            function () use ($index): void {
                $this->files[$index]['discarded'] = true;
            }
        );

        return $file;
    }
}
