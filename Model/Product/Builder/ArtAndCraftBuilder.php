<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;

class ArtAndCraftBuilder extends AbstractBuilder
{
    /**
     * @inheritdoc
     */
    public function getTemplateCode(): string
    {
        return 'ArtAndCraft';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return 'Art & Craft / Handmade';
    }

    /**
     * @inheritdoc
     */
    public function getAvailableFields(): array
    {
        return [
            'artMedium'      => 'Art Medium (oil, watercolour, etc.)',
            'artworkSurface' => 'Surface / Support (canvas, paper, etc.)',
            'creator'        => 'Creator (overridden by SellersSeo bridge)',
            'width'          => 'Width',
            'height'         => 'Height',
            'depth'          => 'Depth',
            'material'       => 'Materials Used',
            'color'          => 'Dominant Colour(s)',
            'isBasedOn'      => 'Is Based On (for prints/reproductions)',
            'gtin13'         => 'GTIN / EAN',
        ];
    }

    /**
     * @inheritdoc
     */
    public function build(ProductInterface $product, array $enabledFields, array $overrides, array $variantData): array
    {
        $schema = $this->buildBase($product, $variantData);

        $simpleFields = [
            'artMedium'      => ['art_medium'],
            'artworkSurface' => ['artwork_surface'],
            'material'       => ['material', 'materials_used'],
            'color'          => ['color', 'dominant_color'],
            'isBasedOn'      => ['is_based_on'],
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

        foreach (['width', 'height', 'depth'] as $dim) {
            if (!\in_array($dim, $enabledFields, true)) {
                continue;
            }
            $value = $overrides[$dim] ?? $this->attr($product, $dim);
            if ($value !== '') {
                $schema[$dim] = $value;
            }
        }

        if (\in_array('gtin13', $enabledFields, true)) {
            $gtin = $overrides['gtin13'] ?? $this->attr($product, 'barcode');
            if ($gtin !== '') {
                $schema['gtin13'] = $gtin;
            }
        }

        // creator — expected to be populated by SellersSeo bridge via overrides
        // but we handle a fallback here if set manually
        if (\in_array('creator', $enabledFields, true) && !empty($overrides['creator'])) {
            $schema['creator'] = $overrides['creator'];
        }

        return $this->applyOverrides($schema, $overrides);
    }

    /**
     * @inheritdoc
     */
    protected function getSchemaType(): string
    {
        return 'VisualArtwork';
    }
}
