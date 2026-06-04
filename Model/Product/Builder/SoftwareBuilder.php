<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;

class SoftwareBuilder extends AbstractBuilder
{
    /**
     * @inheritdoc
     */
    public function getTemplateCode(): string
    {
        return 'Software';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Software / Digital Download';
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'brand'               => 'Brand / Publisher',
            'operatingSystem'     => 'Operating System',
            'applicationCategory' => 'Application Category',
            'softwareVersion'     => 'Version',
            'gtin13'              => 'GTIN / EAN',
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

        $simpleMap = [
            'operatingSystem'       => 'os',
            'applicationCategory'   => 'application_category',
            'softwareVersion'       => 'software_version',
        ];
        foreach ($simpleMap as $field => $attrCode) {
            if (!\in_array($field, $enabledFields, true)) {
                continue;
            }
            $value = $overrides[$field] ?? $this->attr($product, $attrCode);
            if ($value !== '') {
                $schema[$field] = $value;
            }
        }

        if (\in_array('gtin13', $enabledFields, true)) {
            $gtin = $overrides['gtin13'] ?? $this->attr($product, 'barcode');
            if ($gtin !== '') {
                $schema['gtin13'] = $gtin;
            }
        }

        return $this->applyOverrides($schema, $overrides);
    }

    /**
     * @inheritdoc
     */
    protected function getSchemaType(): string
    {
        return 'SoftwareApplication';
    }
}
