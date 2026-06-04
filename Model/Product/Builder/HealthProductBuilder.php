<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;

class HealthProductBuilder extends AbstractBuilder
{
    /**
     * @inheritdoc
     */
    public function getTemplateCode(): string
    {
        return 'HealthProduct';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Health & Wellness';
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'brand'             => 'Brand',
            'gtin13'            => 'GTIN / EAN',
            'activeIngredient'  => 'Active Ingredient(s)',
            'dosageSchedule'    => 'Dosage Schedule',
            'warning'           => 'Safety Warning / Disclaimer',
            'intendedUse'       => 'Intended Use',
            'weight'            => 'Weight / Volume',
            'countryOfOrigin'   => 'Country of Origin',
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

        $simpleFields = [
            'activeIngredient' => 'active_ingredient',
            'dosageSchedule'   => 'dosage_schedule',
            'warning'          => 'safety_warning',
            'intendedUse'      => 'intended_use',
            'weight'           => 'weight',
            'countryOfOrigin'  => 'country_of_origin',
        ];

        foreach ($simpleFields as $field => $attrCode) {
            if (!\in_array($field, $enabledFields, true)) {
                continue;
            }
            $value = $overrides[$field] ?? $this->attr($product, $attrCode);
            if ($value !== '') {
                $schema[$field] = $value;
            }
        }

        return $this->applyOverrides($schema, $overrides);
    }
}
