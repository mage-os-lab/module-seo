<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Feed;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadFactory;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filesystem\Directory\WriteFactory;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\Glob;
use MageOS\Seo\Model\Config;

/**
 * File storage for pre-generated SEO feeds (llms.txt, llms-full.txt, llms.jsonl,
 * hreflang sitemap files), one directory per store view.
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
     */
    public function __construct(
        private readonly Filesystem   $filesystem,
        private readonly WriteFactory $writeFactory,
        private readonly ReadFactory  $readFactory,
        private readonly Config       $seoConfig
    ) {
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
     * The served file name is given to commit(), so a caller that only learns the name at the
     * end (the hreflang sitemap: one urlset, or numbered chunks plus an index) can stream the
     * document out instead of holding it in memory.
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
     * Copy a feed file from one store's directory to another's, replacing it atomically.
     *
     * Store views that share a hreflang alternate set get identical sitemap chunks; copying
     * the finished file is cheaper than generating it again per store view.
     *
     * @param string $fileName
     * @param int $fromStoreId
     * @param int $toStoreId
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return void
     */
    public function copyBetweenStores(string $fileName, int $fromStoreId, int $toStoreId): void
    {
        $dir = $this->getWrite();
        $this->prepareDirectories($dir, $toStoreId);

        $temporary = $this->path('.' . bin2hex(random_bytes(6)) . '.tmp', $toStoreId);
        $target    = $this->path($fileName, $toStoreId);

        try {
            $dir->copyFile($this->path($fileName, $fromStoreId), $temporary);
            $dir->renameFile($temporary, $target);
        } catch (\Exception $e) {
            try {
                if ($dir->isExist($temporary)) {
                    $dir->delete($temporary);
                }
            } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- best-effort cleanup
            }
            throw $e;
        }

        try {
            $dir->changePermissions($target, self::FILE_MODE);
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- the file is in place
        }
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
     * @param string $fileNamePattern Glob pattern, e.g. "hreflang-sitemap*.xml"
     * @param int $storeId
     * @return void
     */
    public function deleteForStore(string $fileNamePattern, int $storeId): void
    {
        $this->deleteByPattern('store_' . $storeId . '/' . $fileNamePattern);
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
            $storeIds = [];
            foreach ($this->search($this->prefix() . 'store_*') as $path) {
                $name = substr((string) $path, (int) strrpos((string) $path, '/') + 1);
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
     * List the names of one store's feed files matching a pattern.
     *
     * @param string $fileNamePattern Glob pattern, e.g. "hreflang-sitemap-*.xml"
     * @param int $storeId
     * @return string[] File names without their directory
     */
    public function listForStore(string $fileNamePattern, int $storeId): array
    {
        try {
            $names = [];
            foreach ($this->search($this->path($fileNamePattern, $storeId)) as $path) {
                $names[] = substr((string) $path, (int) strrpos((string) $path, '/') + 1);
            }

            return $names;
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * Delete every file matching a storage-relative glob pattern (best effort).
     *
     * @param string $relativePattern
     * @return void
     */
    private function deleteByPattern(string $relativePattern): void
    {
        try {
            $dir = $this->getWrite();
            foreach ($this->search($this->prefix() . $relativePattern) as $path) {
                $dir->delete($path);
            }
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- best-effort cleanup
        }
    }

    /**
     * Search the storage root for a pattern, ignoring the framework's glob cache.
     *
     * Magento\Framework\Filesystem\Glob memoises every glob result for the life of the process,
     * so a listing taken after this process has written or deleted files would otherwise be the
     * listing from before it did. Both the queue consumer and the cron can rebuild more than
     * once per process, and a rebuild lists its own output (surplus sitemap chunks, store
     * directories) to decide what to remove.
     *
     * @param string $pattern Storage-relative glob pattern
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return string[] Storage-relative paths
     */
    private function search(string $pattern): array
    {
        Glob::clearCache();

        return $this->getWrite()->search($pattern);
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
        return $this->seoConfig->getFeedStorageDir() === '' ? self::DEFAULT_BASE_DIR . '/' : '';
    }

    /**
     * Writable handle on the storage root.
     *
     * @return WriteInterface
     */
    private function getWrite(): WriteInterface
    {
        $custom = $this->seoConfig->getFeedStorageDir();
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
        $custom = $this->seoConfig->getFeedStorageDir();
        if ($custom !== '') {
            return $this->readFactory->create($custom);
        }

        return $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
    }
}
