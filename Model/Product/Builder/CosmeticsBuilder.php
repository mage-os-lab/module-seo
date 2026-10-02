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
        return (string) __('Beauty & Cosmetics');
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'brand'          => (string) __('Brand'),
            'gtin13'         => (string) __('GTIN / EAN'),
            'color'          => (string) __('Shade / Color'),
            'material'       => (string) __('Ingredients'),
            'scent'          => (string) __('Scent / Fragrance'),
            'gender'         => (string) __('Target Audience'),
            'warning'        => (string) __('Warnings / Allergen Notice'),
            'weight'         => (string) __('Weight / Volume'),
            'countryOfOrigin' => (string) __('Country of Origin'),
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
