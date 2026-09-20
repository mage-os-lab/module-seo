<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\ResourceModel;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * A resource model that resolves its own connection, or fails saying which one is missing.
 *
 * Sits between Magento's AbstractDb and this module's resource models, and adds exactly one thing.
 * AbstractDb::getConnection() returns false when the resource's connection name is not configured
 * in env.php; every query here is against a connection that must exist, so false is a deployment
 * fault rather than a case to fall back from. Annotating the union away at the call site — the
 * other way to satisfy static analysis — lets that false travel on and surface as "call to a
 * member function on bool" somewhere further down, a long way from the cause.
 *
 * Resource models that never touch the connection directly, because their reads go through a
 * collection, have no reason to extend this.
 */
abstract class AbstractConnectedResource extends AbstractDb
{
    /**
     * The resource's connection, guaranteed to be one.
     *
     * @return AdapterInterface
     * @throws \RuntimeException
     */
    protected function connection(): AdapterInterface
    {
        $connection = $this->getConnection();

        if (!$connection instanceof AdapterInterface) {
            // Named by class rather than by table: getMainTable() resolves the table name through
            // ResourceConnection, which is the thing that has just failed to hand over a
            // connection.
            throw new \RuntimeException(
                sprintf(
                    'MageOS_Seo: no database connection named "%s" is configured, so %s cannot'
                    . ' reach its table.',
                    $this->connectionName,
                    static::class
                )
            );
        }

        return $connection;
    }
}
