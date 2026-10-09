<?php

declare(strict_types=1);

namespace MageOS\Seo\Test\Unit\Model\Sitemap;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\Filesystem\File\WriteFactory;
use Magento\Sitemap\Model\Sitemap;
use Magento\Sitemap\Model\SitemapConfigReaderInterface;
use MageOS\Seo\Model\Sitemap\FileNames;
use MageOS\Seo\Model\Sitemap\Writer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Writer on real directories: what it publishes, and what a failure or a neighbour leaves.
 *
 * Each test gets directories of its own under the system's temporary directory: one public
 * directory per installation, and a temporary one an installation could share with another.
 */
class WriterTest extends TestCase
{
    private const DIRECTORY = 'media/sitemap';

    /**
     * The test's own root, removed after it.
     *
     * @var string
     */
    private string $root = '';

    /**
     * The driver every directory of the test writes through.
     *
     * @var FailingFileDriver
     */
    private FailingFileDriver $driver;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->root   = sys_get_temp_dir() . '/mageos-seo-writer-' . bin2hex(random_bytes(6));
        $this->driver = new FailingFileDriver();
        mkdir($this->root);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    /**
     * Issue #24: whichever operation fails, the index lists a whole generation, old or new.
     *
     * The second generation is run again and again, failing one operation further each time, until
     * it runs through.
     *
     * @return void
     */
    public function testAFailureAtAnyStepLeavesOneWholeGenerationPublished(): void
    {
        $old = ['pages' => ['old-page'], 'products' => ['old-1', 'old-2']];
        $new = ['pages' => ['new-page'], 'products' => ['new-1', 'new-2']];

        for ($operation = 1; $operation < 200; $operation++) {
            $installation = $this->installation('failure-' . $operation);
            $this->generate($this->writer($installation), $old);

            $this->driver->failAt($operation);
            try {
                $this->generate($this->writer($installation), $new);
                $completed = true;
            } catch (FileSystemException) {
                $completed = false;
            } finally {
                $this->driver->failAt(null);
            }

            $published = $this->published($installation['public']);
            $this->assertSame([], $published['missing'], "Failing operation $operation: a listed file is missing.");
            $this->assertContains(
                $published['rows'],
                [$this->allRows($old), $this->allRows($new)],
                "Failing operation $operation: the index lists a mixed generation."
            );
            if ($completed) {
                $this->assertGreaterThan(1, $operation, 'No operation was failed.');
                $this->assertSame($this->allRows($new), $published['rows']);
                return;
            }
        }

        $this->fail('The second generation never ran through.');
    }

    /**
     * Issue #25: two installations with the same sitemap, sharing a temporary directory, each
     * publish only their own rows, and neither leaves anything in the shared directory.
     *
     * @return void
     */
    public function testInstallationsSharingTemporaryStoragePublishOnlyTheirOwnRows(): void
    {
        $shared = $this->path('shared-tmp');
        $first  = $this->installation('first', $shared);
        $second = $this->installation('second', $shared);

        $writerA = $this->writer($first);
        $writerB = $this->writer($second);

        // A's pages file is complete, then B's, at the same name.
        $writerA->startType('pages');
        $writerA->writeRow($this->row('first-page'));
        $writerA->startType('products');
        $writerB->startType('pages');
        $writerB->writeRow($this->row('second-page'));
        $writerB->startType('products');

        $writerA->writeRow($this->row('first-product'));
        $writerA->finish();
        try {
            $writerB->writeRow($this->row('second-product'));
            $writerB->finish();
        } catch (FileSystemException) {
            // Asserted below.
        }

        $this->assertSame(['first-page', 'first-product'], $this->published($first['public'])['rows']);
        $this->assertSame(['second-page', 'second-product'], $this->published($second['public'])['rows']);
        $this->assertSame([], glob($shared . '/*') ?: [], 'Something was written to the shared directory.');
    }

