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
        return (string) __('Health & Wellness');
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'brand'             => (string) __('Brand'),
            'gtin13'            => (string) __('GTIN / EAN'),
            'activeIngredient'  => (string) __('Active Ingredient(s)'),
            'dosageSchedule'    => (string) __('Dosage Schedule'),
            'warning'           => (string) __('Safety Warning / Disclaimer'),
            'intendedUse'       => (string) __('Intended Use'),
            'weight'            => (string) __('Weight / Volume'),
            'countryOfOrigin'   => (string) __('Country of Origin'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function build(ProductInterface $product, array $enabledFields, array $overrides): array
    {
        $schema = $this->buildBase($product);

        if (\in_array('brand', $enabledFields, true)) {
            $brand = $overrides['brand'] ?? $this->attr($product, 'manufacturer') ?: $this->attr($product, 'brand');
            if ($brand !== '') {
                $schema['brand'] = ['@type' => 'Brand', 'name' => $brand];
            }
        }

        if (\in_array('gtin13', $enabledFields, true)) {
            $gtin = $overrides['gtin13'] ?? $this->attr($product, 'barcode');
            if ($gtin !== '') {
                $schema = $this->applyGtin($schema, (string) $gtin);
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
