<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;

class ElectronicsSimpleBuilder extends AbstractBuilder
{
    /**
     * @inheritdoc
     */
    public function getTemplateCode(): string
    {
        return 'ElectronicsSimple';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Electronics & Gadgets';
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'brand'   => 'Brand',
            'gtin13'  => 'GTIN / EAN',
            'mpn'     => 'Manufacturer Part Number (MPN)',
            'color'   => 'Colour',
            'material' => 'Material',
            'weight'  => 'Weight',
            'model'   => 'Model Number',
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

        // GTIN is validated (GS1 check digit) before emission; anything that does
        // not validate is omitted, so a free-form barcode attribute never produces
        // an invalid gtin13 property.
        if (\in_array('gtin13', $enabledFields, true)) {
            $gtin = $overrides['gtin13'] ?? '';
            if ($gtin === '') {
                foreach (['barcode', 'ean', 'gtin'] as $code) {
                    $gtin = $this->attr($product, $code);
                    if ($gtin !== '') {
                        break;
                    }
                }
            }
            if ($gtin !== '') {
                $schema = $this->applyGtin($schema, (string) $gtin);
            }
        }

        $simpleFields = [
            'mpn'     => ['mpn'],
            'color'   => ['color'],
            'material' => ['material'],
            'weight'  => ['weight'],
            'model'   => ['model', 'model_number'],
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
