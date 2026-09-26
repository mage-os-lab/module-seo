<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel;

use MageOS\Seo\Model\Organisation as OrganisationModel;

class Organisation extends AbstractConnectedResource
{
    /**
     * Initialize resource model table and primary key.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('mageos_seo_organisation', 'entity_id');
    }

    /**
     * Load a model by scope + scope_id combination.
     *
     * The model's hasData() / getId() will return false/null if no row matched.
     *
     * @param OrganisationModel $model
     * @param string $scope 'default' | 'websites' | 'stores'
     * @param int $scopeId
     * @return void
     */
    public function loadByScope(OrganisationModel $model, string $scope, int $scopeId): void
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

    /**
     * Rename an organisation type in every stored row.
     *
     * @param string $from
     * @param string $to
     * @return int The number of rows changed
     */
    public function renameOrgType(string $from, string $to): int
    {
        return $this->connection()->update(
            $this->getMainTable(),
            ['org_type' => $to],
            ['org_type = ?' => $from]
        );
    }
}
