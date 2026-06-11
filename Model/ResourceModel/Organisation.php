<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use MageOS\Seo\Model\Organisation as OrganisationModel;

class Organisation extends AbstractDb
{
    /**
     * Initialize resource model table and primary key.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('mage-os_seo_organisation', 'entity_id');
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
        $connection = $this->getConnection();
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
