<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\Group;
use Magento\Store\Model\Store;
use Magento\Store\Model\Website;
use MageOS\Seo\Model\Sitemap\FileRemover;

/**
 * Removes the sitemap files of the store views a website, store group or store view delete takes.
 *
 * Their Site Map entries go through the `sitemap.store_id` foreign key, a database-level delete
 * that runs no model code, so nothing else would remove the files. Like
 * RemoveOrganizationOnScopeDelete, this runs on the *_delete_before events — the last moment the
 * store views and their entries can be read — and FileRemover waits for the commit before
 * removing anything, so a delete that fails keeps its files.
 */
class RemoveSitemapFilesOnScopeDelete implements ObserverInterface
{
    /**
     * @param FileRemover $fileRemover
     */
    public function __construct(
        private readonly FileRemover $fileRemover
    ) {
    }

    /**
     * Remove, once the delete commits, the files of the entries of the store views it takes.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $entity = $observer->getEvent()->getData('data_object');

        $storeIds = match (true) {
            $entity instanceof Website, $entity instanceof Group => $this->storeIds($entity->getStoreIds()),
            $entity instanceof Store && $entity->getId() !== null => [(int) $entity->getId()],
            default => [],
        };

        $this->fileRemover->removeFilesOfStores($storeIds);
    }

    /**
     * Normalise the store IDs a website or group reports.
     *
     * @param mixed $storeIds
     * @return int[]
     */
    private function storeIds(mixed $storeIds): array
    {
        return \is_array($storeIds) ? array_map('intval', array_values($storeIds)) : [];
    }
}
