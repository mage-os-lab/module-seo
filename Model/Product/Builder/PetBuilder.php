<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;

class PetBuilder extends AbstractBuilder
{
    /**
     * @inheritdoc
     */
    public function getTemplateCode(): string
    {
        return 'Pet';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Pet Products';
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'brand'                => 'Brand',
            'gtin13'               => 'GTIN / EAN',
            'targetSpecies'        => 'Target Species (dog, cat, etc.)',
            'nutritionInformation' => 'Nutrition Information',
            'material'             => 'Ingredients / Material',
            'weight'               => 'Weight',
            'color'                => 'Colour',
            'warning'              => 'Safety / Allergy Warning',
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

        // Target species and nutrition have no valid Product property (PeopleAudience
        // is for humans; nutritionInformation belongs to MenuItem/DietarySupplement),
        // so both are expressed as additionalProperty entries.
        if (\in_array('targetSpecies', $enabledFields, true)) {
            $species = $overrides['targetSpecies']
                ?? $this->attr($product, 'pet_species')
                ?: $this->attr($product, 'target_species');
            if ($species !== '') {
                $schema = $this->addAdditionalProperty($schema, 'targetSpecies', $species);
            }
        }

        if (\in_array('nutritionInformation', $enabledFields, true)) {
            $raw = $overrides['nutritionInformation'] ?? $this->attr($product, 'nutrition_info');
            if ($raw !== '') {
                $decoded = \is_array($raw) ? $raw : json_decode($raw, true);
                $schema  = $this->addAdditionalProperty(
                    $schema,
                    'nutritionInformation',
                    \is_array($decoded) ? json_encode($decoded) : $raw
                );
            }
        }

        $simpleFields = [
            'material' => ['ingredients', 'material'],
            'weight'   => ['weight'],
            'color'    => ['color'],
        ];

        foreach ($simpleFields as $field => $attrCodes) {
            if (!\in_array($field, $enabledFields, true)) {
                continue;
            }
            $value = $overrides[$field] ?? '';
            if ($value === '') {
                foreach ($attrCodes as $code) {
                    $value = $this->attr($product, $code);
                    if ($value !== '') {
                        break;
                    }
                }
            }
            if ($value !== '') {
                $schema[$field] = $value;
            }
        }

        // "warning" is not a Product property either.
        if (\in_array('warning', $enabledFields, true)) {
            $warning = $overrides['warning'] ?? $this->attr($product, 'safety_warning');
            if ($warning !== '') {
                $schema = $this->addAdditionalProperty($schema, 'safetyWarning', $warning);
            }
        }

        return $this->applyOverrides($schema, $overrides);
    }
}
