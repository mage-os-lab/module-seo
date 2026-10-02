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
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Validates security.txt's Expires date.
 *
 * RFC 9116 requires an Expires field, and after it the file "is considered stale and should not be
 * used" (§2.5.5). So while security.txt is served, the date is required and must be after today
 * (UTC, the zone SecurityTxtBuilder writes it in). It is stored as YYYY-MM-DD, the form the admin's
 * calendar gives; SecurityTxtBuilder writes it as the end of that day.
 */
class SecurityTxtExpires extends Value
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param TimezoneInterface $timezone
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param mixed[] $data
     */
    public function __construct(
        Context                            $context,
        Registry                           $registry,
        ScopeConfigInterface               $config,
        TypeListInterface                  $cacheTypeList,
        private readonly TimezoneInterface $timezone,
        ?AbstractResource                  $resource = null,
        ?AbstractDb                        $resourceCollection = null,
        array                              $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Refuse a missing, malformed or past date; store the date trimmed.
     *
     * @throws LocalizedException
     * @return $this
     */
    public function beforeSave()
    {
        $value = trim((string) $this->getValue());

        if ($value === '') {
            if ((string) $this->getFieldsetDataValue('enabled') === '1') {
                throw new LocalizedException(__('security.txt needs an Expires date: RFC 9116 requires one.'));
            }
        } elseif (!$this->isDate($value)) {
            throw new LocalizedException(
                __('The security.txt Expires date is not a date: %1. Choose one from the calendar.', $value)
            );
        } elseif ($value <= $this->timezone->date(null, null, false)->format('Y-m-d')) {
            throw new LocalizedException(__(
                'The security.txt Expires date must be after today: after it, the file is stale and should'
                . ' not be used (RFC 9116).'
            ));
        }

        $this->setValue($value);

        return parent::beforeSave();
    }

    /**
     * Whether the value is a real calendar date written YYYY-MM-DD.
     *
     * @param string $value
     * @return bool
     */
    private function isDate(string $value): bool
    {
        // The round trip refuses what createFromFormat() would roll over: 2027-02-30 is read as
        // 2027-03-02, which does not write back as 2027-02-30.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
