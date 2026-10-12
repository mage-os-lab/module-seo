<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Sitemap\Model\ResourceModel\Sitemap as SitemapResource;
use Magento\Sitemap\Model\ResourceModel\Sitemap\CollectionFactory as SitemapCollectionFactory;
use Magento\Sitemap\Model\Sitemap;
use Psr\Log\LoggerInterface;

/**
 * Removes the files of Site Map entries that are gone, so they do not stay reachable for ever.
 *
 * Core's Delete action removes an entry's index file and nothing else, for its own generator as for
 * this one; and a store view's entries are removed by the `sitemap.store_id` foreign key when the
 * store view goes, with no model delete at all, so even their index stays. This removes the index
 * and every file FileNames knows as the entry's: either set of each type, the names from before
 * the sets, core's own, and files left half-written.
 *
 * Files another entry still uses stay: the index while an entry has the same path and file name,
 * the other files while one also has the same store view.
 *
 * The removal waits for the deletion to commit — a deletion that rolls back keeps its files — and
 * for the sitemap's GenerationLock, so it does not remove files a rebuild is writing. When the
 * lock is not free in time, it removes them anyway: the entry is gone, and whatever that rebuild
 * still publishes would be left with nothing to remove it.
 */
class FileRemover
{
    /**
     * @param Filesystem $filesystem
     * @param FileNames $fileNames
     * @param Generator $generator
     * @param GenerationLock $generationLock
     * @param SitemapCollectionFactory $sitemapCollectionFactory
     * @param SitemapResource $sitemapResource
     * @param LoggerInterface $logger
     * @param int $lockWait Seconds to wait for a rebuild writing the files to finish
     */
    public function __construct(
        private readonly Filesystem               $filesystem,
        private readonly FileNames                $fileNames,
        private readonly Generator                $generator,
        private readonly GenerationLock           $generationLock,
        private readonly SitemapCollectionFactory $sitemapCollectionFactory,
        private readonly SitemapResource          $sitemapResource,
        private readonly LoggerInterface          $logger,
        private readonly int                      $lockWait = 10
    ) {
    }

    /**
     * Remove a deleted entry's files, once its deletion is committed.
     *
     * @param Sitemap $sitemap
     * @return void
     */
    public function removeFilesOf(Sitemap $sitemap): void
    {
        $this->afterCommit([$sitemap]);
    }

    /**
     * Remove the files of the store views' entries, once the store views' deletion is committed.
     *
     * Called before the store views go — afterwards their entries can no longer be read.
     *
     * @param int[] $storeIds
     * @return void
     */
    public function removeFilesOfStores(array $storeIds): void
    {
        if ($storeIds === []) {
            return;
        }

        $collection = $this->sitemapCollectionFactory->create();
        $collection->addFieldToFilter('store_id', ['in' => $storeIds]);
        /** @var Sitemap[] $sitemaps */
        $sitemaps = array_values($collection->getItems());

        $this->afterCommit($sitemaps);
    }

    /**
     * Remove the entries' files now, each on its own: one that fails does not keep the others.
     *
     * @param Sitemap[] $sitemaps
     * @return void
     */
    private function removeNow(array $sitemaps): void
    {
        foreach ($sitemaps as $sitemap) {
            try {
                $this->remove($sitemap);
            } catch (\Throwable $e) {
                // The entry is gone either way; its files are left for someone to remove by hand.
                $this->logger->error(
                    'MageOS_Seo: could not remove the files of the deleted sitemap '
                    . $this->fileNames->path($sitemap, $this->fileNames->index($sitemap)) . ': ' . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        }
    }

    /**
     * Run the removal once the transaction the deletion is part of commits, or now outside one.
     *
     * A rolled-back transaction drops the callback, so its files stay with the entries that do.
     *
     * @param Sitemap[] $sitemaps
     * @return void
     */
    private function afterCommit(array $sitemaps): void
    {
        if ($sitemaps === []) {
            return;
        }

        $removal    = fn () => $this->removeNow($sitemaps);
        $connection = $this->sitemapResource->getConnection();
        if ($connection !== false && $connection->getTransactionLevel() > 0) {
            $this->sitemapResource->addCommitCallback($removal);
            return;
        }

        $removal();
    }

    /**
     * Remove one entry's files, but those another entry still uses.
     *
     * @param Sitemap $sitemap
     * @throws FileSystemException
     * @return void
     */
    private function remove(Sitemap $sitemap): void
    {
        [$indexShared, $filesShared] = $this->sharing($sitemap);
        if ($filesShared) {
            return;
        }

        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::PUB);
        $path      = $this->fileNames->directory($sitemap);
        if (!$directory->isExist($path)) {
            return;
        }

        $locked = $this->generationLock->acquire($sitemap, $this->lockWait);
        if (!$locked) {
            $this->logger->warning(
                'MageOS_Seo: sitemap ' . $this->fileNames->path($sitemap, $this->fileNames->index($sitemap))
                . ' is being written by another process; its files are removed anyway, as its entry is gone.'
            );
        }

        try {
            $types = $this->generator->getFileTypes();
            $index = $this->fileNames->index($sitemap);
            foreach ($directory->read($path) as $file) {
                $name = $this->fileNames->nameOf($file);
                if ($this->fileNames->isOwn($sitemap, $types, $name) || (!$indexShared && $name === $index)) {
                    $directory->delete($this->fileNames->path($sitemap, $name));
                }
            }
        } finally {
            if ($locked) {
                $this->generationLock->release($sitemap);
            }
        }
    }

    /**
     * Whether another entry uses the index, and whether one uses the other files.
     *
     * The index: an entry with the same path and file name. The other files: one for the same
     * store view too.
     *
     * @param Sitemap $sitemap
     * @return array{bool, bool}
     */
    private function sharing(Sitemap $sitemap): array
    {
        $collection = $this->sitemapCollectionFactory->create();
        $collection->addFieldToFilter('sitemap_path', (string) $sitemap->getSitemapPath());
        $collection->addFieldToFilter('sitemap_filename', (string) $sitemap->getSitemapFilename());
        if ($sitemap->getId()) {
            $collection->addFieldToFilter('sitemap_id', ['neq' => (int) $sitemap->getId()]);
        }

        $indexShared = false;
        $filesShared = false;
        /** @var Sitemap $other */
        foreach ($collection as $other) {
            $indexShared = true;
            if ((int) $other->getStoreId() === (int) $sitemap->getStoreId()) {
                $filesShared = true;
            }
        }

        return [$indexShared, $filesShared];
    }
}
