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
        array            $overrides
    ): array {
        $schema = $this->buildBase($product);

        // Brand — the override, else the manufacturer or brand attribute.
        if (\in_array('brand', $enabledFields, true)) {
            $brand = $overrides['brand'] ?? $this->attr($product, 'manufacturer') ?: $this->attr($product, 'brand');
            if ($brand !== '') {
                $schema['brand'] = ['@type' => 'Brand', 'name' => $brand];
            }
        }

        // GTIN goes through validation: the matching gtin8/12/13/14 property is
        // emitted only when the value's length and GS1 check digit are valid.
        if (\in_array('gtin13', $enabledFields, true)) {
            $gtin = (string) ($overrides['gtin13'] ?? '');
            if ($gtin === '') {
                $gtin = $this->attr($product, 'gtin13')
                    ?: $this->attr($product, 'barcode')
                    ?: $this->attr($product, 'ean');
            }
            if ($gtin !== '') {
                $schema = $this->applyGtin($schema, $gtin);
            }
        }

        $optionalScalarFields = [
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
