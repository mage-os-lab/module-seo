<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Config\Source\SchemaTemplate;

use MageOS\Seo\Model\Config\Source\SchemaTemplate;

/**
 * The schema templates for a single category.
 *
 * A category with no template inherits from the nearest parent category that has one, and failing
 * that uses the store's Default Product Schema Template (see Model\Product\SchemaTemplateResolver).
 */
class CategoryOverride extends SchemaTemplate
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => '', 'label' => (string) __("Inherit (parent category, then the store's default template)")],
            ...parent::toOptionArray(),
        ];
    }
}
