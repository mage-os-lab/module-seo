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
            'creator'        => 'Creator (set as an override)',
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
    public function build(ProductInterface $product, array $enabledFields, array $overrides): array
    {
        $schema = $this->buildBase($product);

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
                $schema = $this->applyGtin($schema, (string) $gtin);
            }
        }

        // creator — only from the category or product override; no attribute holds it
        if (\in_array('creator', $enabledFields, true) && !empty($overrides['creator'])) {
            $schema['creator'] = $overrides['creator'];
        }

        return $this->applyOverrides($schema, $overrides);
    }

    /**
     * @inheritdoc
     *
     * Multi-type: VisualArtwork alone is a CreativeWork subtype, which forfeits
     * Product rich-result eligibility; Product must accompany it.
     */
    protected function getSchemaType(): string|array
    {
        return ['Product', 'VisualArtwork'];
    }
}
