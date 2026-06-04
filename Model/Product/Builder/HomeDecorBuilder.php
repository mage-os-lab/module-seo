<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;

class HomeDecorBuilder extends AbstractBuilder
{
    /**
     * @inheritdoc
     */
    public function getTemplateCode(): string
    {
        return 'HomeDecor';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Home & Decor';
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'brand'          => 'Brand / Maker',
            'gtin13'         => 'GTIN / EAN',
            'color'          => 'Colour',
            'material'       => 'Material',
            'pattern'        => 'Pattern / Style',
            'width'          => 'Width',
            'height'         => 'Height',
            'depth'          => 'Depth / Length',
            'weight'         => 'Weight',
            'countryOfOrigin' => 'Country of Origin',
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
            $gtin = $overrides['gtin13'] ?? $this->attr($product, 'barcode');
            if ($gtin !== '') {
                $schema['gtin13'] = $gtin;
            }
        }

        foreach (['color', 'material', 'pattern'] as $field) {
            if (!\in_array($field, $enabledFields, true)) {
                continue;
            }
            $value = $overrides[$field] ?? $variantData[$field] ?? $this->attr($product, $field);
            if ($value !== '') {
                $schema[$field] = $value;
            }
        }

        foreach (['width', 'height', 'depth', 'weight'] as $dim) {
            if (!\in_array($dim, $enabledFields, true)) {
                continue;
            }
            $value = $overrides[$dim] ?? $this->attr($product, $dim) ?: $this->attr($product, 'rs_' . $dim);
            if ($value !== '') {
                $schema[$dim] = $value;
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
}
