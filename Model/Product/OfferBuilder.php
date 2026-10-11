<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\SchemaPriceInterface;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;

/**
 * Builds the schema.org offer for a product at a URL.
 *
 * The one place an offer is assembled. The page's product node and every variant in a
 * ProductGroup's `hasVariant` list take their offer from here, so price, availability,
 * `priceValidUntil` and the offer enrichers (shipping, returns, item condition) cannot differ
 * between them. To add to every offer, register an `Api\OfferEnricherInterface` in
 * `Model\Product\OfferEnricher\Pool`; to change one, plug in to build().
 *
 * The numbers, the price, a configurable's range and the availability, are OfferSummary's, which
 * MageOS_Aeo's llms.jsonl reads too.
 *
 * A product whose price is not known (Model\Product\FinalPrice) gets no offer: build() returns an
 * empty array and the caller leaves `offers` out. Google requires a price on every Offer and reads
 * 0 as free, so neither an Offer without a price nor one priced 0 would be true. An availability
 * that is not known, because the stock could not be read, is left out of the offer (review low
 * item, decision D8a): Google accepts an Offer without one, and OutOfStock would be a claim.
 */
class OfferBuilder
{
    /**
     * All collaborators are required: Magento's ObjectManager passes the default value for
     * optional constructor parameters unless di.xml configures them per consumer, so an optional
     * pool with a "?? new Pool()" fallback silently loses every enricher registered in di.xml.
     *
     * @param StoreManagerInterface $storeManager
     * @param SchemaPriceInterface $schemaPrice
     * @param OfferEnricherPool $offerEnricherPool
     * @param OfferSummary $offerSummary
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly SchemaPriceInterface  $schemaPrice,
        private readonly OfferEnricherPool     $offerEnricherPool,
        private readonly OfferSummary          $offerSummary
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
        $summary = $this->offerSummary->forProduct($product);
        if ($summary['price'] === null) {
            return [];
        }

        $offer = [
            '@type'         => 'Offer',
            'url'           => $url,
            'price'         => $this->schemaPrice->amount($summary['price']),
            'priceCurrency' => $this->schemaPrice->currencyCode(),
        ];
        if ($summary['availability'] !== null) {
            $offer['availability'] = $summary['availability'];
        }

        // itemCondition is deliberately NOT hardcoded here: ItemConditionEnricher adds
        // it from configuration, so used-goods stores can change or clear it.
        $priceValidUntil = $this->getPriceValidUntil($product);
        if ($priceValidUntil !== null && $priceValidUntil !== '') {
            $offer['priceValidUntil'] = $priceValidUntil;
        }

        if ($summary['lowPrice'] !== null && $summary['highPrice'] !== null) {
            $offer = $this->buildAggregateOffer($offer, $summary['lowPrice'], $summary['highPrice']);
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
     * Convert a single Offer node into an AggregateOffer with low/high price.
     *
     * Preserves currency, availability, url and any other Offer fields; replaces the scalar price
     * with lowPrice/highPrice.
     *
     * @param mixed[] $offer
     * @param float $low
     * @param float $high
     * @return mixed[]
     */
    private function buildAggregateOffer(array $offer, float $low, float $high): array
    {
        $offer['@type'] = 'AggregateOffer';
        unset($offer['price']);
        $offer['lowPrice']  = $this->schemaPrice->amount($low);
        $offer['highPrice'] = $this->schemaPrice->amount($high);

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
            || $this->schemaPrice->amount((float) $special) !== $this->schemaPrice->amount((float) $final)
        ) {
            return null;
        }

        $specialTo = substr((string) $product->getData('special_to_date'), 0, 10);

        return $specialTo !== '' ? $specialTo : null;
    }
}
