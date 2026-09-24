<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Escaper;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\File\WriteInterface as FileWriteInterface;
use Magento\Sitemap\Model\Sitemap;
use Magento\Sitemap\Model\SitemapConfigReaderInterface;

/**
 * Writes one sitemap: a file set per type, and the index that lists them.
 *
 * Created for each generation (see Generator). Rows of a type are written one after another into
 * `{name}-{store}-{type}-{n}.xml`, a new file started whenever the next row would pass core's
 * configured line or size limit — measured on the row as written, newline included, so a file
 * never passes the limit. Files are written to the temporary directory and moved into place when
 * complete, the children before the index, so the index never lists a file that is not there.
 * `{name}.xml` is always the index.
 *
 * Once the index is in place, files left over from an earlier generation of this sitemap — a type
 * that has fewer files now, or core's own `{name}-{store}-{n}.xml` from before this generator was
 * used — are deleted. Only names built from this sitemap's name, store and the registered types are
 * candidates, so another sitemap sharing the directory is never touched.
 *
 * Given `$onlyTypes`, it rewrites those types and keeps the rest: the index lists the other types'
 * files already on disk, and leftovers are removed for the rewritten types only. That needs an index
 * of this generator's to build on — without one (never generated, or last written by core's
 * generator) every type is written.
 *
 * Every file the index lists has its own file time as `<lastmod>`, written now or kept, so a type
 * that was not rebuilt keeps its date.
 */
class Writer
{
    private const URLSET_CLOSE = '</urlset>';

    /**
     * @var WriteInterface
     */
    private WriteInterface $publicDirectory;

    /**
     * @var WriteInterface
     */
    private WriteInterface $temporaryDirectory;

    /**
     * @var FileWriteInterface|null
     */
    private ?FileWriteInterface $stream = null;

    /**
     * @var string
     */
    private string $type = '';

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
     * The types being rewritten; null for all of them.
     *
     * @var string[]|null
     */
    private ?array $onlyTypes;

