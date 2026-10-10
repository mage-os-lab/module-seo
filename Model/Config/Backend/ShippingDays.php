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
 * Checks a handling or transit time's minimum or maximum against the other, before it is stored.
 *
 * Refuses what the storefront could not publish (Model\Product\OfferEnricher\ShippingRules, issue
 * #32): a value that is not a whole number of days, 0 or more; a minimum with no maximum; a minimum
 * above the maximum. The other bound is the one saved with it, or the one stored when this is saved
 * on its own (`bin/magento config:set`).
 */
class ShippingDays extends Value
{
    /**
     * The fields' labels, as the admin shows them.
     */
    private const LABELS = [
        'handling_min' => 'Handling Time Min (days)',
        'handling_max' => 'Handling Time Max (days)',
        'transit_min'  => 'Transit Time Min (days)',
        'transit_max'  => 'Transit Time Max (days)',
    ];

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
     * Refuse a time the storefront could not publish, and store the value trimmed.
     *
     * @throws LocalizedException
     * @return $this
     */
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());
        $field = $this->field();
        if ($value !== '' && !$this->shippingRules->isDays($value)) {
            throw new LocalizedException(__('%1 must be a whole number of days, 0 or more.', $this->label($field)));
        }

        $time     = substr($field, 0, -4);
        $minField = $time . '_min';
        $maxField = $time . '_max';
        $min      = $field === $minField ? $value : $this->other($minField);
        $max      = $field === $maxField ? $value : $this->other($maxField);

        switch ($this->shippingRules->daysProblem($min, $max)) {
            case ShippingRules::NOT_DAYS:
                throw new LocalizedException(__(
                    '%1 must be a whole number of days, 0 or more.',
                    $this->label($field === $minField ? $maxField : $minField)
                ));
            case ShippingRules::MIN_WITHOUT_MAX:
                throw new LocalizedException(__(
                    'Enter %1 as well: a shipping time needs the most days it can take, and %2 is only the'
                    . ' fewest.',
                    $this->label($maxField),
                    $this->label($minField)
                ));
            case ShippingRules::MIN_ABOVE_MAX:
                throw new LocalizedException(
                    __('%1 cannot be more than %2.', $this->label($minField), $this->label($maxField))
                );
        }

        $this->setValue($value);

        return parent::beforeSave();
    }

    /**
     * The field being saved: the last part of its path, such as handling_min.
     *
     * @return string
     */
    private function field(): string
    {
        $path = (string) $this->getPath();

        return substr($path, (int) strrpos($path, '/') + 1);
    }

    /**
     * The other bound: saved with this one, or stored at this scope when this is saved alone.
     *
     * @param string $field
     * @return string
     */
    private function other(string $field): string
    {
        $saved = $this->getFieldsetDataValue($field);
        if ($saved !== null) {
            return trim((string) $saved);
        }

        $path = (string) $this->getPath();

        return trim((string) $this->_config->getValue(
            substr($path, 0, (int) strrpos($path, '/') + 1) . $field,
            $this->getScope() ?: ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
            $this->getScopeCode()
        ));
    }

    /**
     * A field's label, as the admin shows it.
     *
     * @param string $field
     * @return string
     */
    private function label(string $field): string
    {
        // phpcs:ignore Magento2.Translation.ConstantUsage -- the labels are the admin's, from system.xml
        return (string) __(self::LABELS[$field] ?? $field);
    }
}
