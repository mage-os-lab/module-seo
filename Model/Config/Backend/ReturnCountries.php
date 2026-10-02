<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use MageOS\Seo\Model\Product\OfferEnricher\CountryList;
use MageOS\Seo\Model\Product\OfferEnricher\ReturnPolicyEnricher;

/**
 * Saves the return policy's countries, and says so when Google will not read them all.
 *
 * Google reads up to 50 countries in applicableCountry. A larger selection is saved as chosen, and
 * ReturnPolicyEnricher outputs the first 50 in the list; the admin is told which, and pointed to
 * Applies Worldwide.
 */
class ReturnCountries extends Value
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param ManagerInterface $messageManager
     * @param CountryList $countryList
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param mixed[] $data
     */
    public function __construct(
        Context                           $context,
        Registry                          $registry,
        ScopeConfigInterface              $config,
        TypeListInterface                 $cacheTypeList,
        private readonly ManagerInterface $messageManager,
        private readonly CountryList      $countryList,
        ?AbstractResource                 $resource = null,
        ?AbstractDb                       $resourceCollection = null,
        array                             $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Once saved, tell the admin if more countries are chosen than Google reads.
     *
     * @return $this
     */
    public function afterSave()
    {
        $value = $this->getValue();
        $count = \count($this->countryList->fromConfig(\is_array($value) ? implode(',', $value) : (string) $value));

        if ($count > ReturnPolicyEnricher::MAX_COUNTRIES) {
            $this->messageManager->addNoticeMessage((string) __(
                'Google reads at most %1 return countries. %2 are selected, so only the first %1 in the list'
                . ' are output. Choose Applies Worldwide to cover every country.',
                ReturnPolicyEnricher::MAX_COUNTRIES,
                $count
            ));
        }

        return parent::afterSave();
    }
}
