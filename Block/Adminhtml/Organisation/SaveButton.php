<?php

declare(strict_types=1);

namespace MageOS\Seo\Block\Adminhtml\Organisation;

use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class SaveButton implements ButtonProviderInterface
{
    /**
     * Get save button configuration data.
     *
     * @return mixed[]
     */
    public function getButtonData(): array
    {
        return [
            'label'          => __('Save'),
            'class'          => 'save primary',
            'data_attribute' => [
                'mage-init' => ['button' => ['event' => 'save']],
                'form-role' => 'save',
            ],
            'sort_order'     => 90,
        ];
    }
}
