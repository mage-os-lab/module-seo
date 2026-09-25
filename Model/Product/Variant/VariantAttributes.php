<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Variant;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The attributes a configurable product's variants differ by: its own configurable attributes.
 *
 * There is no attribute map to maintain. Google's `variesBy` accepts six properties only — color,
 * size, suggestedAge, suggestedGender, material, pattern — so an attribute whose code names one of
 * them (case and underscores aside: `suggested_age` is suggestedAge) is written as that property and
 * listed in `variesBy`; every other configurable attribute is written as an `additionalProperty`
 * and left out of `variesBy`, where Google would reject it.
 *
 * Kept for the request: the URL resolver asks once per variant.
 */
class VariantAttributes implements ResetAfterRequestInterface
{
    /**
     * Attribute code, lower-cased without underscores => the schema.org property it is written as.
     */
    private const PROPERTIES = [
        'color'           => 'color',
        'size'            => 'size',
        'material'        => 'material',
        'pattern'         => 'pattern',
        'suggestedage'    => 'suggestedAge',
        'suggestedgender' => 'suggestedGender',
    ];

    /**
     * @var array<string, VariantAttribute[]> "{product ID}_{store ID}" => attributes
     */
    private array $attributes = [];

    /**
     * The configurable's attributes, in their configured order; empty for any other product type.
     *
     * @param ProductInterface $product
     * @return VariantAttribute[]
     */
    public function forProduct(ProductInterface $product): array
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $type = $product->getTypeInstance();
        if ($product->getTypeId() !== Configurable::TYPE_CODE || !$type instanceof Configurable) {
            return [];
        }

        $storeId = (int) $product->getStoreId();
        $key     = $product->getId() . '_' . $storeId;
        if (isset($this->attributes[$key])) {
            return $this->attributes[$key];
        }

        $attributes = [];
        foreach ($type->getConfigurableAttributes($product) as $configurableAttribute) {
            $attribute = $configurableAttribute->getProductAttribute();
            if ($attribute === null) {
                continue;
            }
            // Store-view labels, as core's own configurable options read them.
            $attribute->setStoreId($storeId);

            $optionLabels = [];
            foreach ($attribute->getSource()->getAllOptions(false) as $option) {
                $optionLabels[(string) $option['value']] = (string) $option['label'];
            }

            $code         = (string) $attribute->getAttributeCode();
            $attributes[] = new VariantAttribute(
                $code,
                (string) $attribute->getStoreLabel($storeId),
                self::PROPERTIES[str_replace('_', '', strtolower($code))] ?? null,
                $optionLabels
            );
        }

        return $this->attributes[$key] = $attributes;
    }

    /**
     * Drop the attributes kept for the request, between worker-mode requests.
     *
     * @return void
     */
    public function _resetState(): void // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- framework interface
    {
        $this->attributes = [];
    }
}
