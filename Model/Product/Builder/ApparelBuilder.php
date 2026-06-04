<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;

class ApparelBuilder extends AbstractBuilder
{
    /**
     * @inheritdoc
     */
    public function getTemplateCode(): string
    {
        return 'Apparel';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Clothing & Apparel';
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'brand'          => 'Brand',
            'gtin13'         => 'GTIN / EAN',
            'color'          => 'Colour',
            'size'           => 'Size',
            'material'       => 'Material / Fabric',
            'gender'         => 'Gender / Target Audience',
            'pattern'        => 'Pattern',
            'countryOfOrigin' => 'Country of Origin',
            'weight'         => 'Weight',
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

        // Color — prefer active variant, then attribute
        if (\in_array('color', $enabledFields, true)) {
            $color = $overrides['color']
                ?? $variantData['color']
                ?? $variantData['colour']
                ?? $this->attr($product, 'color')
                ?: $this->attr($product, 'colour');
            if ($color !== '') {
                $schema['color'] = $color;
                $schema['offers']['color'] = $color;
            }
        }

        // Size — prefer active variant
        if (\in_array('size', $enabledFields, true)) {
            $size = $overrides['size']
                ?? $variantData['size']
                ?? $this->attr($product, 'size');
            if ($size !== '') {
                $schema['size'] = $size;
                $schema['offers']['size'] = $size;
            }
        }

        if (\in_array('material', $enabledFields, true)) {
            $material = $overrides['material'] ?? $this->attr($product, 'material') ?: $this->attr($product, 'fabric');
            if ($material !== '') {
                $schema['material'] = $material;
            }
        }

        if (\in_array('gender', $enabledFields, true)) {
            $gender = $overrides['gender'] ?? $this->attr($product, 'gender');
            if ($gender !== '') {
                $schema['audience'] = [
                    '@type'           => 'PeopleAudience',
                    'suggestedGender' => $gender,
                ];
            }
        }

        if (\in_array('pattern', $enabledFields, true)) {
            $pattern = $overrides['pattern'] ?? $this->attr($product, 'pattern');
            if ($pattern !== '') {
                $schema['pattern'] = $pattern;
            }
        }

        if (\in_array('countryOfOrigin', $enabledFields, true)) {
            $origin = $overrides['countryOfOrigin'] ?? $this->attr($product, 'country_of_origin');
            if ($origin !== '') {
                $schema['countryOfOrigin'] = $origin;
            }
        }

        return $this->applyOverrides($schema, $overrides);
    }

    /**
     * @inheritdoc
     */
    protected function getSchemaType(): string
    {
        return 'Apparel';
    }
}
