<?php

declare(strict_types=1);

namespace MageOS\Seo\Api\Product;

use Magento\Catalog\Api\Data\ProductInterface;

/**
 * A product's final price as its page shows it, in the display currency, or null when it is not
 * known.
 *
 * Unknown is never written as 0: schema.org and Google read 0 as free. A lookup that threw, a price
 * below 0, a composite product nothing can price, and (unless Publish a Price of Zero is on) a price
 * of 0 are all unknown.
 *
 * @api
 */
interface FinalPriceInterface
{
    /**
     * The product's final price as the page shows it, or null when it is not known.
     *
     * @param ProductInterface $product
     * @return float|null
     */
    public function get(ProductInterface $product): ?float;
}
