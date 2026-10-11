<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Product;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * The schema.org availability of products on the current website's stock, read from MSI's stock
 * index as the storefront reads it.
 *
 * @api
 */
interface AvailabilityResolverInterface
{
    public const IN_STOCK     = 'https://schema.org/InStock';
    public const OUT_OF_STOCK = 'https://schema.org/OutOfStock';
    public const BACKORDER    = 'https://schema.org/BackOrder';

    /**
     * The availability of each product, by SKU, in one lookup for them all.
     *
     * A product the stock index has no row for is OutOfStock; products without a SKU are left out.
     * When the stock cannot be read this throws: the caller decides what that means, and must not
     * publish an availability nothing backs.
     *
     * @param ProductInterface[] $products
     * @throws \Exception When the stock cannot be read
     * @return array<string, string> SKU => availability
     */
    public function resolveMany(array $products): array;
}
