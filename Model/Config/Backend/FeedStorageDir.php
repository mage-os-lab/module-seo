<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use MageOS\Seo\Model\Feed\StorageDirectory;

/**
 * Refuses a feed storage directory the installation does not permit, with the reason.
 *
 * The check is repeated when the value is read (see Model\Feed\FeedStorage): a configuration row
 * can be written without passing through here — by a data patch, a deployment tool, or straight
 * into the database — and a directory this rejects must not be written to whichever way it arrived.
 */
class FeedStorageDir extends Value
{
    /**
     * @param StorageDirectory $storageDirectory
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param mixed[] $data
     */
    public function __construct(
        private readonly StorageDirectory $storageDirectory,
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Validate the configured directory before it is stored.
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return $this
     */
    public function beforeSave(): self
    {
        $this->storageDirectory->validate((string) $this->getValue());

        return parent::beforeSave();
    }
}
