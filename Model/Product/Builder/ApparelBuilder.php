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
        array            $overrides
    ): array {
        $schema = $this->buildBase($product);

        if (\in_array('brand', $enabledFields, true)) {
            $brand = $overrides['brand'] ?? $this->attr($product, 'manufacturer') ?: $this->attr($product, 'brand');
            if ($brand !== '') {
                $schema['brand'] = ['@type' => 'Brand', 'name' => $brand];
            }
        }

        if (\in_array('gtin13', $enabledFields, true)) {
            $gtin = $overrides['gtin13'] ?? $this->attr($product, 'barcode') ?: $this->attr($product, 'ean');
            if ($gtin !== '') {
                $schema = $this->applyGtin($schema, (string) $gtin);
            }
        }

        // Color — override, then attribute. Product-level only: schema.org defines
        // color/size on Product, not on Offer.
        if (\in_array('color', $enabledFields, true)) {
            $color = $overrides['color']
                ?? $this->attr($product, 'color')
                ?: $this->attr($product, 'colour');
            if ($color !== '') {
                $schema['color'] = $color;
            }
        }

        // Size — override, then attribute.
        if (\in_array('size', $enabledFields, true)) {
            $size = $overrides['size']
                ?? $this->attr($product, 'size');
            if ($size !== '') {
                $schema['size'] = $size;
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
     *
     * "Apparel" does not exist in the schema.org vocabulary; apparel items are
     * plain Products with color/size/material/pattern properties.
     */
    protected function getSchemaType(): string|array
    {
        return 'Product';
    }
}
