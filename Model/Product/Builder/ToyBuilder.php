<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;

class ToyBuilder extends AbstractBuilder
{
    /**
     * @inheritdoc
     */
    public function getTemplateCode(): string
    {
        return 'Toy';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Toy & Game';
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'brand'             => 'Brand',
            'gtin13'            => 'GTIN / EAN',
            'suggestedAge'      => 'Suggested Minimum Age (years)',
            'suggestedMaxAge'   => 'Suggested Maximum Age (years)',
            'playerCount'       => 'Number of Players',
            'material'          => 'Material',
            'color'             => 'Colour',
            'batteriesRequired' => 'Batteries Required',
            'warning'           => 'Safety Warning',
        ];
    }

    /**
     * @inheritdoc
     */
    public function build(ProductInterface $product, array $enabledFields, array $overrides, array $variantData): array
    {
        $schema = $this->buildBase($product, $variantData);

        if (\in_array('brand', $enabledFields, true)) {
            $brand = $overrides['brand'] ?? $this->attr($product, 'manufacturer') ?: $this->attr($product, 'brand');
            if ($brand !== '') {
                $schema['brand'] = ['@type' => 'Brand', 'name' => $brand];
            }
        }

        if (\in_array('gtin13', $enabledFields, true)) {
            $gtin = $overrides['gtin13'] ?? $this->attr($product, 'barcode');
            if ($gtin !== '') {
                $schema['gtin13'] = $gtin;
            }
        }

        $ageMin = $overrides['suggestedAge'] ?? $this->attr($product, 'min_age');
        $ageMax = $overrides['suggestedMaxAge'] ?? $this->attr($product, 'max_age');

        if ((\in_array('suggestedAge', $enabledFields, true) && $ageMin !== '') ||
            (\in_array('suggestedMaxAge', $enabledFields, true) && $ageMax !== '')) {
            $audience = ['@type' => 'PeopleAudience'];
            if ($ageMin !== '') {
                $audience['suggestedMinAge'] = (float) $ageMin;
            }
            if ($ageMax !== '') {
                $audience['suggestedMaxAge'] = (float) $ageMax;
            }
            $schema['audience'] = $audience;
        }

        foreach (['material' => 'material', 'color' => 'color', 'warning' => 'safety_warning'] as $field => $attrCode) {
            if (!\in_array($field, $enabledFields, true)) {
                continue;
            }
            $value = $overrides[$field] ?? $this->attr($product, $attrCode);
            if ($value !== '') {
                $schema[$field] = $value;
            }
        }

        if (\in_array('batteriesRequired', $enabledFields, true)) {
            $batteries = $overrides['batteriesRequired'] ?? $this->attr($product, 'batteries_required');
            if ($batteries !== '') {
                $schema['batteriesRequired'] = filter_var($batteries, FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $this->applyOverrides($schema, $overrides);
    }
}
