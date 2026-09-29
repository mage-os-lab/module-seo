<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Escaper;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\File\WriteInterface as FileWriteInterface;
use Magento\Sitemap\Model\Sitemap;
use Magento\Sitemap\Model\SitemapConfigReaderInterface;
use MageOS\Seo\Model\Sitemap\Writer;
use PHPUnit\Framework\TestCase;

class WriterTest extends TestCase
{
    private const DIRECTORY = '/media/sitemap/';

    /**
     * What was written to each file opened in the temporary directory.
     *
     * @var array<string,string> path => contents
     */
    private array $written = [];

    /**
     * A file written now and a file kept from an earlier generation are both listed with their own
     * file time — never with the time the index is written, which a later rebuild that keeps the
     * file would replace with its file time, moving its date back.
     *
     * @return void
     */
    public function testEveryFileIsListedWithItsOwnFileTime(): void
    {
        $pagesTime    = (int) strtotime('2026-01-02 03:04:05 UTC');
        $productsTime = (int) strtotime('2026-01-01 00:00:00 UTC');
        $writer       = $this->writer(
            ['sitemap-1-pages-1.xml' => $pagesTime, 'sitemap-1-products-1.xml' => $productsTime],
            ['pages']
        );

        $writer->startType('pages');
        $writer->writeRow('<url><loc>https://example.com/a.html</loc></url>');
        $writer->finish();

        $index = $this->written[self::DIRECTORY . 'sitemap.xml'] ?? '';
        $this->assertStringContainsString(
            'sitemap-1-pages-1.xml</loc><lastmod>' . date('c', $pagesTime) . '</lastmod>',
            $index,
            'The file written now has its file time.'
        );
        $this->assertStringContainsString(
            'sitemap-1-products-1.xml</loc><lastmod>' . date('c', $productsTime) . '</lastmod>',
            $index,
            'The kept file has its file time.'
        );
    }

    /**
     * A writer over a sitemap `sitemap.xml` for store view 1, whose index of this generator's already
     * lists a products file, and whose files in place have the given times.
     *
     * @param array<string,int> $fileTimes file name => mtime
     * @param string[]|null $onlyTypes
     * @return Writer
     */
    private function writer(array $fileTimes, ?array $onlyTypes): Writer
    {
        $temporary = $this->createStub(WriteInterface::class);
        $temporary->method('openFile')->willReturnCallback(function (string $path): FileWriteInterface {
            $this->written[$path] = '';
            $file = $this->createStub(FileWriteInterface::class);
            $file->method('write')->willReturnCallback(function (string $data) use ($path): int {
                $this->written[$path] .= $data;
                return \strlen($data);
            });

            return $file;
        });
        $temporary->method('renameFile')->willReturn(true);

        $public = $this->createStub(WriteInterface::class);
        $public->method('isExist')->willReturn(true);
        $public->method('readFile')->willReturn(
            '<sitemapindex><sitemap><loc>https://example.com/media/sitemap/sitemap-1-products-1.xml</loc>'
            . '</sitemap></sitemapindex>'
        );
        $public->method('read')->willReturn(
            array_map(static fn (string $name): string => 'media/sitemap/' . $name, array_keys($fileTimes))
        );
        $public->method('stat')->willReturnCallback(
            static fn (string $path): array => ['mtime' => $fileTimes[basename($path)] ?? 0]
        );

        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturnCallback(
            static fn (string $code): WriteInterface => $code === DirectoryList::PUB ? $public : $temporary
        );

        $configReader = $this->createStub(SitemapConfigReaderInterface::class);
        $configReader->method('getMaximumLinesNumber')->willReturn(50000);
        $configReader->method('getMaximumFileSize')->willReturn(10485760);

        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeUrl')->willReturnArgument(0);

        $sitemap = $this->createStub(Sitemap::class);
        $sitemap->method('__call')->willReturnCallback(
            static fn (string $method) => match ($method) {
                'getSitemapPath'     => self::DIRECTORY,
                'getSitemapFilename' => 'sitemap.xml',
                'getStoreId'         => 1,
                default              => null,
            }
        );
        $sitemap->method('getSitemapUrl')->willReturnCallback(
            static fn (string $path, string $file): string => 'https://example.com' . $path . $file
        );

        return new Writer(
            $filesystem,
            $configReader,
            $escaper,
            $sitemap,
            '<urlset>',
            ['pages', 'products'],
            $onlyTypes
        );
    }
}
