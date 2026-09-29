<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Variant;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Pricing\Price\ConfigurableOptionsProviderInterface;

/**
 * The children a configurable product's structured data describes.
 *
 * Core's own answer, not a second query: `ConfigurableOptionsProviderInterface` is where core
 * prices a configurable from, it returns the used products after `ConfigurableOptionsFilterInterface`
 * (on the storefront, enabled children, and in-stock ones unless out-of-stock products are shown),
 * and it keeps them for the request, so the product page's own load is reused. A module that
 * narrows what the storefront sells registers a filter there, and the JSON-LD follows.
 */
class ChildProducts
{
    /**
     * @param ConfigurableOptionsProviderInterface $optionsProvider
     */
    public function __construct(
        private readonly ConfigurableOptionsProviderInterface $optionsProvider
    ) {
    }

    /**
     * The configurable's sellable children; empty for any other product type.
     *
     * @param ProductInterface $product
     * @return ProductInterface[]
     */
    public function get(ProductInterface $product): array
    {
        if ($product->getTypeId() !== Configurable::TYPE_CODE) {
            return [];
        }

        return array_values($this->optionsProvider->getProducts($product));
    }
}
