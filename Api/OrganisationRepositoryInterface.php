<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

use MageOS\Seo\Api\Data\OrganisationInterface;

interface OrganisationRepositoryInterface
{
    /**
     * Load the Organisation record for an explicit scope+scopeId pair.
     *
     * Returns the row for that scope exactly (no fallback). If no row exists,
     * returns a new unsaved model with scope/scopeId pre-set so it can be saved
     * directly. Use getForScope() when you need the inherited fallback chain.
     *
     * @param string $scope 'default' | 'websites' | 'stores'
     * @param int $scopeId Website or store ID; 0 for the global default.
     * @return \MageOS\Seo\Api\Data\OrganisationInterface
     */
    public function get(string $scope = 'default', int $scopeId = 0): OrganisationInterface;

    /**
     * Load Organisation for display/rendering, applying the full scope fallback.
     *
     * Falls back: store-view → website → global default.
     *
     * @param int $storeId Current store view ID.
     * @param int $websiteId Current website ID.
     * @return \MageOS\Seo\Api\Data\OrganisationInterface
     */
    public function getForScope(int $storeId, int $websiteId): OrganisationInterface;

    /**
     * Persist the Organisation settings record.
     *
     * @param \MageOS\Seo\Api\Data\OrganisationInterface $organisation
     * @return \MageOS\Seo\Api\Data\OrganisationInterface
     */
    public function save(OrganisationInterface $organisation): OrganisationInterface;
}
