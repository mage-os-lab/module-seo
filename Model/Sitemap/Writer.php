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
     * Child file names written so far, in order.
     *
     * @var string[]
     */
    private array $children = [];

    /**
     * @param Filesystem $filesystem
     * @param SitemapConfigReaderInterface $configReader
     * @param Escaper $escaper
     * @param Sitemap $sitemap The core sitemap being generated: its name, path and store
     * @param string $urlsetOpen The opening of every child file, up to and including `<urlset …>`
     * @param string[] $types Every type a child file can have, for recognising leftovers
     */
    public function __construct(
        Filesystem                                    $filesystem,
        private readonly SitemapConfigReaderInterface $configReader,
        private readonly Escaper                      $escaper,
        private readonly Sitemap                      $sitemap,
        private readonly string                       $urlsetOpen,
        private readonly array                        $types
    ) {
        $this->publicDirectory    = $filesystem->getDirectoryWrite(DirectoryList::PUB);
        $this->temporaryDirectory = $filesystem->getDirectoryWrite(DirectoryList::SYS_TMP);
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

        foreach ($this->children as $child) {
            $path = $this->filePath($child);
            $this->temporaryDirectory->renameFile($path, $path, $this->publicDirectory);
        }

        $this->writeIndex();
        $this->removeLeftovers();

        return $this->children;
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
        $this->stream     = null;
        $this->children[] = $this->childName();
    }

    /**
     * Write the index listing every child, and move it into place.
     *
     * @return void
     */
    private function writeIndex(): void
    {
        $path   = $this->filePath((string) $this->sitemap->getSitemapFilename());
        $stream = $this->temporaryDirectory->openFile($path);
        $stream->write(
            '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL
            . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL
        );

        $lastMod = date('c');
        foreach ($this->children as $child) {
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
     * Delete this sitemap's child files that the new set does not contain.
     *
     * @return void
     */
    private function removeLeftovers(): void
    {
        $directory = rtrim((string) $this->sitemap->getSitemapPath(), '/');
        if (!$this->publicDirectory->isExist($directory)) {
            return;
        }

        $types   = implode('|', array_map(static fn (string $type) => preg_quote($type, '/'), $this->types));
        $pattern = '/^' . preg_quote($this->baseName(), '/') . '-' . (int) $this->sitemap->getStoreId()
            . '-(?:(?:' . $types . ')-)?\d+\.xml$/';

        foreach ($this->publicDirectory->read($directory) as $path) {
            $slash = strrpos($path, '/');
            $name  = $slash === false ? $path : substr($path, $slash + 1);
            if (preg_match($pattern, $name) === 1 && !\in_array($name, $this->children, true)) {
                $this->publicDirectory->delete($path);
            }
        }
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
