<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\ProductSchemaBuilderInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use MageOS\Seo\Model\Product\GtinValidator;
use MageOS\Seo\Model\Product\OfferEnricher\Pool as OfferEnricherPool;
use MageOS\Seo\Model\Review\AggregateRatingResolver;
use MageOS\Seo\Service\CurrencyService;

abstract class AbstractBuilder implements ProductSchemaBuilderInterface
{
    // Standard availability URIs (canonical values live on AvailabilityResolver).
    protected const AVAILABILITY_IN_STOCK  = AvailabilityResolver::IN_STOCK;
    protected const AVAILABILITY_OUT       = AvailabilityResolver::OUT_OF_STOCK;
    protected const AVAILABILITY_BACKORDER = AvailabilityResolver::BACKORDER;

    /**
     * All collaborators are required: Magento's ObjectManager passes the default value
     * for optional constructor parameters unless di.xml configures them per consumer,
     * so an optional pool with a "?? new Pool()" fallback silently loses every enricher
     * and provider registered in di.xml.
     *
     * @param StoreManagerInterface $storeManager
     * @param CurrencyService $currencyService
     * @param AvailabilityResolver $availabilityResolver
     * @param ImageHelper $imageHelper
     * @param Config $seoConfig
     * @param DateTime $dateTime
     * @param OfferEnricherPool $offerEnricherPool
     * @param AggregateRatingResolver $aggregateRatingResolver
     * @param GtinValidator $gtinValidator
     */
    public function __construct(
        protected readonly StoreManagerInterface  $storeManager,
        protected readonly CurrencyService        $currencyService,
        protected readonly AvailabilityResolver   $availabilityResolver,
        protected readonly ImageHelper            $imageHelper,
        protected readonly Config                 $seoConfig,
        protected readonly DateTime               $dateTime,
        protected readonly OfferEnricherPool      $offerEnricherPool,
        protected readonly AggregateRatingResolver $aggregateRatingResolver,
        protected readonly GtinValidator          $gtinValidator
    ) {
    }

    /**
     * Add a validated GTIN to the schema, or nothing when the value doesn't validate.
     *
     * Emits the property matching the value's real length (gtin8/gtin12/gtin13/gtin14)
     * after a GS1 check-digit validation, so free-form barcode attribute content never
     * produces "invalid gtin" errors in Search Console.
     *
     * @param mixed[] $schema
     * @param string $value
     * @return mixed[]
     */
    protected function applyGtin(array $schema, string $value): array
    {
        $property = $this->gtinValidator->resolveProperty($value);
        if ($property !== null) {
            $schema[$property] = (string) $this->gtinValidator->normalize($value);
        }

        return $schema;
    }

    /**
     * Build the shared base node present on all product schemas.
     *
     * Subclasses call this and then add their template-specific fields.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @return mixed[]
     */
    protected function buildBase(ProductInterface $product): array
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $store      = $this->storeManager->getStore();
        $productUrl = $product->getProductUrl();

