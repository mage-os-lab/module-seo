<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Which generator writes the sitemaps configured under Marketing → Site Map.
 */
class SitemapGenerator implements OptionSourceInterface
{
    public const MAGEOS_SEO = 'mageos_seo';
    public const MAGENTO    = 'magento';

    /**
     * The two generators.
     *
     * @return array<int,array{value:string,label:string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::MAGEOS_SEO, 'label' => (string) __('MageOS SEO')],
            ['value' => self::MAGENTO, 'label' => (string) __('Magento')],
        ];
    }
}
