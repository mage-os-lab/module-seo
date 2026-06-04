<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;

class GenericProductBuilder extends AbstractBuilder
{
    /**
     * @inheritdoc
     */
    public function getTemplateCode(): string
    {
        return 'GenericProduct';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Generic Product';
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'gtin13'          => 'GTIN / EAN (barcode)',
            'mpn'             => 'Manufacturer Part Number (MPN)',
            'brand'           => 'Brand name',
            'color'           => 'Colour',
            'material'        => 'Material',
            'weight'          => 'Weight',
            'width'           => 'Width',
            'height'          => 'Height',
            'depth'           => 'Depth',
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

        // Brand — populated by SellersSeo bridge via overrides, or product attribute.
        if (\in_array('brand', $enabledFields, true)) {
            $brand = $overrides['brand'] ?? $this->attr($product, 'manufacturer') ?: $this->attr($product, 'brand');
            if ($brand !== '') {
                $schema['brand'] = ['@type' => 'Brand', 'name' => $brand];
            }
        }

        $optionalScalarFields = [
            'gtin13'          => ['gtin13', 'barcode', 'ean'],
            'mpn'             => ['mpn'],
            'color'           => ['color', 'colour'],
            'material'        => ['material'],
            'countryOfOrigin' => ['country_of_origin', 'country_of_manufacture'],
        ];

        foreach ($optionalScalarFields as $fieldCode => $attrCodes) {
            if (!\in_array($fieldCode, $enabledFields, true)) {
                continue;
            }
            $value = $overrides[$fieldCode] ?? '';
            if ($value === '') {
                foreach ($attrCodes as $attrCode) {
                    $value = $this->attr($product, $attrCode);
                    if ($value !== '') {
                        break;
                    }
                }
            }
            // Variant data can supply color/size
            if ($value === '' && isset($variantData[$fieldCode])) {
                $value = (string) $variantData[$fieldCode];
            }
            if ($value !== '') {
                $schema[$fieldCode] = $value;
            }
        }

        // Dimension fields go into a nested object if more than one is present
        $dimensions = [];
        foreach (['weight', 'width', 'height', 'depth'] as $dim) {
            if (!\in_array($dim, $enabledFields, true)) {
                continue;
            }
            $value = $overrides[$dim] ?? $this->attr($product, $dim) ?: $this->attr($product, 'rs_' . $dim);
            if ($value !== '') {
                $dimensions[$dim] = $value;
            }
        }
        if (!empty($dimensions)) {
            foreach ($dimensions as $dim => $val) {
                $schema[$dim] = $val;
            }
        }

        return $this->applyOverrides($schema, $overrides);
    }
}
