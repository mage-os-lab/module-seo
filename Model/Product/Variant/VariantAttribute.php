<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Variant;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * One attribute a configurable product's variants differ by, as their JSON-LD describes it.
 */
class VariantAttribute
{
    /**
     * @param string $code The attribute code, e.g. `color`
     * @param string $label The attribute's label in the store view
     * @param string|null $property The schema.org property it is written as when it is one of the
     *                              six Google's `variesBy` accepts; null for any other attribute
     * @param array<string,string> $optionLabels Option ID => label in the store view
     */
    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly ?string $property,
        private readonly array $optionLabels
    ) {
    }

    /**
     * The option a variant has for this attribute, or '' when it has none.
     *
     * @param ProductInterface $variant
     * @return string
     */
    public function optionId(ProductInterface $variant): string
    {
        /** @var \Magento\Catalog\Model\Product $variant */
        return (string) $variant->getData($this->code);
    }

    /**
     * The label of the option a variant has for this attribute, or '' when it has none.
     *
     * @param ProductInterface $variant
     * @return string
     */
    public function optionLabel(ProductInterface $variant): string
    {
        return $this->optionLabels[$this->optionId($variant)] ?? '';
    }
}
