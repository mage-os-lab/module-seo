<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Feed;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Directory\WriteFactory;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use MageOS\Seo\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * File storage for pre-generated SEO feeds (llms.txt, llms-full.txt, llms.jsonl), one
 * directory per store view.
 *
 * Defaults to var/mageos_seo/store_<id>/; a custom absolute directory can be
 * configured (mageos_seo_general/feeds/storage_dir) so multi-server deployments
 * can point web servers and the cron/consumer host at a shared mount — var/ is
 * host-local on scaled setups.
 */
class FeedStorage
{
    private const DEFAULT_BASE_DIR = 'mageos_seo';

    /**
     * Feed files: owner read/write, group read, nothing for others. Replacing a file needs
     * only directory permissions, so writers do not need group write on the file.
     */
    private const FILE_MODE = 0o640;

    /**
     * Feed directories: owner full access, group enter/list, nothing for others. This also
     * covers the moment between a rename and the file mode being applied.
     */
    private const DIRECTORY_MODE = 0o750;

    /**
     * @param Filesystem $filesystem
     * @param WriteFactory $writeFactory
     * @param ReadFactory $readFactory
     * @param Config $seoConfig
     * @param StorageDirectory $storageDirectory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Filesystem       $filesystem,
        private readonly WriteFactory     $writeFactory,
        private readonly ReadFactory      $readFactory,
        private readonly Config           $seoConfig,
        private readonly StorageDirectory $storageDirectory,
        private readonly LoggerInterface  $logger
    ) {
    }

    /**
     * The configured storage directory, or none when the installation does not permit it.
     *
     * The admin field is validated on save, but a configuration row can arrive another way — a
     * data patch, a deployment tool, a direct database write — so the value is checked again here,
     * where it turns into a directory handle. Refusing it falls back to var/mageos_seo rather than
     * failing: the feeds keep working, in the one place every installation can write.
     *
     * @return string
     */
    private function configuredDirectory(): string
    {
        $configured = $this->seoConfig->getFeedStorageDir();
        if ($configured === '' || $this->storageDirectory->isAllowed($configured)) {
            return $configured;
        }

        $this->logger->error(
            'MageOS_Seo: the configured feed storage directory is not permitted and was ignored;'
            . ' falling back to var/mageos_seo.',
            ['storage_dir' => $configured]
        );

        return '';
    }

    /**
     * Persist a feed file for a store, replacing any existing file atomically.
     *
     * The content is written to a temporary file in the same directory and renamed over the
     * target, so a concurrent request reads either the previous file or the complete new one,
     * never a partially written file.
     *
     * @param string $fileName
     * @param int $storeId
     * @param string $content
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return void
     */
    public function write(string $fileName, int $storeId, string $content): void
    {
        $writer = $this->openForWrite($storeId);

        try {
            $writer->write($content);
            $writer->commit($fileName);
        } catch (\Exception $e) {
            $writer->discard();
            throw $e;
        }
    }

    /**
     * Open a writer that builds one feed file for a store incrementally.
     *
     * The document is streamed out (llms.jsonl, a line per product) instead of held in memory;
     * the served file name is given to commit(), and the file appears only then.
     *
     * @param int $storeId
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return FeedFileWriter
     */
    public function openForWrite(int $storeId): FeedFileWriter
    {
        $dir = $this->getWrite();
        $this->prepareDirectories($dir, $storeId);

        return new FeedFileWriter(
            $dir,
            // Hidden and ".tmp"-suffixed: no served file name or cleanup pattern can match it.
            $this->path('.' . bin2hex(random_bytes(6)) . '.tmp', $storeId),
            $this->prefix() . 'store_' . $storeId,
            self::FILE_MODE
        );
    }

