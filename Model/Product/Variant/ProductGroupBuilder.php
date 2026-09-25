<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Variant;

use Magento\Catalog\Api\Data\ProductInterface;
use MageOS\Seo\Api\ProductVariantUrlResolverInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\GtinValidator;
use MageOS\Seo\Model\Product\OfferBuilder;

/**
 * Describes a configurable product as a ProductGroup of its variants.
 *
 * Runs on the product node a template built, so it works for every template. A configurable with
 * between one and `has_variant_max` sellable children becomes, per Google's product-variant
 * guidance:
 *
 *  - a `ProductGroup` (beside the template's own type, e.g. `["ProductGroup", "Book"]`) with
 *    `productGroupID` = its SKU, `variesBy` and `hasVariant`, and no `offers`: offers belong to
 *    the variants. What varies is taken off the group, where a template may have set it from the
 *    parent product.
 *  - one `Product` per variant: its name, SKU, GTIN (when the template's GTIN field is enabled),
 *    image (its own, else the group's), what it varies by, and its offer from the one offer
 *    builder at the URL `Api\ProductVariantUrlResolverInterface` gives it.
 *
 * More sellable children than `has_variant_max`, or the setting at 0, leaves the node as the
 * template built it: one Product with an AggregateOffer over the whole price range, rather than a
 * variant list cut short that would misstate what the store sells.
 */
class ProductGroupBuilder
{
    /**
     * The template field whose being enabled puts a GTIN on each variant.
     */
    private const GTIN_FIELD = 'gtin13';

    /**
     * @param ChildProducts $childProducts
     * @param VariantAttributes $variantAttributes
     * @param ProductVariantUrlResolverInterface $urlResolver
     * @param OfferBuilder $offerBuilder
     * @param GtinReader $gtinReader
     * @param GtinValidator $gtinValidator
     * @param Config $seoConfig
     */
    public function __construct(
        private readonly ChildProducts                      $childProducts,
        private readonly VariantAttributes                  $variantAttributes,
        private readonly ProductVariantUrlResolverInterface $urlResolver,
        private readonly OfferBuilder                       $offerBuilder,
        private readonly GtinReader                         $gtinReader,
        private readonly GtinValidator                      $gtinValidator,
        private readonly Config                             $seoConfig
    ) {
    }

    /**
     * Return the product node as a ProductGroup of its variants, or unchanged when it is not one.
     *
     * @param mixed[] $schema The product node the template built
     * @param ProductInterface $product
     * @param string[] $enabledFields The template fields enabled for the product's category
     * @return mixed[]
     */
    public function build(array $schema, ProductInterface $product, array $enabledFields): array
    {
        $variants = $this->childProducts->get($product);
        if ($variants === [] || \count($variants) > $this->seoConfig->getHasVariantMax()) {
            return $schema;
        }

        $attributes = $this->variantAttributes->forProduct($product);
        $gtins      = \in_array(self::GTIN_FIELD, $enabledFields, true)
            ? $this->gtinReader->read($product, $variants)
            : [];
        $groupImage = $this->firstImage($schema['image'] ?? null);

        $nodes = [];
        foreach ($variants as $variant) {
            $nodes[] = $this->variant(
                $product,
                $variant,
                $attributes,
                $gtins[(int) $variant->getId()] ?? '',
                $groupImage
            );
        }

        $group = $this->group($schema, (string) $product->getSku(), $this->varyingProperties($attributes, $nodes));
        $group['hasVariant'] = $nodes;

        return $group;
    }

    /**
     * The product node as a ProductGroup, before its variants are added.
     *
     * @param mixed[] $schema
     * @param string $sku
     * @param string[] $varying The schema.org properties the variants differ by
     * @return mixed[]
     */
    private function group(array $schema, string $sku, array $varying): array
    {
        $schema['@type'] = $this->groupType($schema['@type'] ?? 'Product');
        unset($schema['offers']);
        foreach ($varying as $property) {
            $schema = $this->withoutProperty($schema, $property);
        }

        $schema['productGroupID'] = $sku;
        if ($varying !== []) {
            $schema['variesBy'] = array_map(
                static fn (string $property): string => 'https://schema.org/' . $property,
                $varying
            );
        }

        return $schema;
    }

