<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;

class ToolBuilder extends AbstractBuilder
{
    /**
     * @inheritdoc
     */
    public function getTemplateCode(): string
    {
        return 'Tool';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Tool & Hardware';
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'brand'       => 'Brand',
            'gtin13'      => 'GTIN / EAN',
            'mpn'         => 'MPN',
            'material'    => 'Material',
            'color'       => 'Colour',
            'weight'      => 'Weight',
            'powerSource' => 'Power Source',
            'model'       => 'Model',
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

        $simpleFields = [
            'gtin13'      => ['barcode', 'ean'],
            'mpn'         => ['mpn'],
            'material'    => ['material'],
            'color'       => ['color'],
            'weight'      => ['weight'],
            'powerSource' => ['power_source'],
            'model'       => ['model'],
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

        return $this->applyOverrides($schema, $overrides);
    }
}
