<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Product;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * A product's base image, or for a configurable without one, its first sold variant's.
 *
 * Core's own fallback, `Product::getImage()`, loads all of a configurable's variants for it, gallery
 * included, one configurable at a time. This reads the variants a page prefetch already loaded
 * (OfferSummaryInterface::prefetch()), and takes only a variant the storefront sells.
 *
 * @api
 */
interface VariantImageInterface
{
    /**
     * The image's path under the product media directory (`/m/u/mug.jpg`), or null when none.
     *
     * @param ProductInterface $product
     * @return string|null
     */
    public function forProduct(ProductInterface $product): ?string;
}