    /**
     * The `variesBy` properties the variants were actually given, in attribute order.
     *
     * Listed from the variant nodes, not the attributes alone: an age attribute whose options are
     * not numbers is written as an additionalProperty, and variesBy must not claim it.
     *
     * @param VariantAttribute[] $attributes
     * @param array<int,mixed[]> $nodes
     * @return string[]
     */
    private function varyingProperties(array $attributes, array $nodes): array
    {
        $varying = [];
        foreach ($attributes as $attribute) {
            $property = $attribute->property;
            if ($property === null || \in_array($property, $varying, true)) {
                continue;
            }
            foreach ($nodes as $node) {
                if (isset($node[$property]) || isset($node['audience'][$property])) {
                    $varying[] = $property;
                    break;
                }
            }
        }

        return $varying;
    }

    /**
     * One variant's Product node.
     *
     * @param ProductInterface $product
     * @param ProductInterface $variant
     * @param VariantAttribute[] $attributes
     * @param string $gtin The variant's raw GTIN, '' for none
     * @param string|null $groupImage
     * @return mixed[]
     */
    private function variant(
        ProductInterface $product,
        ProductInterface $variant,
        array $attributes,
        string $gtin,
        ?string $groupImage
    ): array {
        $node = [
            '@type' => 'Product',
            'name'  => (string) $variant->getName(),
            'sku'   => (string) $variant->getSku(),
        ];
        if ($gtin !== '') {
            $node = array_merge($node, $this->gtinValidator->toProperties($gtin));
        }

        $image = $this->variantImage($variant) ?? $groupImage;
        if ($image !== null) {
            $node['image'] = $image;
        }

        $additional = [];
        foreach ($attributes as $attribute) {
            $value = $attribute->optionLabel($variant);
            if ($value === '') {
                continue;
            }

            if ($attribute->property === 'suggestedGender') {
                $node['audience'] = ($node['audience'] ?? ['@type' => 'PeopleAudience'])
                    + ['suggestedGender' => $value];
            } elseif ($attribute->property === 'suggestedAge' && is_numeric($value)) {
                // schema.org's suggestedAge is a QuantitativeValue, in years (UN/CEFACT ANN).
                $node['audience'] = ($node['audience'] ?? ['@type' => 'PeopleAudience'])
                    + ['suggestedAge' => ['@type' => 'QuantitativeValue', 'value' => 0 + $value, 'unitCode' => 'ANN']];
            } elseif ($attribute->property !== null && $attribute->property !== 'suggestedAge') {
                $node[$attribute->property] = $value;
            } else {
                // Any other attribute — and an age that is not a number ("Adult"), which no
                // QuantitativeValue can hold.
                $additional[] = ['@type' => 'PropertyValue', 'name' => $attribute->label, 'value' => $value];
            }
        }
        if ($additional !== []) {
            $node['additionalProperty'] = $additional;
        }

        $node['offers'] = $this->offerBuilder->build($variant, $this->urlResolver->getUrl($product, $variant));

        return $node;
    }

    /**
     * The group's @type: ProductGroup in Product's place, beside a template's own type.
     *
     * @param string|string[] $type
     * @return string|string[]
     */
    private function groupType(string|array $type): string|array
    {
        if ($type === 'Product') {
            return 'ProductGroup';
        }
        $types = (array) $type;
        $index = array_search('Product', $types, true);
        if ($index === false) {
            return ['ProductGroup', ...$types];
        }
        $types[$index] = 'ProductGroup';

        return array_values($types);
    }

    /**
     * Take a property that varies off the group.
     *
     * @param mixed[] $schema
     * @param string $property
     * @return mixed[]
     */
    private function withoutProperty(array $schema, string $property): array
    {
        $audienceKeys = match ($property) {
            'suggestedGender' => ['suggestedGender'],
            'suggestedAge'    => ['suggestedAge', 'suggestedMinAge', 'suggestedMaxAge'],
            default           => null,
        };
        if ($audienceKeys === null) {
            unset($schema[$property]);

            return $schema;
        }

        if (isset($schema['audience']) && \is_array($schema['audience'])) {
            $schema['audience'] = array_diff_key($schema['audience'], array_flip($audienceKeys));
            if (array_keys($schema['audience']) === ['@type']) {
                unset($schema['audience']);
            }
        }

        return $schema;
    }

    /**
     * The variant's first gallery image, or null when it has none of its own.
     *
     * @param ProductInterface $variant
     * @return string|null
     */
    private function variantImage(ProductInterface $variant): ?string
    {
        /** @var \Magento\Catalog\Model\Product $variant */
        foreach ($variant->getMediaGalleryImages() as $image) {
            $url = (string) $image->getUrl();
            if ($url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * The first of the group's images, which the node holds as one URL or a list.
     *
     * @param mixed $image
     * @return string|null
     */
    private function firstImage(mixed $image): ?string
    {
        $first = \is_array($image) ? reset($image) : $image;

        return \is_string($first) && $first !== '' ? $first : null;
    }
}
