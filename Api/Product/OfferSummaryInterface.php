<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Product;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * What a product's offer says, as numbers: the same ones its page's structured data is built from.
 *
 * - `price`: the final price as the page shows it, in the display currency; null when not known.
 * - `lowPrice` and `highPrice`: for a configurable whose sellable children are priced differently,
 *   the lowest and highest of their prices; otherwise null, and the offer has the one price.
 * - `availability`: an AvailabilityResolverInterface value; a configurable's is its best child's.
 *   Null when the stock could not be read: leave it out rather than claim one.
 *
 * @api
 */
interface OfferSummaryInterface
{
    /**
     * The product's offer as numbers.
     *
     * @param ProductInterface $product
     * @return array{price: ?float, lowPrice: ?float, highPrice: ?float, availability: ?string}
     */
    public function forProduct(ProductInterface $product): array;

    /**
     * Read what a page of products' offers need at once, before forProduct() is asked for each.
     *
     * For a feed: the configurables' variants in one collection and their stock in one lookup,
     * where forProduct() would read each configurable's on its own. Optional, and only for a page:
     * a product page needs no call. A later call replaces the page.
     *
     * @param ProductInterface[] $products
     * @return void
     */
    public function prefetch(array $products): void;
}
