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
        return (string) __('Home & Decor');
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'brand'          => (string) __('Brand / Maker'),
            'gtin13'         => (string) __('GTIN / EAN'),
            'color'          => (string) __('Color'),
            'material'       => (string) __('Material'),
            'pattern'        => (string) __('Pattern / Style'),
            'width'          => (string) __('Width'),
            'height'         => (string) __('Height'),
            'depth'          => (string) __('Depth / Length'),
            'weight'         => (string) __('Weight'),
            'countryOfOrigin' => (string) __('Country of Origin'),
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
            $gtin = $overrides['gtin13'] ?? $this->attr($product, 'barcode');
            if ($gtin !== '') {
                $schema = $this->applyGtin($schema, (string) $gtin);
            }
        }

        foreach (['color', 'material', 'pattern'] as $field) {
            if (!\in_array($field, $enabledFields, true)) {
                continue;
            }
            $value = $overrides[$field] ?? $this->attr($product, $field);
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
