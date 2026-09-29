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
            ['value' => 'Organization',            'label' => 'Organization (generic)'],
            ['value' => 'Corporation',             'label' => 'Corporation'],
            ['value' => 'LocalBusiness',           'label' => 'Local Business'],
            ['value' => 'NGO',                     'label' => 'NGO / Charity'],
            ['value' => 'EducationalOrganization', 'label' => 'Educational Organization'],
            ['value' => 'GovernmentOrganization',  'label' => 'Government Organization'],
        ];
    }
}