    /**
     * Decision 1: a type is written into the set its index does not list, and the index keeps its
     * name — the one search engines were given.
     *
     * @return void
     */
    public function testEachTypeAlternatesBetweenTwoSetsUnderOneIndexName(): void
    {
        $installation = $this->installation('alternating');
        $rows         = ['pages' => ['page'], 'products' => ['product']];

        $listed = [];
        for ($generation = 0; $generation < 3; $generation++) {
            $this->generate($this->writer($installation), $rows);
            $listed[] = $this->published($installation['public'])['files'];
        }

        $this->assertSame(['sitemap-1-pages-a-1.xml', 'sitemap-1-products-a-1.xml'], $listed[0]);
        $this->assertSame(['sitemap-1-pages-b-1.xml', 'sitemap-1-products-b-1.xml'], $listed[1]);
        $this->assertSame($listed[0], $listed[2]);
        $this->assertSame(
            ['sitemap.xml'],
            array_values(array_filter(
                $this->filesIn($installation['public']),
                static fn (string $name): bool => !str_contains($name, '-')
            )),
            'There is one index, under its own name.'
        );
    }

    /**
     * Decision 2: the set the previous index listed stays until the next publication; files of the
     * set before it that the new generation no longer has go.
     *
     * @return void
     */
    public function testThePreviousSetStaysUntilTheNextPublication(): void
    {
        $installation = $this->installation('previous');

        $this->generate($this->writer($installation), ['products' => ['one', 'two', 'three']]);
        $this->generate($this->writer($installation), ['products' => ['one']]);

        $this->assertSame(
            ['sitemap-1-products-a-1.xml', 'sitemap-1-products-a-2.xml', 'sitemap-1-products-a-3.xml',
                'sitemap-1-products-b-1.xml', 'sitemap.xml'],
            $this->filesIn($installation['public']),
            'The previous set stays whole.'
        );

        $this->generate($this->writer($installation), ['products' => ['one']]);

        $this->assertSame(
            ['sitemap-1-products-a-1.xml', 'sitemap-1-products-b-1.xml', 'sitemap.xml'],
            $this->filesIn($installation['public']),
            'The set before it keeps only what was written again.'
        );
    }

    /**
     * A rebuild of one type keeps the other types' files and their dates as the index gave them;
     * the rebuilt type's files are dated now.
     *
     * @return void
     */
    public function testARebuildOfOneTypeKeepsTheOtherTypesFilesAndDates(): void
    {
        $installation = $this->installation('partial');
        $this->generate($this->writer($installation), ['pages' => ['page'], 'products' => ['product']]);

        $index = self::DIRECTORY . '/sitemap.xml';
        $installation['public']->writeFile(
            $index,
            (string) preg_replace(
                '#<lastmod>[^<]*</lastmod>#',
                '<lastmod>2020-01-02T03:04:05+00:00</lastmod>',
                $installation['public']->readFile($index)
            )
        );

        $this->generate($this->writer($installation, ['pages']), ['pages' => ['new-page']]);

        $published = $this->published($installation['public']);
        $this->assertSame(['new-page', 'product'], $published['rows']);
        $this->assertSame('2020-01-02T03:04:05+00:00', $published['lastmod']['sitemap-1-products-a-1.xml']);
        $this->assertNotSame('2020-01-02T03:04:05+00:00', $published['lastmod']['sitemap-1-pages-b-1.xml']);
    }

    /**
     * An index of today's names — no sets — has nothing a rebuild of one type can build on: every
     * type is written.
     *
     * @return void
     */
    public function testAnIndexWithoutSetsIsWrittenWhole(): void
    {
        $installation = $this->installation('legacy');
        $public       = $installation['public'];
        $public->writeFile(self::DIRECTORY . '/sitemap-1-products-1.xml', '<urlset>' . $this->row('old') . '</urlset>');
        $public->writeFile(
            self::DIRECTORY . '/sitemap.xml',
            '<sitemapindex><sitemap><loc>https://example.com/media/sitemap/sitemap-1-products-1.xml</loc>'
            . '<lastmod>2020-01-02T03:04:05+00:00</lastmod></sitemap></sitemapindex>'
        );

        $writer = $this->writer($installation, ['pages']);
        $this->assertTrue($writer->writes('products'), 'The products are written too.');
        $this->generate($writer, ['pages' => ['page'], 'products' => ['product']]);

        $this->assertSame(
            ['sitemap-1-pages-a-1.xml', 'sitemap-1-products-a-1.xml'],
            $this->published($public)['files']
        );
    }

