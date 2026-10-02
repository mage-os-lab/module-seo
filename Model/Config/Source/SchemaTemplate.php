<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use MageOS\Seo\Model\Product\SchemaBuilderPool;
use MageOS\Seo\Model\Product\SchemaTemplateResolver;

/**
 * The registered product schema templates, for the store's Default Product Schema Template.
 *
 * GenericProduct comes first. A select shows a stored value it does not list as its first option,
 * and saving the page then stores that option. GenericProduct is what the storefront builds for a
 * code no builder is registered for (SchemaTemplateResolver), so with it first such a value is
 * shown, and kept, as what already happens.
 *
 * The category form's list adds an inherit option first: SchemaTemplate\CategoryOverride.
 */
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
     * Return the registered schema templates as an option array, GenericProduct first.
     *
     * @return mixed[]
     */
    public function toOptionArray(): array
    {
        $templates = $this->builderPool->getAvailableTemplates();
        $fallback  = SchemaTemplateResolver::FALLBACK;

        $options = [];
        if (isset($templates[$fallback])) {
            $options[] = ['value' => $fallback, 'label' => $templates[$fallback]];
            unset($templates[$fallback]);
        }
        foreach ($templates as $code => $label) {
            $options[] = ['value' => $code, 'label' => $label];
        }

        return $options;
    }
}