    /**
     * Create the store directory (and the default base directory) with the feed directory mode.
     *
     * The mode is re-applied on every write so directories created before this policy, or by
     * another tool, converge on it. A custom storage root is left as configured. Changing the
     * mode of a directory owned by another user fails and is ignored.
     *
     * @param WriteInterface $dir
     * @param int $storeId
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return void
     */
    private function prepareDirectories(WriteInterface $dir, int $storeId): void
    {
        $directories = [$this->prefix() . 'store_' . $storeId];
        if ($this->prefix() !== '') {
            array_unshift($directories, rtrim($this->prefix(), '/'));
        }

        foreach ($directories as $directory) {
            if (!$dir->isExist($directory)) {
                $dir->create($directory);
            }
            try {
                $dir->changePermissions($directory, self::DIRECTORY_MODE);
            } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- best effort
            }
        }
    }

    /**
     * Read a feed file for a store, or null when it has not been generated.
     *
     * @param string $fileName
     * @param int $storeId
     * @return string|null
     */
    public function read(string $fileName, int $storeId): ?string
    {
        try {
            $dir  = $this->getRead();
            $path = $this->path($fileName, $storeId);
            if (!$dir->isFile($path)) {
                return null;
            }
            return $dir->readFile($path);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Delete matching feed files for one store.
     *
     * @param string $fileNamePattern Glob pattern, e.g. "llms*.txt"
     * @param int $storeId
     * @return void
     */
    public function deleteForStore(string $fileNamePattern, int $storeId): void
    {
        try {
            $dir = $this->getWrite();
            foreach ($this->search((string) $this->storeDirectory($storeId), $fileNamePattern) as $path) {
                $dir->delete($path);
            }
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- best-effort cleanup
        }
    }

    /**
     * Delete a store's whole feed directory (best effort).
     *
     * Used when a store view is deleted: nothing rebuilds its files, so without this they stay
     * on disk forever. A custom storage root keeps its own store_<id>/ directories only.
     *
     * @param int $storeId
     * @return void
     */
    public function deleteStoreDirectory(int $storeId): void
    {
        try {
            $dir       = $this->getWrite();
            $directory = $this->prefix() . 'store_' . $storeId;
            if ($dir->isExist($directory)) {
                $dir->delete($directory);
            }
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- best-effort cleanup
        }
    }

    /**
     * List the store view IDs that have a feed directory.
     *
     * Used to find directories left behind by store views that no longer exist: deleting a
     * store group or a website removes its store views through a database-level cascade, with
     * no event to act on.
     *
     * @return int[]
     */
    public function listStoreDirectories(): array
    {
        try {
            $root     = $this->storeDirectory(null);
            $storeIds = [];
            foreach ($this->search($root, 'store_*') as $path) {
                $name = substr((string) $path, $root === null ? 0 : \strlen($root) + 1);
                if (preg_match('/^store_(\d+)$/', $name, $matches) === 1) {
                    $storeIds[] = (int) $matches[1];
                }
            }

            return $storeIds;
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * The storage-relative directory a store view's files live in.
     *
     * With $storeId null it is the directory those store directories sit in, which is null for a
     * custom storage root: the configured directory is itself the root, with nothing above it.
     *
     * @param int|null $storeId
     * @return string|null
     */
    private function storeDirectory(?int $storeId): ?string
    {
        if ($storeId !== null) {
            return $this->prefix() . 'store_' . $storeId;
        }

        $base = rtrim($this->prefix(), '/');

        return $base === '' ? null : $base;
    }

    /**
     * List the storage entries matching a pattern, reading the directory each time.
     *
     * Deliberately not WriteInterface::search(): that goes through
     * Magento\Framework\Filesystem\Glob, which memoises every result for the life of the
     * process, so a listing taken after this process has written or deleted files is the
     * listing from before it did. Both the queue consumer and the cron rebuild more than once
     * per process, and a rebuild lists its own output — files to delete, store directories —
     * to decide what to remove. (Glob::clearCache() would do, but it does not
     * exist on every version this module supports, and reading the directory is no more work.)
     *
     * @param string|null $directory Storage-relative directory, or null for the storage root
     * @param string $mask Glob pattern matched against each entry's own name
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return string[] Storage-relative paths
     */
    private function search(?string $directory, string $mask): array
    {
        // read() reports storage-relative paths, so an entry's own name is what follows the
        // directory it was read from. The directory is always one this class built, which is
        // why neither end needs taking apart with dirname()/basename().
        $nameOffset = $directory === null ? 0 : \strlen($directory) + 1;

        $paths = [];
        foreach ($this->getWrite()->read($directory) as $entry) {
            $entry = (string) $entry;
            if (fnmatch($mask, substr($entry, $nameOffset))) {
                $paths[] = $entry;
            }
        }

        return $paths;
    }

    /**
     * Build the storage-relative path of a feed file.
     *
     * @param string $fileName
     * @param int $storeId
     * @return string
     */
    private function path(string $fileName, int $storeId): string
    {
        return $this->prefix() . 'store_' . $storeId . '/' . $fileName;
    }

    /**
     * Path prefix inside the storage root ('' for a custom absolute directory).
     *
     * @return string
     */
    private function prefix(): string
    {
        return $this->configuredDirectory() === '' ? self::DEFAULT_BASE_DIR . '/' : '';
    }

    /**
     * Writable handle on the storage root.
     *
     * @return WriteInterface
     */
    private function getWrite(): WriteInterface
    {
        $custom = $this->configuredDirectory();
        if ($custom !== '') {
            return $this->writeFactory->create($custom);
        }

        return $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
    }

    /**
     * Readable handle on the storage root.
     *
     * @return ReadInterface
     */
    private function getRead(): ReadInterface
    {
        $custom = $this->configuredDirectory();
        if ($custom !== '') {
            return $this->readFactory->create($custom);
        }

        return $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
    }
}
