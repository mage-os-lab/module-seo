<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class RobotsMeta implements OptionSourceInterface
{
    /**
     * Return robots meta options for system.xml select fields.
     *
     * @return mixed[]
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'INDEX,FOLLOW',   'label' => 'INDEX, FOLLOW'],
            ['value' => 'NOINDEX,FOLLOW', 'label' => 'NOINDEX, FOLLOW'],
            ['value' => 'INDEX,NOFOLLOW', 'label' => 'INDEX, NOFOLLOW'],
            ['value' => 'NOINDEX,NOFOLLOW', 'label' => 'NOINDEX, NOFOLLOW'],
        ];
    }
}
