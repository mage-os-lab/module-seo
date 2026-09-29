<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel;

use MageOS\Seo\Model\Organization as OrganizationModel;

class Organization extends AbstractConnectedResource
{
    /**
     * Initialize resource model table and primary key.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('mageos_seo_organization', 'entity_id');
    }

    /**
     * Load a model by scope + scope_id combination.
     *
     * The model's hasData() / getId() will return false/null if no row matched.
     *
     * @param OrganizationModel $model
     * @param string $scope 'default' | 'websites' | 'stores'
     * @param int $scopeId
     * @return void
     */
    public function loadByScope(OrganizationModel $model, string $scope, int $scopeId): void
    {
        $connection = $this->connection();
        $select     = $connection->select()
            ->from($this->getMainTable())
            ->where('scope = ?', $scope)
            ->where('scope_id = ?', $scopeId);

        $data = $connection->fetchRow($select);
        if ($data) {
            $model->addData($data);
            $model->setOrigData();
            $model->isObjectNew(false);
        }
    }
}
