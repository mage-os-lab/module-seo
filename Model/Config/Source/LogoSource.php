<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class LogoSource implements OptionSourceInterface
{
    /**
     * Get logo source options array.
     *
     * @return mixed[]
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => '1', 'label' => __('Use Design Logo (from Content → Design → Configuration)')],
            ['value' => '0', 'label' => __('Upload Custom SEO Logo')],
        ];
    }
}
