<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use MageOS\Seo\Model\Product\OfferEnricher\ShippingRules;

/**
 * Checks the shipping rate before it is stored.
 *
 * An amount of 0 or more, in the base currency (Model\Product\OfferEnricher\ShippingRules, issue
 * #33); required while shipping details are on, since no rate would otherwise be published as free
 * shipping. Whether they are on is what is saved with it, or what is stored when the rate is saved
 * on its own (`bin/magento config:set`).
 */
class ShippingRate extends Value
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param ShippingRules $shippingRules
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param mixed[] $data
     */
    public function __construct(
        Context                        $context,
        Registry                       $registry,
        ScopeConfigInterface           $config,
        TypeListInterface              $cacheTypeList,
        private readonly ShippingRules $shippingRules,
        ?AbstractResource              $resource = null,
        ?AbstractDb                    $resourceCollection = null,
        array                          $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Refuse a rate the storefront could not publish, and store it trimmed.
     *
     * @throws LocalizedException
     * @return $this
     */
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());

        switch ($this->shippingRules->rateProblem($value)) {
            case ShippingRules::NOT_AN_AMOUNT:
                throw new LocalizedException(
                    __('Shipping Rate must be an amount of 0 or more, such as 4.95. Enter 0.00 for free shipping.')
                );
            case ShippingRules::NO_RATE:
                if ($this->shippingDetailsAreOn()) {
                    throw new LocalizedException(__(
                        'Enter a Shipping Rate while Enable Shipping Details Schema is Yes. Enter 0.00 for free'
                        . ' shipping.'
                    ));
                }
        }

        $this->setValue($value);

        return parent::beforeSave();
    }

    /**
     * Whether shipping details are on: as saved with the rate, or as stored at this scope.
     *
     * @return bool
     */
    private function shippingDetailsAreOn(): bool
    {
        $enabled = $this->getFieldsetDataValue('enabled');
        if ($enabled === null) {
            $path    = (string) $this->getPath();
            $enabled = $this->_config->getValue(
                substr($path, 0, (int) strrpos($path, '/') + 1) . 'enabled',
                $this->getScope() ?: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                $this->getScopeCode()
            );
        }

        return (bool) (int) $enabled;
    }
}
