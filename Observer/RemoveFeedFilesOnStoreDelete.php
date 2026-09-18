<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Api\Data\StoreInterface;
use MageOS\Seo\Model\Feed\FeedStorage;
use Psr\Log\LoggerInterface;

/**
 * Removes a deleted store view's feed directory.
 *
 * Feed files live per store view. Once the store view is gone nothing rebuilds or serves its
 * files, so they would stay in the storage directory for good.
 */
class RemoveFeedFilesOnStoreDelete implements ObserverInterface
{
    /**
     * @param FeedStorage $feedStorage
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly FeedStorage     $feedStorage,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Delete the store view's feed directory.
     *
     * The store is already deleted when this runs (the event is dispatched from a commit
     * callback), so a failure here must not propagate: it would surface as an error on a
     * completed deletion. It is logged instead.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $store = $observer->getEvent()->getData('store');
        if (!$store instanceof StoreInterface || $store->getId() === null) {
            return;
        }

        try {
            $this->feedStorage->deleteStoreDirectory((int) $store->getId());
        } catch (\Throwable $e) {
            $this->logger->error(
                \sprintf(
                    'MageOS_Seo: could not remove the feed directory of deleted store %d: %s',
                    (int) $store->getId(),
                    $e->getMessage()
                ),
                ['exception' => $e]
            );
        }
    }
}
