<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * schema.org ReturnFeesEnumeration values for MerchantReturnPolicy returnFees.
 */
class ReturnFees implements OptionSourceInterface
{
    /**
     * Options mapping admin labels to schema.org enum URLs.
     *
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'https://schema.org/FreeReturn', 'label' => (string) __('Free return')],
            [
                'value' => 'https://schema.org/ReturnFeesCustomerResponsibility',
                'label' => (string) __('Customer pays return shipping'),
            ],
            ['value' => 'https://schema.org/ReturnShippingFees', 'label' => (string) __('Return shipping fees apply')],
        ];
    }
}
