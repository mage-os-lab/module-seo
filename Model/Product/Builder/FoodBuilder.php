<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;

class FoodBuilder extends AbstractBuilder
{
    /**
     * @inheritdoc
     */
    public function getTemplateCode(): string
    {
        return 'Food';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Food Product';
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'brand'                => 'Brand / Producer',
            'gtin13'               => 'GTIN / EAN',
            'nutritionInformation' => 'Nutrition Information',
            'containsAllergen'     => 'Allergens',
            'isAlcoholicBeverage'  => 'Alcoholic Beverage',
            'countryOfOrigin'      => 'Country of Origin',
            'weight'               => 'Weight / Volume',
            'material'             => 'Ingredients',
        ];
    }

    /**
     * @inheritdoc
     */
    public function build(
        ProductInterface $product,
        array            $enabledFields,
        array            $overrides,
        array            $variantData
    ): array {
        $schema = $this->buildBase($product, $variantData);

        if (\in_array('brand', $enabledFields, true)) {
            $brand = $overrides['brand'] ?? $this->attr($product, 'manufacturer') ?: $this->attr($product, 'brand');
            if ($brand !== '') {
                $schema['brand'] = ['@type' => 'Brand', 'name' => $brand];
            }
        }

        if (\in_array('gtin13', $enabledFields, true)) {
            $gtin = $overrides['gtin13'] ?? $this->attr($product, 'barcode') ?: $this->attr($product, 'ean');
            if ($gtin !== '') {
                $schema['gtin13'] = $gtin;
            }
        }

        if (\in_array('nutritionInformation', $enabledFields, true)) {
            $raw = $overrides['nutritionInformation'] ?? $this->attr($product, 'nutrition_info');
            if ($raw !== '') {
                $decoded = \is_array($raw) ? $raw : json_decode($raw, true);
                if (\is_array($decoded)) {
                    $nutrition = ['@type' => 'NutritionInformation'];
                    foreach ($decoded as $k => $v) {
                        $nutrition[$k] = $v;
                    }
                    $schema['nutritionInformation'] = $nutrition;
                } else {
                    $schema['nutritionInformation'] = $raw;
                }
            }
        }

        if (\in_array('containsAllergen', $enabledFields, true)) {
            $allergens = $overrides['containsAllergen'] ?? $this->attr($product, 'allergens');
            if ($allergens !== '') {
                $schema['containsAllergen'] = \is_array($allergens)
                    ? $allergens
                    : array_filter(array_map('trim', explode(',', $allergens)));
            }
        }

        if (\in_array('isAlcoholicBeverage', $enabledFields, true)) {
            $isAlcohol = $overrides['isAlcoholicBeverage'] ?? $this->attr($product, 'is_alcoholic_beverage');
            if ($isAlcohol !== '') {
                $schema['isAlcoholicBeverage'] = filter_var($isAlcohol, FILTER_VALIDATE_BOOLEAN);
            }
        }

        if (\in_array('countryOfOrigin', $enabledFields, true)) {
            $origin = $overrides['countryOfOrigin'] ?? $this->attr($product, 'country_of_origin');
            if ($origin !== '') {
                $schema['countryOfOrigin'] = $origin;
            }
        }

        if (\in_array('weight', $enabledFields, true)) {
            $weight = $overrides['weight'] ?? $this->attr($product, 'weight');
            if ($weight !== '') {
                $schema['weight'] = $weight;
            }
        }

        // ingredients stored as material attribute
        if (\in_array('material', $enabledFields, true)) {
            $ingredients = $overrides['material']
                ?? $this->attr($product, 'ingredients')
                ?: $this->attr($product, 'material');
            if ($ingredients !== '') {
                $schema['material'] = $ingredients;
            }
        }

        return $this->applyOverrides($schema, $overrides);
    }

    /**
     * @inheritdoc
     */
    protected function getSchemaType(): string
    {
        return 'FoodProduct';
    }
}
