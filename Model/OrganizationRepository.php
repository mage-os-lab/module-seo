<?php

declare(strict_types=1);

namespace MageOS\Seo\Model;

use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use MageOS\Seo\Api\Data\OrganizationInterface;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Model\ResourceModel\Organization as OrganizationResource;
use MageOS\Seo\Model\ResourceModel\Organization\CollectionFactory;

/**
 * Reads are memoised per request.
 *
 * getForScope() walks store view → website → global default, so one call costs up to three
 * SELECTs, and a storefront page makes several: the meta-tag provider, the Organization schema
 * provider and, on pages carrying them, the article and event providers each ask independently.
 * Nothing about the answer can change mid-request, so it is read once.
 *
 * The memo hands the same model instance to every caller of a scope. They all read it; none of
 * the storefront callers mutate it. A caller that needs to change an Organization must go through
 * save(), which clears the memo.
 */
class OrganizationRepository implements OrganizationRepositoryInterface, ResetAfterRequestInterface
{
    /**
     * Models already read this request, keyed by scope.
     *
     * @var array<string, OrganizationInterface>
     */
    private array $cache = [];

    /**
     * @param OrganizationFactory $factory
     * @param OrganizationResource $resource
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly OrganizationFactory  $factory,
        private readonly OrganizationResource $resource,
        private readonly CollectionFactory    $collectionFactory
    ) {
    }

    /**
     * @inheritdoc
     */
    public function get(string $scope = 'default', int $scopeId = 0): OrganizationInterface
    {
        $model = $this->factory->create();
        $this->resource->loadByScope($model, $scope, $scopeId);

        if (!$model->getId()) {
            $model->isObjectNew(true);
            $model->setScope($scope);
            $model->setScopeId($scopeId);
        }

        return $model;
    }

    /**
     * @inheritdoc
     *
     * Fallback chain: store-view → website → global default.
     */
    public function getForScope(int $storeId, int $websiteId): OrganizationInterface
    {
        $cacheKey = "{$storeId}_{$websiteId}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $model                  = $this->resolveForScope($storeId, $websiteId);
        $this->cache[$cacheKey] = $model;

        return $model;
    }

    /**
     * Walk the fallback chain: store view, then website, then the global default.
     *
     * @param int $storeId
     * @param int $websiteId
     * @return OrganizationInterface
     */
    private function resolveForScope(int $storeId, int $websiteId): OrganizationInterface
    {
        // 1. Store-view specific
        $model = $this->factory->create();
        $this->resource->loadByScope($model, 'stores', $storeId);
        if ($model->getId()) {
            return $model;
        }

        // 2. Website specific
        $model = $this->factory->create();
        $this->resource->loadByScope($model, 'websites', $websiteId);
        if ($model->getId()) {
            return $model;
        }

        // 3. Global default
        $model = $this->factory->create();
        $this->resource->loadByScope($model, 'default', 0);
        if (!$model->getId()) {
            $model->isObjectNew(true);
        }

        return $model;
    }

    /**
     * @inheritdoc
     */
    public function save(OrganizationInterface $organization): OrganizationInterface
    {
        if (!$organization instanceof AbstractModel) {
            throw new CouldNotSaveException(__('Organization model must extend AbstractModel.'));
        }
        try {
            $this->resource->save($organization);
        } catch (\Exception $e) {
            throw new CouldNotSaveException(__('Could not save the Organization: %1', $e->getMessage()), $e);
        }
        // The whole memo, not the saved scope's entry: adding a store-view row changes the answer
        // for a store that was until now falling back to its website or to the global default,
        // and the memo cannot tell from the saved row which of those it had answered.
        $this->cache = [];

        return $organization;
    }

    /**
     * @inheritdoc
     */
    public function deleteForScope(string $scope, array $scopeIds): int
    {
        $scopeIds = array_values(array_unique(array_map('intval', $scopeIds)));
        if ($scopeIds === []) {
            return 0;
        }

        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('scope', $scope);
        $collection->addFieldToFilter('scope_id', ['in' => $scopeIds]);

        $deleted = 0;
        foreach ($collection as $organization) {
            try {
                $this->resource->delete($organization);
            } catch (\Exception $e) {
                throw new CouldNotDeleteException(
                    __(
                        'Could not delete the Organization of %1 %2: %3',
                        $scope,
                        $organization->getScopeId(),
                        $e->getMessage()
                    ),
                    $e
                );
            }
            $deleted++;
        }

        if ($deleted > 0) {
            // Same reasoning as save(): a removed row sends its scope back down the chain.
            $this->cache = [];
        }

        return $deleted;
    }

    /**
     * Drop the memoised models between worker-mode requests.
     *
     * Without this the first visitor's Organization — its name, URL and social profiles — would
     * be served to every later visitor handled by the same worker thread, including visitors of
     * a different store view.
     *
     * @return void
     */
    public function _resetState(): void // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- framework interface
    {
        $this->cache = [];
    }
}
