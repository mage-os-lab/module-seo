<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

use MageOS\Seo\Api\Data\OrganizationInterface;

/**
 * Reads the Organization record of a scope (exactly, or through the store view → website → default
 * fallback), saves it, and deletes the records of scopes that go away.
 *
 * @api
 */
interface OrganizationRepositoryInterface
{
    /**
     * Load the Organization record for an explicit scope+scopeId pair.
     *
     * Returns the row for that scope exactly (no fallback). If no row exists,
     * returns a new unsaved model with scope/scopeId pre-set so it can be saved
     * directly. Use getForScope() when you need the inherited fallback chain.
     *
     * @param string $scope 'default' | 'websites' | 'stores'
     * @param int $scopeId Website or store ID; 0 for the global default.
     * @return \MageOS\Seo\Api\Data\OrganizationInterface
     */
    public function get(string $scope = 'default', int $scopeId = 0): OrganizationInterface;

    /**
     * Load Organization for display/rendering, applying the full scope fallback.
     *
     * Falls back: store-view → website → global default.
     *
     * @param int $storeId Current store view ID.
     * @param int $websiteId Current website ID.
     * @return \MageOS\Seo\Api\Data\OrganizationInterface
     */
    public function getForScope(int $storeId, int $websiteId): OrganizationInterface;

    /**
     * Persist the Organization settings record.
     *
     * @param \MageOS\Seo\Api\Data\OrganizationInterface $organization
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     * @return \MageOS\Seo\Api\Data\OrganizationInterface
     */
    public function save(OrganizationInterface $organization): OrganizationInterface;

    /**
     * Delete the Organization records of one scope.
     *
     * Used when the scopes themselves go away. scope_id points at a website or a store view
     * depending on the scope, so the table carries no foreign key to clean up after them —
     * the same reason core_config_data has none, and core clears it the same way from
     * Website::beforeDelete() and Group::beforeDelete().
     *
     * @param string $scope 'default' | 'websites' | 'stores'
     * @param array<int|string> $scopeIds Website or store IDs; an empty list deletes nothing.
     *                                    Values are cast to int, so IDs read from a request or
     *                                    a model's data are accepted as they come.
     * @throws \Magento\Framework\Exception\CouldNotDeleteException
     * @return int Number of records deleted.
     */
    public function deleteForScope(string $scope, array $scopeIds): int;
}
