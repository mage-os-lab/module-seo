<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use MageOS\Seo\Api\Product\OfferSummaryInterface;
use MageOS\Seo\Model\Product\Variant\ChildProducts;

/**
 * What a product's offer says, as numbers: its price, a configurable's price range across its
 * sellable children, and its availability.
 *
 * The one place these are decided. OfferBuilder builds the product page's Offer or AggregateOffer
 * from them, and MageOS_Aeo's llms.jsonl its lines (review M4: a configurable whose children were
 * priced differently was a single Offer there, with its own index row's availability).
 */
class OfferSummary implements OfferSummaryInterface
{
    /**
     * @param FinalPrice $finalPrice
     * @param ChildProducts $childProducts
     * @param AvailabilityResolver $availabilityResolver
     */
    public function __construct(
        private readonly FinalPrice           $finalPrice,
        private readonly ChildProducts        $childProducts,
        private readonly AvailabilityResolver $availabilityResolver
    ) {
    }

    /**
     * @inheritdoc
     *
     * The configurables' variants first, then all their stock in one lookup.
     */
    public function prefetch(array $products): void
    {
        $this->childProducts->prefetch($products);

        $children = [];
        foreach ($products as $product) {
            array_push($children, ...$this->childProducts->get($product));
        }
        if ($children !== []) {
            $this->availabilityResolver->prefetch($children);
        }
    }

    /**
     * @inheritdoc
     */
    public function forProduct(ProductInterface $product): array
    {
        // A configurable is as available as its best child: the children are loaded for the price
        // range anyway, and its own index row says "in stock" even when only backorders sell.
        $children = $this->childProducts->get($product);
        $range    = $this->priceRange($children);

        return [
            'price'        => $this->finalPrice->get($product),
            'lowPrice'     => $range['low'] ?? null,
            'highPrice'    => $range['high'] ?? null,
            'availability' => $children !== []
                ? $this->availabilityResolver->best($children)
                : $this->availabilityResolver->resolve($product),
        ];
    }

    /**
     * A low/high price range for a configurable whose children differ in price, or null.
     *
     * Read from the sellable children's own final prices — the same basis as the product's own
     * price. (Core's `FinalPrice::getMinimalPrice()` reads `minimal_price`, which only a
     * collection load with price data sets: on a product page it is 0.) A child whose price is not
     * known is left out of the range. Null keeps the single price: any other product type, fewer
     * than two priced children, or one price for all. FinalPrice gives the amounts the page shows,
     * already in the display currency.
     *
     * @param ProductInterface[] $children The configurable's sellable children (Variant\ChildProducts)
     * @return array{low: float, high: float}|null
     */
    private function priceRange(array $children): ?array
    {
        $prices = array_filter(
            array_map(
                fn (ProductInterface $child): ?float => $this->finalPrice->get($child),
                $children
            ),
            static fn (?float $price): bool => $price !== null
        );
        if (\count($prices) < 2) {
            return null;
        }

        $min = min($prices);
        $max = max($prices);

        return $min < $max ? ['low' => $min, 'high' => $max] : null;
    }
}
