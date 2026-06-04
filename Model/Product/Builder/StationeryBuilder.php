<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;

class StationeryBuilder extends AbstractBuilder
{
    /**
     * @inheritdoc
     */
    public function getTemplateCode(): string
    {
        return 'Stationery';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Stationery & Paper Goods';
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'brand'        => 'Brand',
            'gtin13'       => 'GTIN / EAN',
            'color'        => 'Colour',
            'material'     => 'Material / Paper Type',
            'pattern'      => 'Pattern / Design',
            'numberOfPages' => 'Number of Pages',
            'weight'       => 'Weight',
            'width'        => 'Width',
            'height'       => 'Height',
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
            'gtin13'       => ['barcode'],
            'color'        => ['color'],
            'material'     => ['material', 'paper_type'],
            'pattern'      => ['pattern'],
            'numberOfPages' => ['number_of_pages'],
            'weight'       => ['weight'],
            'width'        => ['width'],
            'height'       => ['height'],
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

        return $this->applyOverrides($schema, $overrides);
    }
}
