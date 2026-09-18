<?php

declare(strict_types=1);

namespace MageOS\Seo\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\Group;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\Website;
use MageOS\Seo\Api\OrganisationRepositoryInterface;

/**
 * Removes the Organisation settings of a store view, store group or website being deleted.
 *
 * The other SEO tables reference catalogue and store rows, so their records go with a foreign
 * key. This one cannot: scope_id points at a website or a store view depending on the scope
 * column, which no single constraint expresses — core_config_data is built the same way.
 *
 * Registered on the *_delete_before events for three reasons: they run inside the resource's
 * own transaction, so this deletion rolls back with a failed delete; a website or group still
 * knows its store views there; and once the delete commits, core's own
 * store.website_id / store.group_id CASCADE has taken those store views away without
 * dispatching a single store_delete event, leaving nothing to read the IDs from.
 *
 * Core does the same thing for its configuration table, from Website::beforeDelete() and
 * Group::beforeDelete(), via Config\Data::clearScopeData().
 */
class RemoveOrganisationOnScopeDelete implements ObserverInterface
{
    /**
     * @param OrganisationRepositoryInterface $organisationRepository
     */
    public function __construct(
        private readonly OrganisationRepositoryInterface $organisationRepository
    ) {
    }

    /**
     * Delete the Organisation records of the scopes this delete removes.
     *
     * Failures are not caught: the delete has not committed yet, so aborting it is better than
     * committing one that leaves stale identity settings behind.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $entity = $observer->getEvent()->getData('data_object');

        if ($entity instanceof Website) {
            $this->organisationRepository->deleteForScope(
                ScopeInterface::SCOPE_WEBSITES,
                [(int) $entity->getId()]
            );
            $this->organisationRepository->deleteForScope(
                ScopeInterface::SCOPE_STORES,
                $this->storeIds($entity->getStoreIds())
            );
            return;
        }

        if ($entity instanceof Group) {
            $this->organisationRepository->deleteForScope(
                ScopeInterface::SCOPE_STORES,
                $this->storeIds($entity->getStoreIds())
            );
            return;
        }

        if ($entity instanceof Store && $entity->getId() !== null) {
            $this->organisationRepository->deleteForScope(
                ScopeInterface::SCOPE_STORES,
                [(int) $entity->getId()]
            );
        }
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
