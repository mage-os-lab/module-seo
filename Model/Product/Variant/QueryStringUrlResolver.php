<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Variant;

use Magento\Catalog\Api\Data\ProductInterface;
use MageOS\Seo\Api\ProductVariantUrlResolverInterface;

/**
 * A variant's URL: the product's own URL with `?{attribute_code}={option_id}` for each configurable
 * attribute, e.g. `/tee.html?color=49&size=167`.
 *
 * The page keeps one canonical URL — core builds its canonical tag (when
 * `catalog/seo/product_canonical_tag` is on) from the product URL, not the request — and Luma's
 * swatch renderer preselects the options the query names.
 */
class QueryStringUrlResolver implements ProductVariantUrlResolverInterface
{
    /**
     * @param VariantAttributes $variantAttributes
     */
    public function __construct(
        private readonly VariantAttributes $variantAttributes
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getUrl(ProductInterface $product, ProductInterface $variant): string
    {
        $query = [];
        foreach ($this->variantAttributes->forProduct($product) as $attribute) {
            $optionId = $attribute->optionId($variant);
            if ($optionId !== '') {
                $query[$attribute->code] = $optionId;
            }
        }

        /** @var \Magento\Catalog\Model\Product $product */
        $url = (string) $product->getProductUrl();
        if ($query === []) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }
}
