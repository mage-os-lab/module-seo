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
use MageOS\Seo\Model\Hreflang\CodeList;

/**
 * Validates and normalises the hreflang codes a store view claims.
 *
 * Refuses the save rather than storing something Google will reject: a malformed code, a region
 * that does not exist, or the same code twice. The region check is the one that earns its keep —
 * `en-UK` is well-formed and wrong (the ISO code is GB), and is the most common hreflang mistake
 * there is.
 */
class HreflangCodes extends Value
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param CodeList $codeList
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param mixed[] $data
     */
    public function __construct(
        Context                   $context,
        Registry                  $registry,
        ScopeConfigInterface      $config,
        TypeListInterface         $cacheTypeList,
        private readonly CodeList $codeList,
        ?AbstractResource         $resource = null,
        ?AbstractDb               $resourceCollection = null,
        array                     $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Validate the entered codes and store them normalised.
     *
     * @throws LocalizedException
     * @return $this
     */
    public function beforeSave()
    {
        $checked = $this->codeList->check(explode(',', (string) $this->getValue()));

        if ($checked['malformed'] !== []) {
            throw new LocalizedException(__(
                'These hreflang codes are not valid: %1. Use a two-letter language, optionally '
                . 'followed by a four-letter script and a two-letter region — for example en, '
                . 'en-GB or zh-Hant-TW.',
                implode(', ', $checked['malformed'])
            ));
        }

        if ($checked['duplicates'] !== []) {
            throw new LocalizedException(
                __('Each hreflang code can be listed once: %1.', implode(', ', $checked['duplicates']))
            );
        }

        if ($checked['unknown_regions'] !== []) {
            throw new LocalizedException(__(
                'These regions are not ISO 3166-1 country codes: %1. The United Kingdom, for '
                . 'instance, is GB rather than UK.',
                implode(', ', $checked['unknown_regions'])
            ));
        }

        $this->setValue(implode(',', $checked['codes']));

        return parent::beforeSave();
    }
}