    /**
     * After publishing, this sitemap's files that neither index lists go — today's names, core's,
     * stray temporary files — and nothing else does.
     *
     * @return void
     */
    public function testOnlyThisSitemapsUnlistedFilesAreRemoved(): void
    {
        $installation = $this->installation('sweep');
        $public       = $installation['public'];
        $leftovers    = [
            'sitemap-1-products-9.xml',
            'sitemap-1-7.xml',
            'sitemap-1-pages-b-4.xml',
            'sitemap-1-pages-a-1.xml.tmp',
            'sitemap.xml.tmp',
        ];
        $strangers = [
            'sitemapx-1-1.xml',
            'sitemap-101-pages-a-1.xml',
            'sitemap-1-blog-a-1.xml',
            'unrelated.xml',
        ];
        foreach (array_merge($leftovers, $strangers) as $name) {
            $public->writeFile(self::DIRECTORY . '/' . $name, '');
        }

        $this->generate($this->writer($installation), ['pages' => ['page'], 'products' => ['product']]);

        $files = $this->filesIn($public);
        foreach ($leftovers as $name) {
            $this->assertNotContains($name, $files, $name . ' was left behind.');
        }
        foreach ($strangers as $name) {
            $this->assertContains($name, $files, $name . ' was removed.');
        }
    }

    /**
     * Every file is whole XML — each child a `<urlset>`, the index a `<sitemapindex>`, each closed —
     * and finish() returns what the index lists, in its order.
     *
     * @return void
     */
    public function testEveryFileIsWholeAndFinishReturnsWhatTheIndexLists(): void
    {
        $installation = $this->installation('whole');
        $writer       = $this->writer($installation);
        $writer->startType('pages');
        $writer->writeRow($this->row('page'));
        $writer->startType('products');
        $writer->writeRow($this->row('product'));

        $this->assertSame(['sitemap-1-pages-a-1.xml', 'sitemap-1-products-a-1.xml'], $writer->finish());
        $this->assertSame(0, $this->driver->openFiles(), 'Every file is closed: remote storage writes on close.');

        $public = $installation['public'];
        $this->assertSame(
            '<urlset>' . $this->row('product') . PHP_EOL . '</urlset>',
            $public->readFile(self::DIRECTORY . '/sitemap-1-products-a-1.xml')
        );
        $index = $public->readFile(self::DIRECTORY . '/sitemap.xml');
        $this->assertStringStartsWith(
            '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL
            . '<sitemap><loc>https://example.com/media/sitemap/sitemap-1-pages-a-1.xml</loc><lastmod>',
            $index
        );
        $this->assertMatchesRegularExpression(
            '#</lastmod></sitemap>' . PHP_EOL . '</sitemapindex>$#',
            $index
        );
        $this->assertMatchesRegularExpression(
            '#<lastmod>\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d</lastmod>#',
            $index,
            'A file written now is dated, as an ISO 8601 time.'
        );
    }

    /**
     * A file ends at the configured number of URLs, and before the row that would take it past the
     * configured size — the file measured whole, newlines and closing tag included.
     *
     * @return void
     */
    public function testAFileEndsAtTheConfiguredLinesOrSize(): void
    {
        $rows      = ['aa', 'bb', 'cc'];
        $rowLength = \strlen($this->row('aa') . PHP_EOL);
        $wrapper   = \strlen('<urlset></urlset>');
        $cases     = [
            'two URLs a file'        => [2, 10485760, [['aa', 'bb'], ['cc']]],
            'two rows fit exactly'   => [50000, $wrapper + 2 * $rowLength, [['aa', 'bb'], ['cc']]],
            'a byte short of two'    => [50000, $wrapper + 2 * $rowLength - 1, [['aa'], ['bb'], ['cc']]],
        ];

        $case = 0;
        foreach ($cases as $label => [$lines, $size, $expected]) {
            $installation = $this->installation('limits-' . (++$case));
            $this->generate($this->writer($installation, maxLines: $lines, maxSize: $size), ['products' => $rows]);

            $files = [];
            foreach ($this->published($installation['public'])['files'] as $file) {
                preg_match_all(
                    '#<loc>https://example\.com/([^<]+)</loc>#',
                    $installation['public']->readFile(self::DIRECTORY . '/' . $file),
                    $matches
                );
                $files[] = $matches[1];
            }
            $this->assertSame($expected, $files, $label);
        }
    }

