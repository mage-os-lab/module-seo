<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\File\WriteInterface as FileWriteInterface;
use Magento\Sitemap\Model\Sitemap;
use Magento\Sitemap\Model\SitemapConfigReaderInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes one sitemap: a file set per type, and the index that lists them.
 *
 * Created for each generation (see Generator). Rows of a type are written one after another into
 * numbered files, a new file started whenever the next row would pass core's configured line or
 * size limit — measured on the row as written, newline included, so a file never passes the limit.
 *
 * **Publication.** Each type has two sets of files (FileNames): a generation writes the set the
 * published index does not list, so the files search engines can reach are never touched. Every
 * file — the index too — is written under a temporary name in the sitemap's own directory and
 * renamed over its name once complete, so no file is ever read half-written. Renaming the index is
 * the moment the generation is published: before it, the index lists the previous generation,
 * which is whole. A rename within one directory stays on one filesystem, so it is atomic on local
 * disk and a single object replacement on remote storage.
 *
 * Writing in the sitemap's directory, not a temporary directory, also keeps installations apart:
 * one that shares its temporary storage with another never stages files at the other's names.
 *
 * **Clean-up.** Once the index is published, this sitemap's files that neither the new index nor
 * the previous one lists are deleted: a set's files beyond what was written again, this
 * generator's names from before the sets, core's own `{name}-{store}-{n}.xml`, stray temporary
 * files. The previous index's files stay until the next publication, so a crawler that read the
 * old index a moment before still finds what it lists. Only names built from this sitemap's name,
 * store and the registered types are candidates, so another sitemap in the directory is never
 * touched.
 *
 * Given `$onlyTypes`, it rewrites those types and keeps the rest: the index lists the other types'
 * files as the published index did, with the same `<lastmod>`. That needs an index with sets to
 * build on — without one (never generated, written before the sets, or by core's generator) every
 * type is written.
 *
 * Files written now are dated with the time of publication; a kept file keeps its date, so its
 * date changes only when the file does.
 */
class Writer
{
    private const URLSET_CLOSE = '</urlset>';

    /**
     * @var WriteInterface
     */
    private WriteInterface $directory;

    /**
     * @var FileWriteInterface|null
     */
    private ?FileWriteInterface $stream = null;

    /**
     * The temporary file being written, until it is renamed into place.
     *
     * @var string|null
     */
    private ?string $temporaryPath = null;

    /**
     * @var string
     */
    private string $type = '';

    /**
     * The set the current type is written into.
     *
     * @var string
     */
    private string $set = '';

    /**
     * @var int
     */
    private int $fileNumber = 0;

    /**
     * @var int
     */
    private int $lineCount = 0;

    /**
     * @var int
     */
    private int $fileSize = 0;

    /**
     * Child file names written so far, in order, with their types.
     *
     * @var array<string,string> file name => type
     */
    private array $children = [];

    /**
     * The files the published index lists, in its order, with their `<lastmod>`.
     *
     * @var array<string,string> file name => lastmod
     */
    private array $published;

    /**
     * The types being rewritten; null for all of them.
     *
     * @var string[]|null
     */
    private ?array $onlyTypes;

    /**
     * @param Filesystem $filesystem
     * @param SitemapConfigReaderInterface $configReader
     * @param Escaper $escaper
     * @param FileNames $fileNames
     * @param LoggerInterface $logger
     * @param Sitemap $sitemap The core sitemap being generated: its name, path and store
     * @param string $urlsetOpen The opening of every child file, up to and including `<urlset …>`
     * @param string[] $types Every type a child file can have, in the order the index lists them
     * @param string[]|null $onlyTypes Rewrite these types and keep the others' files; null for all
     */
    public function __construct(
        Filesystem                                    $filesystem,
        private readonly SitemapConfigReaderInterface $configReader,
        private readonly Escaper                      $escaper,
        private readonly FileNames                    $fileNames,
        private readonly LoggerInterface              $logger,
        private readonly Sitemap                      $sitemap,
        private readonly string                       $urlsetOpen,
        private readonly array                        $types,
        ?array                                        $onlyTypes = null
    ) {
        $this->directory = $filesystem->getDirectoryWrite(DirectoryList::PUB);
        $this->published = $this->publishedEntries();
        $this->onlyTypes = $onlyTypes !== null && $this->hasSets() ? $onlyTypes : null;
    }

    /**
     * Whether rows of the type are written in this generation.
     *
     * @param string $type
     * @return bool
     */
    public function writes(string $type): bool
    {
        return $this->onlyTypes === null || \in_array($type, $this->onlyTypes, true);
    }

    /**
     * Start writing the rows of a type, into the set the published index does not list.
     *
     * @param string $type
     * @return void
     */
    public function startType(string $type): void
    {
        $this->closeFile();
        $this->type       = $type;
        $this->set        = $this->unlistedSet($type);
        $this->fileNumber = 0;
    }

    /**
     * Write one `<url>` row of the current type.
     *
     * @param string $row
     * @return void
     */
    public function writeRow(string $row): void
    {
        $line = $row . PHP_EOL;

        if ($this->stream !== null && $this->isSplitRequired($line)) {
            $this->closeFile();
        }
        if ($this->stream === null) {
            $this->openFile();
        }

        /** @var FileWriteInterface $stream */
        $stream = $this->stream;
        $stream->write($line);
        $this->lineCount++;
        $this->fileSize += \strlen($line);
    }

    /**
     * Publish the generation: complete the last file, switch the index to it, and clean up.
     *
     * @return string[] The child files, in the order the index lists them
     */
    public function finish(): array
    {
        $this->closeFile();

        $entries = $this->indexEntries(date('c'));
        $this->writeIndex($entries);
        $this->removeUnlisted($entries);

        return array_keys($entries);
    }

    /**
     * Give up a generation that failed: close the file being written and delete it.
     *
     * The published index is untouched; it still lists the previous generation. Called while the
     * failure is being handled, so a failure here is logged rather than thrown over it.
     *
     * @return void
     */
    public function abandon(): void
    {
        $stream       = $this->stream;
        $this->stream = null;
        try {
            $stream?->close();
            if ($this->temporaryPath !== null) {
                $this->directory->delete($this->temporaryPath);
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'MageOS_Seo: could not remove the unfinished sitemap file ' . $this->temporaryPath . ': '
                . $e->getMessage(),
                ['exception' => $e]
            );
        }
        $this->temporaryPath = null;
    }

    /**
     * Whether the line would take the current file past core's configured line or size limit.
     *
     * @param string $line
     * @return bool
     */
    private function isSplitRequired(string $line): bool
    {
        $storeId = (int) $this->sitemap->getStoreId();

        return $this->lineCount + 1 > (int) $this->configReader->getMaximumLinesNumber($storeId)
            || $this->fileSize + \strlen($line) > (int) $this->configReader->getMaximumFileSize($storeId);
    }

    /**
     * Open the next file of the current type, under its temporary name.
     *
     * @return void
     */
    private function openFile(): void
    {
        $this->fileNumber++;
        $this->stream = $this->openTemporary($this->childName());
        $this->stream->write($this->urlsetOpen);
        $this->lineCount = 0;
        $this->fileSize  = \strlen($this->urlsetOpen . self::URLSET_CLOSE);
    }

    /**
     * Complete the file being written, if any, and rename it into place.
     *
     * Its name is in the set the published index does not list, so nothing reachable changes yet.
     *
     * @return void
     */
    private function closeFile(): void
    {
        if ($this->stream === null) {
            return;
        }

        $this->stream->write(self::URLSET_CLOSE);
        $this->stream->close();
        $this->stream = null;

        $name = $this->childName();
        $this->moveIntoPlace($name);
        $this->children[$name] = $this->type;
    }

    /**
     * Write the index listing the given files, and rename it into place: the publication.
     *
     * @param array<string,string> $entries file name => lastmod
     * @return void
     */
    private function writeIndex(array $entries): void
    {
        $index  = $this->fileNames->index($this->sitemap);
        $stream = $this->openTemporary($index);
        $this->stream = $stream;
        $stream->write(
            '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL
        );

        foreach ($entries as $child => $lastMod) {
            $url = $this->sitemap->getSitemapUrl((string) $this->sitemap->getSitemapPath(), $child);
            $stream->write(
                '<sitemap><loc>' . $this->escaper->escapeUrl($url) . '</loc>'
                . '<lastmod>' . $lastMod . '</lastmod></sitemap>' . PHP_EOL
            );
        }

        $stream->write('</sitemapindex>');
        $stream->close();
        $this->stream = null;
        $this->moveIntoPlace($index);
    }

    /**
     * Open a file of the sitemap's directory under its temporary name.
     *
     * @param string $fileName
     * @return FileWriteInterface
     */
    private function openTemporary(string $fileName): FileWriteInterface
    {
        $this->temporaryPath = $this->fileNames->path($this->sitemap, $this->fileNames->temporary($fileName));

        return $this->directory->openFile($this->temporaryPath);
    }

    /**
     * Rename a completed temporary file over its name.
     *
     * @param string $fileName
     * @return void
     */
    private function moveIntoPlace(string $fileName): void
    {
        $this->directory->renameFile(
            $this->fileNames->path($this->sitemap, $this->fileNames->temporary($fileName)),
            $this->fileNames->path($this->sitemap, $fileName)
        );
        $this->temporaryPath = null;
    }

    /**
     * The files the new index lists, in type order, each with its `<lastmod>`.
     *
     * A type written now lists its files with the publication time; a kept type lists its files as
     * the published index did, with their dates.
     *
     * @param string $now
     * @return array<string,string> file name => lastmod
     */
    private function indexEntries(string $now): array
    {
        $entries = [];
        foreach ($this->types as $type) {
            if ($this->writes($type)) {
                foreach (array_keys($this->children, $type, true) as $file) {
                    $entries[$file] = $now;
                }
                continue;
            }
            foreach ($this->published as $file => $lastMod) {
                if ($this->fileNames->setOf($this->sitemap, $type, $file) !== null) {
                    $entries[$file] = $lastMod !== '' ? $lastMod : $now;
                }
            }
        }

        return $entries;
    }

    /**
     * Delete this sitemap's files that neither the new index nor the previous one lists.
     *
     * The generation is published by now, so a file that cannot be deleted is logged and left for
     * the next publication rather than failing this one.
     *
     * @param array<string,string> $entries The new index's files
     * @return void
     */
    private function removeUnlisted(array $entries): void
    {
        $listed = $entries + $this->published;
        foreach ($this->directoryFiles() as $name) {
            if (isset($listed[$name]) || !$this->fileNames->isOwn($this->sitemap, $this->types, $name)) {
                continue;
            }

            try {
                $this->directory->delete($this->fileNames->path($this->sitemap, $name));
            } catch (FileSystemException $e) {
                $this->logger->warning(
                    'MageOS_Seo: could not remove the sitemap file ' . $name . ' no index lists: '
                    . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        }
    }

    /**
     * The files the published index lists, in its order, with their `<lastmod>`.
     *
     * Empty when there is no index — never generated — or the file is core's single `<urlset>`.
     *
     * @return array<string,string> file name => lastmod
     */
    private function publishedEntries(): array
    {
        $path = $this->fileNames->path($this->sitemap, $this->fileNames->index($this->sitemap));
        if (!$this->directory->isFile($path)) {
            return [];
        }

        // The file name is the last segment of each listed URL.
        preg_match_all(
            '#<sitemap>\s*<loc>[^<]*/([^/<]+)</loc>(?:\s*<lastmod>([^<]*)</lastmod>)?#',
            $this->directory->readFile($path),
            $matches,
            PREG_SET_ORDER
        );

        $entries = [];
        foreach ($matches as $match) {
            $entries[$match[1]] = $match[2] ?? '';
        }

        return $entries;
    }

    /**
     * Whether the published index lists files of the sets, so other types' files can be kept.
     *
     * An index from before the sets, or core's, lists files a rebuild of one type cannot build on.
     *
     * @return bool
     */
    private function hasSets(): bool
    {
        foreach (array_keys($this->published) as $file) {
            foreach ($this->types as $type) {
                if ($this->fileNames->setOf($this->sitemap, $type, $file) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The set of the type the published index does not list: the second when it lists the first.
     *
     * @param string $type
     * @return string
     */
    private function unlistedSet(string $type): string
    {
        foreach (array_keys($this->published) as $file) {
            if ($this->fileNames->setOf($this->sitemap, $type, $file) === FileNames::SETS[0]) {
                return FileNames::SETS[1];
            }
        }

        return FileNames::SETS[0];
    }

    /**
     * The names of the files in the sitemap's directory.
     *
     * @return string[]
     */
    private function directoryFiles(): array
    {
        $directory = $this->fileNames->directory($this->sitemap);
        if (!$this->directory->isExist($directory)) {
            return [];
        }

        return array_map(
            fn (string $path): string => $this->fileNames->nameOf($path),
            $this->directory->read($directory)
        );
    }

    /**
     * The name of the current file.
     *
     * @return string
     */
    private function childName(): string
    {
        return $this->fileNames->child($this->sitemap, $this->type, $this->set, $this->fileNumber);
    }
}
