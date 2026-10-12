<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Variant;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use MageOS\Seo\Api\Product\VariantImageInterface;

/**
 * A product's base image, or for a configurable without one, its first sold variant's.
 *
 * Reads the stored attribute, never `getImage()`, whose fallback for a configurable loads all of its
 * variants. The variants are ChildProducts', so a prefetched page costs nothing more.
 */
class VariantImage implements VariantImageInterface
{
    /**
     * @param ChildProducts $childProducts
     */
    public function __construct(
        private readonly ChildProducts $childProducts
    ) {
    }

    /**
     * @inheritdoc
     */
    public function forProduct(ProductInterface $product): ?string
    {
        $own = $this->image($product);
        if ($own !== null) {
            return $own;
        }

        foreach ($this->childProducts->get($product) as $variant) {
            $image = $this->image($variant);
            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }

    /**
     * The product's own base image, or null when it has none.
     *
     * @param ProductInterface $product
     * @return string|null
     */
    private function image(ProductInterface $product): ?string
    {
        $image = $product instanceof Product ? (string) $product->getData('image') : '';

        return $image === '' || $image === 'no_selection' ? null : $image;
    }
}
