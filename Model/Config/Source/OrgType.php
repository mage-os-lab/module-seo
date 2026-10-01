<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class OrgType implements OptionSourceInterface
{
    /**
     * Return schema.org organization type options.
     *
     * Each value is emitted as the Organization node's @type, so each must be a schema.org type
     * name.
     *
     * @return mixed[]
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'Organization',            'label' => (string) __('Organization (generic)')],
            ['value' => 'Corporation',             'label' => (string) __('Corporation')],
            ['value' => 'LocalBusiness',           'label' => (string) __('Local Business')],
            ['value' => 'NGO',                     'label' => (string) __('NGO / Charity')],
            ['value' => 'EducationalOrganization', 'label' => (string) __('Educational Organization')],
            ['value' => 'GovernmentOrganization',  'label' => (string) __('Government Organization')],
        ];
    }
}
