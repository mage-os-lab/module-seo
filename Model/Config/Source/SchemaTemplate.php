<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\Seo\Model\Product\SchemaBuilderPool;

class SchemaTemplate implements OptionSourceInterface
{
    /**
     * @param SchemaBuilderPool $builderPool
     */
    public function __construct(
        private readonly SchemaBuilderPool $builderPool
    ) {
    }

    /**
     * Return all registered schema templates as option array for dropdowns.
     *
     * @return mixed[]
     */
    public function toOptionArray(): array
    {
        $options = [['value' => '', 'label' => '-- Inherit / Use Global Default --']];

        foreach ($this->builderPool->getAvailableTemplates() as $code => $label) {
            $options[] = ['value' => $code, 'label' => $label];
        }

        return $options;
    }
}
