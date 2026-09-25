<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Config;
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
     * @param Config $seoConfig
     * @param DateTime $dateTime
     * @param OfferEnricherPool $offerEnricherPool
     * @param ChildProducts $childProducts
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly CurrencyService       $currencyService,
        private readonly AvailabilityResolver  $availabilityResolver,
        private readonly Config                $seoConfig,
        private readonly DateTime              $dateTime,
        private readonly OfferEnricherPool     $offerEnricherPool,
        private readonly ChildProducts         $childProducts
    ) {
    }

    /**
     * Build the offer for a product at a URL.
     *
     * An `Offer` with the product's price; for a configurable whose sellable children are priced
     * differently, an `AggregateOffer` from the lowest to the highest.
     *
     * @param ProductInterface $product
     * @param string $url The URL the offer is made at: the product's page, or a variant's
     * @return mixed[]
     */
    public function build(ProductInterface $product, string $url): array
    {
        $offer = [
            '@type'         => 'Offer',
            'url'           => $url,
            'price'         => $this->resolvePrice($product),
            'priceCurrency' => $this->currencyService->getCurrentCurrencyCode(),
            'availability'  => $this->availabilityResolver->resolve($product),
        ];

        // itemCondition is deliberately NOT hardcoded here: ItemConditionEnricher adds
        // it from configuration, so used-goods stores can change or clear it.
        $priceValidUntil = $this->getPriceValidUntil($product);
        if ($priceValidUntil !== null && $priceValidUntil !== '') {
            $offer['priceValidUntil'] = $priceValidUntil;
        }

        $priceRange = $this->resolvePriceRange($product);
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
     * Resolve the scalar price value: the product's final price, in the display currency.
     *
     * @param ProductInterface $product
     * @return string
     */
    private function resolvePrice(ProductInterface $product): string
    {
        return number_format($this->currencyService->convertFromBase($this->finalPrice($product)), 2, '.', '');
    }

    /**
     * Resolve a low/high price range for a configurable whose children differ in price, or null.
     *
     * Read from the sellable children's own final prices — the same basis resolvePrice() uses
     * for one product. (Core's `FinalPrice::getMinimalPrice()` reads `minimal_price`, which only
     * a collection load with price data sets: on a product page it is 0.) Null keeps the
     * single Offer: any other product type, fewer than two children, or one price for all.
     *
     * @param ProductInterface $product
     * @return array{low: float, high: float}|null
     */
    private function resolvePriceRange(ProductInterface $product): ?array
    {
        $prices = array_map(
            fn (ProductInterface $child): float => $this->finalPrice($child),
            $this->childProducts->get($product)
        );
        if (\count($prices) < 2) {
            return null;
        }

        $min = min($prices);
        $max = max($prices);
        if ($min >= $max) {
            return null;
        }

        // PriceInfo amounts are base currency; convert so lowPrice/highPrice match
        // the display currency code emitted alongside them.
        return [
            'low'  => $this->currencyService->convertFromBase($min),
            'high' => $this->currencyService->convertFromBase($max),
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
        $offer['lowPrice']  = number_format($range['low'], 2, '.', '');
        $offer['highPrice'] = number_format($range['high'], 2, '.', '');

        return $offer;
    }

    /**
     * Resolve priceValidUntil
     *
     * Resolve priceValidUntil: the real special-price end date when one is active,
     * otherwise a synthetic "today + N months" window (omitted when N is 0).
     *
     * @param ProductInterface $product
     * @return string|null ISO 8601 date string (Y-m-d), or null to omit the property
     */
    private function getPriceValidUntil(ProductInterface $product): ?string
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $specialTo = substr((string) $product->getData('special_to_date'), 0, 10);
        $today     = $this->dateTime->date('Y-m-d');
        if ($specialTo !== '' && $specialTo >= $today) {
            return $specialTo;
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $months  = $this->seoConfig->getPriceValidUntilMonths($storeId);
        if ($months <= 0) {
            // Merchants set 0 to omit the synthetic date rather than promise
            // a price validity that has no basis in real pricing data.
            return null;
        }

        // Store-timezone-aware (Stdlib DateTime::date applies the store offset).
        return $this->dateTime->date('Y-m-d', "+{$months} months");
    }

    /**
     * The product's final price, in base currency.
     *
     * @param ProductInterface $product
     * @return float
     */
    private function finalPrice(ProductInterface $product): float
    {
        /** @var \Magento\Catalog\Model\Product $product */
        return (float) $product->getPriceInfo()->getPrice('final_price')->getValue();
    }
}
