<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\ProductSchemaBuilderInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\GtinValidator;
use MageOS\Seo\Model\Product\OfferBuilder;
use MageOS\Seo\Model\Review\AggregateRatingResolver;

abstract class AbstractBuilder implements ProductSchemaBuilderInterface
{
    /**
     * All collaborators are required: Magento's ObjectManager passes the default value
     * for optional constructor parameters unless di.xml configures them per consumer,
     * so an optional pool with a "?? new Pool()" fallback silently loses every enricher
     * and provider registered in di.xml.
     *
     * @param StoreManagerInterface $storeManager
     * @param ImageHelper $imageHelper
     * @param Config $seoConfig
     * @param OfferBuilder $offerBuilder
     * @param AggregateRatingResolver $aggregateRatingResolver
     * @param GtinValidator $gtinValidator
     */
    public function __construct(
        protected readonly StoreManagerInterface   $storeManager,
        protected readonly ImageHelper             $imageHelper,
        protected readonly Config                  $seoConfig,
        protected readonly OfferBuilder            $offerBuilder,
        protected readonly AggregateRatingResolver $aggregateRatingResolver,
        protected readonly GtinValidator           $gtinValidator
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
        return array_merge($schema, $this->gtinValidator->toProperties($value));
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

        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => $this->getSchemaType(),
            '@id'      => $productUrl . '#product',
            'name'     => $product->getName(),
            'url'      => $productUrl,
            'sku'      => $product->getSku(),
            // An Offer, or an AggregateOffer for a configurable priced across a range.
            'offers'   => $this->offerBuilder->build($product, $productUrl),
        ];

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
