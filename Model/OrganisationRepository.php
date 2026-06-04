<?php

declare(strict_types=1);

namespace MageOS\Seo\Model;

use MageOS\Seo\Api\Data\OrganisationInterface;
use MageOS\Seo\Api\OrganisationRepositoryInterface;
use MageOS\Seo\Model\ResourceModel\Organisation as OrganisationResource;

class OrganisationRepository implements OrganisationRepositoryInterface
{
    private const SINGLETON_ID = 1;

    /**
     * @param OrganisationFactory $factory
     * @param OrganisationResource $resource
     */
    public function __construct(
        private readonly OrganisationFactory  $factory,
        private readonly OrganisationResource $resource
    ) {
    }

    /**
     * @inheritdoc
     */
    public function get(): OrganisationInterface
    {
        $model = $this->factory->create();
        $this->resource->load($model, self::SINGLETON_ID);

        // If no row exists yet, mark as new. Do NOT set entity_id — if we set it,
        // AbstractDb treats the model as existing and issues UPDATE (0 rows affected).
        // The auto-increment column will assign entity_id=1 on INSERT since the table
        // is empty. The singleton contract is enforced by the UNIQUE PK constraint.
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
}