        $price    = $this->resolvePrice($product);
        $currency = $this->currencyService->getCurrentCurrencyCode();

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => $this->getSchemaType(),
            '@id'      => $productUrl . '#product',
            'name'     => $product->getName(),
            'url'      => $productUrl,
            'sku'      => $product->getSku(),
            'offers'   => [
                '@type'            => 'Offer',
                'url'              => $productUrl,
                'price'            => $price,
                'priceCurrency'    => $currency,
                'availability'     => $this->resolveAvailability($product),
            ],
        ];

        // itemCondition is deliberately NOT hardcoded here: ItemConditionEnricher adds
        // it from configuration, so used-goods stores can change or clear it.
        $priceValidUntil = $this->getPriceValidUntil($product);
        if ($priceValidUntil !== null && $priceValidUntil !== '') {
            $schema['offers']['priceValidUntil'] = $priceValidUntil;
        }

        // Description
        $rawDesc = (string) $product->getShortDescription() ?: (string) $product->getDescription();
        $description = $this->stripHtml($rawDesc);
        if ($description !== '') {
            $schema['description'] = mb_substr($description, 0, 5000);
        }

        // Images
        $images = $this->getProductImages($product);
        if (!empty($images)) {
            $schema['image'] = \count($images) === 1 ? $images[0] : $images;
        }

        $storeId = (int) $store->getId();

        // AggregateOffer for products that expose a price range (e.g. configurable).
        $priceRange = $this->resolvePriceRange($product);
        if ($priceRange !== null) {
            $schema['offers'] = $this->buildAggregateOffer($schema['offers'], $priceRange);
        }

        // Offer enrichment: shipping, returns, item condition, … (pluggable pool).
        $offerAdditions = $this->offerEnricherPool->enrich($product, $storeId);
        if (!empty($offerAdditions)) {
            $schema['offers'] = array_merge($schema['offers'], $offerAdditions);
        }

        // Product-level AggregateRating from the (pluggable) rating provider pool.
        if ($this->seoConfig->isAggregateRatingEnabled($storeId)) {
            $rating = $this->aggregateRatingResolver->resolve((int) $product->getId(), $storeId);
            if ($rating !== null) {
                $schema['aggregateRating'] = array_merge(['@type' => 'AggregateRating'], $rating);
            }
        }

        return $schema;
    }

    /**
     * Resolve a low/high price range for products that have one (configurable), or null.
     *
     * Returns null for any product whose final price does not expose a usable minimal/maximal
     * range, so the standard single Offer is kept.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @return array{low: float, high: float}|null
     */
    protected function resolvePriceRange(ProductInterface $product): ?array
    {
        if ($product->getTypeId() !== 'configurable') {
            return null;
        }

        try {
            /** @var \Magento\Catalog\Model\Product $product */
            $finalPrice = $product->getPriceInfo()->getPrice('final_price');
            if (!method_exists($finalPrice, 'getMinimalPrice') || !method_exists($finalPrice, 'getMaximalPrice')) {
                return null;
            }
            $min = (float) $finalPrice->getMinimalPrice()->getValue();
            $max = (float) $finalPrice->getMaximalPrice()->getValue();
        } catch (\Throwable) {
            return null;
        }

        if ($min <= 0.0 || $max <= 0.0 || $min >= $max) {
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
    protected function buildAggregateOffer(array $offer, array $range): array
    {
        $offer['@type'] = 'AggregateOffer';
        unset($offer['price']);
        $offer['lowPrice']  = number_format($range['low'], 2, '.', '');
        $offer['highPrice'] = number_format($range['high'], 2, '.', '');

        return $offer;
    }

    /**
     * Return the schema.org @type for this builder.
     *
     * Subclasses may return a multi-type array like ["Product", "Book"]: Google's
     * Product rich results and merchant listings require the Product type, so a
     * category-specific type must accompany Product, never replace it.
     *
     * @return string|string[]
     */
    protected function getSchemaType(): string|array
    {
        return 'Product';
    }

    /**
     * Resolve the scalar price value: the product's final price, in the display currency.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @return string
     */
    protected function resolvePrice(ProductInterface $product): string
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $baseAmount = (float) $product->getPriceInfo()->getPrice('final_price')->getValue();

        // PriceInfo amounts are base currency; convert so the amount matches the display
        // currency code emitted with it.
        return number_format($this->currencyService->convertFromBase($baseAmount), 2, '.', '');
    }

    /**
     * Resolve schema.org availability URI: MSI salability for the current website.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @return string
     */
    protected function resolveAvailability(ProductInterface $product): string
    {
        return $this->availabilityResolver->resolve($product);
    }

    /**
     * Read a product attribute value safely, returning empty string if unset.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param string $code
     * @return string
     */
    protected function attr(ProductInterface $product, string $code): string
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $value = $product->getData($code);
        if ($value === null || $value === false || $value === '') {
            return '';
        }
        // For select attributes, resolve label
        if (is_numeric($value)) {
            try {
                $label = $product->getAttributeText($code);
                if (\is_string($label) && $label !== '') {
                    return $label;
                }
            } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
            }
        }
        return (string) $value;
    }

    /**
     * Apply overrides to a schema node. Override keys map directly to top-level schema properties.
     *
     * @param mixed[] $schema
     * @param mixed[] $overrides
     * @return mixed[]
     */
    protected function applyOverrides(array $schema, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            // GTIN overrides go through validation like attribute values, so a raw
            // override can never emit an invalid gtin property.
            if (\in_array($key, ['gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14'], true)) {
                unset($schema[$key]);
                $schema = $this->applyGtin($schema, (string) $value);
                continue;
            }
            $schema[$key] = $value;
        }
        return $schema;
    }

    /**
     * Append a schema.org additionalProperty entry (PropertyValue).
     *
     * The home for category-specific data that has no valid Product property —
     * inventing properties (batteriesRequired) or borrowing them from other types
     * (nutritionInformation, location) costs rich-result eligibility.
     *
     * @param mixed[] $schema
     * @param string $name
     * @param mixed $value
     * @return mixed[]
     */
    protected function addAdditionalProperty(array $schema, string $name, mixed $value): array
    {
        $schema['additionalProperty'][] = [
            '@type' => 'PropertyValue',
            'name'  => $name,
            'value' => $value,
        ];

        return $schema;
    }

    /**
     * Resolve priceValidUntil
     *
     * Resolve priceValidUntil: the real special-price end date when one is active,
     * otherwise a synthetic "today + N months" window (omitted when N is 0).
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @return string|null ISO 8601 date string (Y-m-d), or null to omit the property
     */
    protected function getPriceValidUntil(ProductInterface $product): ?string
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
     * Strip HTML tags and decode entities for use in schema text fields.
     *
     * @param string $html
     * @return string
     */
    protected function stripHtml(string $html): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Return product image URLs for the schema image field.
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @return string[]
     */
    protected function getProductImages(ProductInterface $product): array
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $images = [];
        try {
            $mediaGallery = $product->getMediaGalleryImages();
            if ($mediaGallery) {
                foreach ($mediaGallery as $image) {
                    $url = (string) $image->getUrl();
                    if ($url !== '') {
                        $images[] = $url;
                    }
                    if (\count($images) >= 5) {
                        break;
                    }
                }
            }
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        }

        if (empty($images)) {
            try {
                $url = (string) $this->imageHelper
                    ->init($product, 'product_page_image_large')
                    ->getUrl();
                if ($url !== '') {
                    $images[] = $url;
                }
            } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- no image available
            }
        }

        return $images;
    }
}