    /**
     * @param Filesystem $filesystem
     * @param SitemapConfigReaderInterface $configReader
     * @param Escaper $escaper
     * @param Sitemap $sitemap The core sitemap being generated: its name, path and store
     * @param string $urlsetOpen The opening of every child file, up to and including `<urlset …>`
     * @param string[] $types Every type a child file can have, in the order the index lists them
     * @param string[]|null $onlyTypes Rewrite these types and keep the others' files; null for all
     */
    public function __construct(
        Filesystem                                    $filesystem,
        private readonly SitemapConfigReaderInterface $configReader,
        private readonly Escaper                      $escaper,
        private readonly Sitemap                      $sitemap,
        private readonly string                       $urlsetOpen,
        private readonly array                        $types,
        ?array                                        $onlyTypes = null
    ) {
        $this->publicDirectory    = $filesystem->getDirectoryWrite(DirectoryList::PUB);
        $this->temporaryDirectory = $filesystem->getDirectoryWrite(DirectoryList::SYS_TMP);
        $this->onlyTypes          = $onlyTypes !== null && $this->hasOwnIndex() ? $onlyTypes : null;
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
     * Start writing the rows of a type.
     *
     * @param string $type
     * @return void
     */
    public function startType(string $type): void
    {
        $this->closeFile();
        $this->type       = $type;
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
     * Move the files into place, write the index, and remove what the new set no longer contains.
     *
     * @return string[] The child files, in the order the index lists them
     */
    public function finish(): array
    {
        $this->closeFile();

        foreach (array_keys($this->children) as $child) {
            $path = $this->filePath($child);
            $this->temporaryDirectory->renameFile($path, $path, $this->publicDirectory);
        }

        $entries = $this->indexEntries();
        $this->writeIndex($entries);
        $this->removeLeftovers();

        return array_keys($entries);
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
     * Open the next file of the current type.
     *
     * @return void
     */
    private function openFile(): void
    {
        $this->fileNumber++;
        $this->stream = $this->temporaryDirectory->openFile($this->filePath($this->childName()));
        $this->stream->write($this->urlsetOpen);
        $this->lineCount = 0;
        $this->fileSize  = \strlen($this->urlsetOpen . self::URLSET_CLOSE);
    }

    /**
     * Close the file being written, if any.
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
        $this->stream                        = null;
        $this->children[$this->childName()] = $this->type;
    }

    /**
     * The files the index lists, in type order, each with its `<lastmod>`.
     *
     * Every file carries its own file time once in place, whether written now or kept from an
     * earlier generation, so its date changes only when the file does. Stamping the files written
     * now with the time of the index instead would give a file one date now and its file time at the
     * next rebuild that keeps it — moving it back by up to the length of this generation.
     *
     * @return array<string,string> file name => lastmod
     */
    private function indexEntries(): array
    {
        $entries = [];
        foreach ($this->types as $type) {
            $files = $this->writes($type) ? array_keys($this->children, $type, true) : $this->filesOnDisk($type);
            foreach ($files as $file) {
                $entries[$file] = $this->lastModified($file);
            }
        }

        return $entries;
    }

    /**
     * A file's time in place, as `<lastmod>`; the current time when the file system reports none.
     *
     * @param string $file
     * @return string
     */
    private function lastModified(string $file): string
    {
        $mtime = (int) ($this->publicDirectory->stat($this->filePath($file))['mtime'] ?? 0);

        return date('c', $mtime > 0 ? $mtime : time());
    }

    /**
     * This sitemap's files of a type now in place, in file-number order.
     *
     * @param string $type
     * @return string[]
     */
    private function filesOnDisk(string $type): array
    {
        $pattern = '/^' . preg_quote($this->baseName() . '-' . (int) $this->sitemap->getStoreId() . '-' . $type, '/')
            . '-(\d+)\.xml$/';

        $files = [];
        foreach ($this->directoryFiles() as $name) {
            if (preg_match($pattern, $name, $matches) === 1) {
                $files[(int) $matches[1]] = $name;
            }
        }
        ksort($files);

        return array_values($files);
    }

    /**
     * Write the index listing the given files, and move it into place.
     *
     * @param array<string,string> $entries file name => lastmod
     * @return void
     */
    private function writeIndex(array $entries): void
    {
        $path   = $this->filePath((string) $this->sitemap->getSitemapFilename());
        $stream = $this->temporaryDirectory->openFile($path);
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
        $this->temporaryDirectory->renameFile($path, $path, $this->publicDirectory);
    }

    /**
     * Delete this sitemap's child files of the written types that the new set does not contain.
     *
     * A full generation also removes core's own untyped `{name}-{store}-{n}.xml`; a rewrite of some
     * types touches only theirs.
     *
     * @return void
     */
    private function removeLeftovers(): void
    {
        $types   = $this->onlyTypes ?? $this->types;
        $untyped = $this->onlyTypes === null ? '?' : '';
        $pattern = '/^' . preg_quote($this->baseName(), '/') . '-' . (int) $this->sitemap->getStoreId()
            . '-(?:(?:' . implode('|', array_map(static fn (string $type) => preg_quote($type, '/'), $types))
            . ')-)' . $untyped . '\d+\.xml$/';

        $directory = rtrim((string) $this->sitemap->getSitemapPath(), '/');
        foreach ($this->directoryFiles() as $name) {
            if (preg_match($pattern, $name) === 1 && !isset($this->children[$name])) {
                $this->publicDirectory->delete($directory . '/' . $name);
            }
        }
    }

    /**
     * The names of the files in the sitemap's directory.
     *
     * @return string[]
     */
    private function directoryFiles(): array
    {
        $directory = rtrim((string) $this->sitemap->getSitemapPath(), '/');
        if (!$this->publicDirectory->isExist($directory)) {
            return [];
        }

        $names = [];
        foreach ($this->publicDirectory->read($directory) as $path) {
            $slash   = strrpos($path, '/');
            $names[] = $slash === false ? $path : substr($path, $slash + 1);
        }

        return $names;
    }

    /**
     * Whether the sitemap's index was written by this generator, so other types' files can be kept.
     *
     * Core's generator writes a single `<urlset>` under the same name, or an index of its own
     * numbered files; either way there are no typed files to keep.
     *
     * @return bool
     */
    private function hasOwnIndex(): bool
    {
        $path = $this->filePath((string) $this->sitemap->getSitemapFilename());
        if (!$this->publicDirectory->isExist($path)) {
            return false;
        }

        $types = implode('|', array_map(static fn (string $type) => preg_quote($type, '/'), $this->types));

        return preg_match(
            '/<sitemapindex.*' . preg_quote($this->baseName() . '-' . (int) $this->sitemap->getStoreId(), '/')
            . '-(?:' . $types . ')-\d+\.xml/s',
            $this->publicDirectory->readFile($path)
        ) === 1;
    }

    /**
     * The name of the current file.
     *
     * @return string
     */
    private function childName(): string
    {
        return $this->baseName() . '-' . (int) $this->sitemap->getStoreId() . '-' . $this->type . '-'
            . $this->fileNumber . '.xml';
    }

    /**
     * The sitemap's file name without `.xml`, as core names its own children from it.
     *
     * @return string
     */
    private function baseName(): string
    {
        return str_replace('.xml', '', (string) $this->sitemap->getSitemapFilename());
    }

    /**
     * The path of a file in the sitemap's directory, as core builds it.
     *
     * @param string $fileName
     * @return string
     */
    private function filePath(string $fileName): string
    {
        return rtrim((string) $this->sitemap->getSitemapPath(), '/') . '/' . $fileName;
    }
}
