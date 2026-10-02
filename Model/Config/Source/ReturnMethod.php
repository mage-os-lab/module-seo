<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * schema.org ReturnMethodEnumeration values for MerchantReturnPolicy returnMethod.
 */
class ReturnMethod implements OptionSourceInterface
{
    /**
     * Options mapping admin labels to schema.org enum URLs.
     *
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'https://schema.org/ReturnByMail',  'label' => (string) __('By mail')],
            ['value' => 'https://schema.org/ReturnInStore', 'label' => (string) __('In store')],
            ['value' => 'https://schema.org/ReturnAtKiosk', 'label' => (string) __('At kiosk')],
        ];
    }
}