    /**
     * Giving up before anything was written leaves everything as it was.
     *
     * @return void
     */
    public function testAbandoningWithNothingOpenChangesNothing(): void
    {
        $installation = $this->installation('nothing-open');
        $this->generate($this->writer($installation), ['pages' => ['page']]);
        $before = $this->filesIn($installation['public']);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        $this->writer($installation, logger: $logger)->abandon();

        $this->assertSame($before, $this->filesIn($installation['public']));
    }

    /**
     * A leftover that cannot be deleted is logged and left for the next publication; the
     * generation is published all the same.
     *
     * @return void
     */
    public function testALeftoverThatCannotBeDeletedIsLoggedAndThePublicationStands(): void
    {
        $installation = $this->installation('stuck-leftover');
        $installation['public']->writeFile(self::DIRECTORY . '/sitemap-1-products-9.xml', '');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('sitemap-1-products-9.xml'));

        $this->driver->failMatching('#^delete .*sitemap-1-products-9\.xml$#');
        try {
            $this->generate($this->writer($installation, logger: $logger), ['products' => ['product']]);
        } finally {
            $this->driver->failMatching(null);
        }

        $this->assertSame(['product'], $this->published($installation['public'])['rows']);
        $this->assertContains('sitemap-1-products-9.xml', $this->filesIn($installation['public']));
    }

    /**
     * A failure while giving up is logged, not thrown over the failure being handled.
     *
     * @return void
     */
    public function testAFailureWhileAbandoningIsLogged(): void
    {
        $installation = $this->installation('stuck-abandon');
        $logger       = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with($this->stringContains('sitemap-1-pages-a-1.xml.tmp'));
        $writer = $this->writer($installation, logger: $logger);
        $writer->startType('pages');
        $writer->writeRow($this->row('page'));

        $this->driver->failMatching('#^delete #');
        try {
            $writer->abandon();
        } finally {
            $this->driver->failMatching(null);
        }
    }

    /**
     * A generation given up removes the file it was writing and leaves the published one alone.
     *
     * @return void
     */
    public function testAnAbandonedGenerationRemovesItsUnfinishedFile(): void
    {
        $installation = $this->installation('abandoned');
        $this->generate($this->writer($installation), ['pages' => ['old-page']]);

        $writer = $this->writer($installation);
        $writer->startType('pages');
        $writer->writeRow($this->row('new-page'));
        $this->assertContains('sitemap-1-pages-b-1.xml.tmp', $this->filesIn($installation['public']));

        $writer->abandon();

        $this->assertSame(
            ['sitemap-1-pages-a-1.xml', 'sitemap.xml'],
            $this->filesIn($installation['public'])
        );
        $this->assertSame(['old-page'], $this->published($installation['public'])['rows']);
    }

    /**
     * An installation: its own public directory, and a temporary directory of its own or shared.
     *
     * @param string $name
     * @param string|null $sharedTemporary
     * @return array{public: WriteInterface, temporary: WriteInterface}
     */
    private function installation(string $name, ?string $sharedTemporary = null): array
    {
        return [
            'public'    => $this->directory($this->path($name . '-pub')),
            'temporary' => $this->directory($sharedTemporary ?? $this->path($name . '-tmp')),
        ];
    }

    /**
     * A writer for `sitemap.xml` in /media/sitemap/ of store view 1, of the installation, writing
     * one URL per file unless given other limits.
     *
     * Asked for the temporary directory, the filesystem gives the installation's — shared with
     * another, where a test shares it — so a writer that stages there is caught.
     *
     * @param array{public: WriteInterface, temporary: WriteInterface} $installation
     * @param string[]|null $onlyTypes
     * @param LoggerInterface|null $logger
     * @param int $maxLines URLs a file may hold
     * @param int $maxSize Bytes a file may have
     * @return Writer
     */
    private function writer(
        array $installation,
        ?array $onlyTypes = null,
        ?LoggerInterface $logger = null,
        int $maxLines = 1,
        int $maxSize = 10485760
    ): Writer {
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturnCallback(
            static fn (string $code): WriteInterface => $code === DirectoryList::PUB
                ? $installation['public']
                : $installation['temporary']
        );

        $configReader = $this->createStub(SitemapConfigReaderInterface::class);
        $configReader->method('getMaximumLinesNumber')->willReturn($maxLines);
        $configReader->method('getMaximumFileSize')->willReturn($maxSize);

        $escaper = $this->createStub(Escaper::class);
        $escaper->method('escapeUrl')->willReturnArgument(0);

        $sitemap = $this->createStub(Sitemap::class);
        $sitemap->method('__call')->willReturnCallback(
            static fn (string $method) => match ($method) {
                'getSitemapPath'     => '/' . self::DIRECTORY . '/',
                'getSitemapFilename' => 'sitemap.xml',
                'getStoreId'         => 1,
                default              => null,
            }
        );
        $sitemap->method('getSitemapUrl')->willReturnCallback(
            static fn (string $path, string $file): string => 'https://example.com' . $path . $file
        );

        return new Writer(
            filesystem: $filesystem,
            configReader: $configReader,
            escaper: $escaper,
            fileNames: new FileNames(),
            logger: $logger ?? $this->createStub(LoggerInterface::class),
            sitemap: $sitemap,
            urlsetOpen: '<urlset>',
            types: ['pages', 'products'],
            onlyTypes: $onlyTypes
        );
    }

    /**
     * Write a generation: each type's rows, then finish.
     *
     * @param Writer $writer
     * @param array<string,string[]> $rowsByType
     * @return void
     */
    private function generate(Writer $writer, array $rowsByType): void
    {
        foreach ($rowsByType as $type => $rows) {
            if (!$writer->writes($type)) {
                continue;
            }
            $writer->startType($type);
            foreach ($rows as $row) {
                $writer->writeRow($this->row($row));
            }
        }
        $writer->finish();
    }

    /**
     * What the published index lists: its files in order, their `<lastmod>`, the files it lists that
     * are not there, and the rows of those that are, in order.
     *
     * @param WriteInterface $public
     * @return array{files: string[], lastmod: array<string,string>, missing: string[], rows: string[]}
     */
    private function published(WriteInterface $public): array
    {
        $index = $public->readFile(self::DIRECTORY . '/sitemap.xml');
        preg_match_all('#<loc>[^<]*/([^/<]+)</loc><lastmod>([^<]*)</lastmod>#', $index, $matches);

        $missing = [];
        $rows    = [];
        foreach ($matches[1] as $file) {
            $path = self::DIRECTORY . '/' . $file;
            if (!$public->isExist($path)) {
                $missing[] = $file;
                continue;
            }
            preg_match_all('#<url><loc>https://example\.com/([^<]+)</loc></url>#', $public->readFile($path), $urls);
            array_push($rows, ...$urls[1]);
        }

        return [
            'files'   => $matches[1],
            'lastmod' => array_combine($matches[1], $matches[2]),
            'missing' => $missing,
            'rows'    => $rows,
        ];
    }

    /**
     * The names of the files in the installation's sitemap directory, sorted.
     *
     * @param WriteInterface $public
     * @return string[]
     */
    private function filesIn(WriteInterface $public): array
    {
        $names = array_map('basename', $public->read(self::DIRECTORY));
        sort($names);

        return $names;
    }

    /**
     * Every row of a generation, in writing order.
     *
     * @param array<string,string[]> $rowsByType
     * @return string[]
     */
    private function allRows(array $rowsByType): array
    {
        return array_merge([], ...array_values($rowsByType));
    }

    /**
     * @param string $name
     * @return string
     */
    private function row(string $name): string
    {
        return '<url><loc>https://example.com/' . $name . '</loc></url>';
    }

    /**
     * A directory of the test's root, created.
     *
     * @param string $name
     * @return string
     */
    private function path(string $name): string
    {
        $path = $this->root . '/' . $name;
        if (!is_dir($path)) {
            mkdir($path);
        }

        return $path;
    }

    /**
     * A writable directory over the test's driver.
     *
     * @param string $path
     * @return WriteInterface
     */
    private function directory(string $path): WriteInterface
    {
        return new Write(new WriteFactory(new DriverPool()), $this->driver, $path);
    }

    /**
     * Remove a directory and everything in it.
     *
     * @param string $path
     * @return void
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
