<?php

declare(strict_types=1);

namespace MageOS\Seo\Api;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * The URL a configurable product's variant is reached at: its offer's `url` in the product JSON-LD.
 *
 * Google's product-variant guidance for products sold from one page: one canonical URL for the
 * group, and each variant reached by a query parameter that preselects it. The default,
 * `Model\Product\Variant\QueryStringUrlResolver`, appends `?{attribute_code}={option_id}` for each
 * configurable attribute — the form Luma's swatch renderer preselects options from. Replace it with
 * a di.xml preference when a theme's option widget reads something else (Luma's dropdown-only
 * widget reads the URL hash, not the query string), or when variants have pages of their own.
 *
 * @api
 */
interface ProductVariantUrlResolverInterface
{
    /**
     * Return the URL a variant is reached at.
     *
     * @param ProductInterface $product The configurable product the page is for
     * @param ProductInterface $variant One of its children
     * @return string
     */
    public function getUrl(ProductInterface $product, ProductInterface $variant): string;
}
