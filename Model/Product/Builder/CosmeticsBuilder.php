<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;

class CosmeticsBuilder extends AbstractBuilder
{
    /**
     * @inheritdoc
     */
    public function getTemplateCode(): string
    {
        return 'Cosmetics';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Beauty & Cosmetics';
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'brand'          => 'Brand',
            'gtin13'         => 'GTIN / EAN',
            'color'          => 'Shade / Colour',
            'material'       => 'Ingredients',
            'scent'          => 'Scent / Fragrance',
            'gender'         => 'Target Audience',
            'warning'        => 'Warnings / Allergen Notice',
            'weight'         => 'Weight / Volume',
            'countryOfOrigin' => 'Country of Origin',
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
            'color'           => ['color', 'shade'],
            'material'        => ['ingredients', 'material'],
            'scent'           => ['scent', 'fragrance'],
            'warning'         => ['safety_warning', 'allergen_warning'],
            'weight'          => ['weight'],
            'countryOfOrigin' => ['country_of_origin'],
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
            if ($value === '' && isset($variantData[$field])) {
                $value = (string) $variantData[$field];
            }
            if ($value !== '') {
                $schema[$field] = $value;
            }
        }

        if (\in_array('gender', $enabledFields, true)) {
            $gender = $overrides['gender'] ?? $this->attr($product, 'gender');
            if ($gender !== '') {
                $schema['audience'] = ['@type' => 'PeopleAudience', 'suggestedGender' => $gender];
            }
        }

        return $this->applyOverrides($schema, $overrides);
    }
}
