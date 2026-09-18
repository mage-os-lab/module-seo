<?php

declare(strict_types=1);

namespace MageOS\Seo\Model;

use MageOS\Seo\Api\Data\OrganisationInterface;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
use MageOS\Seo\Model\ResourceModel\Organisation as OrganisationResource;
use MageOS\Seo\Model\ResourceModel\Organisation\CollectionFactory;

class OrganisationRepository implements OrganisationRepositoryInterface
{
    /**
     * @param OrganisationFactory $factory
     * @param OrganisationResource $resource
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly OrganisationFactory  $factory,
        private readonly OrganisationResource $resource,
        private readonly CollectionFactory    $collectionFactory
    ) {
    }

    /**
     * @inheritdoc
     */
    public function get(string $scope = 'default', int $scopeId = 0): OrganisationInterface
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
    public function getForScope(int $storeId, int $websiteId): OrganisationInterface
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
    public function save(OrganisationInterface $organisation): OrganisationInterface
    {
        if (!$organisation instanceof \Magento\Framework\Model\AbstractModel) {
            throw new \InvalidArgumentException('Organisation model must extend AbstractModel');
        }
        $this->resource->save($organisation);
        return $organisation;
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
        foreach ($collection as $organisation) {
            $this->resource->delete($organisation);
            $deleted++;
        }

        return $deleted;
    }
}
