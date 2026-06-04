<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class OrgType implements OptionSourceInterface
{
    /**
     * Return schema.org organisation type options.
     *
     * @return mixed[]
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'Organization',      'label' => 'Organization (generic)'],
            ['value' => 'Corporation',        'label' => 'Corporation'],
            ['value' => 'LocalBusiness',      'label' => 'Local Business'],
            ['value' => 'NGO',                'label' => 'NGO / Charity'],
            ['value' => 'EducationalOrg',     'label' => 'Educational Organization'],
            ['value' => 'GovernmentOrg',      'label' => 'Government Organization'],
        ];
    }
}
