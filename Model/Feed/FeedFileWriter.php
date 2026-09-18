<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Feed;

use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\File\WriteInterface as FileWriteInterface;

/**
 * Writes one feed file incrementally, and only puts it in place when it is complete.
 *
 * Content goes to a temporary file in the store's feed directory; commit() renames that over
 * the served name, so readers see the previous file or the complete new one, never a partially
 * written document, and a build that fails leaves the served file untouched. The served name is
 * chosen at commit time: the hreflang sitemap only knows whether its first chunk is the whole
 * document once the stream ends.
 */
class FeedFileWriter
{
    /**
     * @var FileWriteInterface|null
     */
    private ?FileWriteInterface $file = null;

    /**
     * @param WriteInterface $directory Feed storage root
     * @param string $temporaryPath Path of the temporary file, relative to that root
     * @param string $directoryPath Store directory, relative to that root
     * @param int $fileMode Mode the finished file gets
     */
    public function __construct(
        private readonly WriteInterface $directory,
        private readonly string         $temporaryPath,
        private readonly string         $directoryPath,
        private readonly int            $fileMode
    ) {
    }

    /**
     * Append content to the file being built.
     *
     * @param string $content
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return void
     */
    public function write(string $content): void
    {
        if ($this->file === null) {
            $this->file = $this->directory->openFile($this->temporaryPath, 'w');
        }

        $this->file->write($content);
    }

    /**
     * Put the finished file in place under the given name, replacing any existing one.
     *
     * @param string $fileName
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return void
     */
    public function commit(string $fileName): void
    {
        $this->write('');
        $this->close();

        $path = $this->directoryPath . '/' . $fileName;
        $this->directory->renameFile($this->temporaryPath, $path);

        try {
            // The filesystem driver's rename() applies 0777 & ~umask to the file.
            $this->directory->changePermissions($path, $this->fileMode);
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- the file is in place
        }
    }

    /**
     * Throw the partial file away; the served file stays as it is.
     *
     * @return void
     */
    public function discard(): void
    {
        $this->close();

        try {
            if ($this->directory->isExist($this->temporaryPath)) {
                $this->directory->delete($this->temporaryPath);
            }
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- best-effort cleanup
        }
    }

    /**
     * Close the underlying handle if it was opened.
     *
     * @return void
     */
    private function close(): void
    {
        if ($this->file === null) {
            return;
        }

        try {
            $this->file->close();
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- already closed or gone
        }
        $this->file = null;
    }
}
