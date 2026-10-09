<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Product\Variant\ChildProducts;
use MageOS\Seo\Service\CurrencyService;

/**
 * Builds the schema.org offer for a product at a URL.
 *
 * The one place an offer is assembled. The page's product node and every variant in a
 * ProductGroup's `hasVariant` list take their offer from here, so price, availability,
 * `priceValidUntil` and the offer enrichers (shipping, returns, item condition) cannot differ
 * between them. To add to every offer, register an `Api\OfferEnricherInterface` in
 * `Model\Product\OfferEnricher\Pool`; to change one, plug in to build().
 *
 * A product whose price is not known (Model\Product\FinalPrice) gets no offer: build() returns an
 * empty array and the caller leaves `offers` out. Google requires a price on every Offer and reads
 * 0 as free, so neither an Offer without a price nor one priced 0 would be true.
 */
class OfferBuilder
{
    /**
     * All collaborators are required: Magento's ObjectManager passes the default value for
     * optional constructor parameters unless di.xml configures them per consumer, so an optional
     * pool with a "?? new Pool()" fallback silently loses every enricher registered in di.xml.
     *
     * @param StoreManagerInterface $storeManager
     * @param CurrencyService $currencyService
     * @param AvailabilityResolver $availabilityResolver
     * @param OfferEnricherPool $offerEnricherPool
     * @param ChildProducts $childProducts
     * @param FinalPrice $finalPrice
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly CurrencyService       $currencyService,
        private readonly AvailabilityResolver  $availabilityResolver,
        private readonly OfferEnricherPool     $offerEnricherPool,
        private readonly ChildProducts         $childProducts,
        private readonly FinalPrice            $finalPrice
    ) {
    }

    /**
     * Build the offer for a product at a URL.
     *
     * An `Offer` with the product's price; for a configurable whose sellable children are priced
     * differently, an `AggregateOffer` from the lowest to the highest. An empty array when the
     * price is not known: the caller leaves `offers` out.
     *
     * @param ProductInterface $product
     * @param string $url The URL the offer is made at: the product's page, or a variant's
     * @return mixed[]
     */
    public function build(ProductInterface $product, string $url): array
    {
        $price = $this->finalPrice->get($product);
        if ($price === null) {
            return [];
        }

        // A configurable is as available as its best child: the children are loaded for the price
        // range anyway, and its own index row says "in stock" even when only backorders sell.
        $children = $this->childProducts->get($product);
        $offer = [
            '@type'         => 'Offer',
            'url'           => $url,
            'price'         => $this->currencyService->formatAmountForLlms($price),
            'priceCurrency' => $this->currencyService->getCurrentCurrencyCode(),
            'availability'  => $children !== []
                ? $this->availabilityResolver->best($children)
                : $this->availabilityResolver->resolve($product),
        ];

        // itemCondition is deliberately NOT hardcoded here: ItemConditionEnricher adds
        // it from configuration, so used-goods stores can change or clear it.
        $priceValidUntil = $this->getPriceValidUntil($product);
        if ($priceValidUntil !== null && $priceValidUntil !== '') {
            $offer['priceValidUntil'] = $priceValidUntil;
        }

        $priceRange = $this->resolvePriceRange($children);
        if ($priceRange !== null) {
            $offer = $this->buildAggregateOffer($offer, $priceRange);
        }

        // Offer enrichment: shipping, returns, item condition, … (pluggable pool).
        $storeId        = (int) $this->storeManager->getStore()->getId();
        $offerAdditions = $this->offerEnricherPool->enrich($product, $storeId);
        if (!empty($offerAdditions)) {
            $offer = array_merge($offer, $offerAdditions);
        }

        return $offer;
    }

    /**
     * Resolve a low/high price range for a configurable whose children differ in price, or null.
     *
     * Read from the sellable children's own final prices — the same basis as the product's own
     * price. (Core's `FinalPrice::getMinimalPrice()` reads `minimal_price`, which only a
     * collection load with price data sets: on a product page it is 0.) A child whose price is not
     * known is left out of the range. Null keeps the single Offer: any other product type, fewer
     * than two priced children, or one price for all.
     *
     * @param ProductInterface[] $children The configurable's sellable children (Variant\ChildProducts)
     * @return array{low: float, high: float}|null
     */
    private function resolvePriceRange(array $children): ?array
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
        if ($min >= $max) {
            return null;
        }

        // FinalPrice gives the amount the page shows, already in the current (display) currency.
        // Converting again would apply the rate twice.
        return [
            'low'  => $min,
            'high' => $max,
        ];
    }

    /**
     * Convert a single Offer node into an AggregateOffer with low/high price.
     *
     * Preserves currency, availability, url and any other Offer fields; replaces the scalar price
     * with lowPrice/highPrice.
     *
     * @param mixed[] $offer
     * @param float[] $range
     * @return mixed[]
     */
    private function buildAggregateOffer(array $offer, array $range): array
    {
        $offer['@type'] = 'AggregateOffer';
        unset($offer['price']);
        $offer['lowPrice']  = $this->currencyService->formatAmountForLlms($range['low']);
        $offer['highPrice'] = $this->currencyService->formatAmountForLlms($range['high']);

        return $offer;
    }

    /**
     * Resolve priceValidUntil: the end date of the special price the offer publishes.
     *
     * Nothing is made up. Only a special price that is in effect today and is the price published has its end date
     * published (issue #31). Core's SpecialPrice decides "in effect": its getValue() is false
     * outside the special's dates, by core's own date rules, so a special that has ended or not yet
     * begun gives none. A special beaten by a lower price, a catalog rule's say, is not the price
     * published, so its end date is not that price's. The two are compared as core holds them
     * (getValue(), before tax and other display adjustments), to the currency's two decimals.
     *
     * Without a special_to_date there is no date: a synthetic "N months from today" would promise a
     * validity nothing backs, and Google accepts an Offer without one.
     *
     * @param ProductInterface $product
     * @return string|null ISO 8601 date string (Y-m-d), or null to omit the property
     */
    private function getPriceValidUntil(ProductInterface $product): ?string
    {
        /** @var \Magento\Catalog\Model\Product $product */
        try {
            $priceInfo = $product->getPriceInfo();
            $special   = $priceInfo->getPrice('special_price')->getValue();
            $final     = $priceInfo->getPrice('final_price')->getValue();
        } catch (\Exception) {
            // The price itself was read (FinalPrice logs its own failures); no date is no claim.
            return null;
        }
        if ($special === false || $special === null
            || $this->currencyService->formatAmountForLlms((float) $special)
                !== $this->currencyService->formatAmountForLlms((float) $final)
        ) {
            return null;
        }

        $specialTo = substr((string) $product->getData('special_to_date'), 0, 10);

        return $specialTo !== '' ? $specialTo : null;
    }
}
