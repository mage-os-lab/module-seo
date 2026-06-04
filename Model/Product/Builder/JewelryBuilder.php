<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;

class JewelryBuilder extends AbstractBuilder
{
    /**
     * @inheritdoc
     */
    public function getTemplateCode(): string
    {
        return 'Jewelry';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Jewelry & Accessories';
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'brand'    => 'Brand / Maker',
            'gtin13'   => 'GTIN / EAN',
            'material' => 'Metal / Material',
            'color'    => 'Colour / Finish',
            'size'     => 'Ring / Bracelet Size',
            'pattern'  => 'Gemstone / Design',
            'weight'   => 'Weight',
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

        if (\in_array('material', $enabledFields, true)) {
            $material = $overrides['material'] ?? $this->attr($product, 'metal') ?: $this->attr($product, 'material');
            if ($material !== '') {
                $schema['material'] = $material;
            }
        }

        if (\in_array('color', $enabledFields, true)) {
            $color = $overrides['color'] ?? $variantData['color'] ?? $this->attr($product, 'color');
            if ($color !== '') {
                $schema['color'] = $color;
            }
        }

        if (\in_array('size', $enabledFields, true)) {
            $size = $overrides['size']
                ?? $variantData['size']
                ?? $this->attr($product, 'ring_size')
                ?: $this->attr($product, 'size');
            if ($size !== '') {
                $schema['size'] = $size;
            }
        }

        if (\in_array('pattern', $enabledFields, true)) {
            $pattern = $overrides['pattern'] ?? $this->attr($product, 'gemstone') ?: $this->attr($product, 'pattern');
            if ($pattern !== '') {
                $schema['pattern'] = $pattern;
            }
        }

        if (\in_array('weight', $enabledFields, true)) {
            $weight = $overrides['weight'] ?? $this->attr($product, 'weight');
            if ($weight !== '') {
                $schema['weight'] = $weight;
            }
        }

        return $this->applyOverrides($schema, $overrides);
    }
}
