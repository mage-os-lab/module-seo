<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\LlmsJsonl;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Service\CurrencyService;

/**
 * Builds a compact JSON-LD Product node (one line of /llms.jsonl) for a single product.
 *
 * Deliberately leaner than the full product schema builders: just the fields an AI catalog consumer
 * needs. Omits empty values to keep each line small.
 */
class ProductLineBuilder
{
    private const AVAILABILITY_IN_STOCK = 'https://schema.org/InStock';
    private const AVAILABILITY_OUT      = 'https://schema.org/OutOfStock';

    /**
     * Maximum description length in characters, ellipsis excluded.
     */
    private const DESCRIPTION_MAX = 300;

    /**
     * @param StoreManagerInterface $storeManager
     * @param CurrencyService $currencyService
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly CurrencyService       $currencyService
    ) {
    }

    /**
     * Build the JSON-LD Product node for a product.
     *
     * Salability is passed in explicitly (resolved in one MSI batch call per
     * collection page by JsonlBuilder) instead of consulting the product's
     * legacy is_salable state per line.
     *
     * @param ProductInterface $product
     * @param bool $isSalable
     * @return array<string, mixed>
     */
    public function build(ProductInterface $product, bool $isSalable): array
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $url = $product->getProductUrl();

        $node = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            '@id'      => $url,
            'name'     => (string) $product->getName(),
            'url'      => $url,
            'offers'   => [
                '@type'         => 'Offer',
                'price'         => $this->price($product),
                'priceCurrency' => $this->currencyService->getCurrentCurrencyCode(),
                'availability'  => $isSalable ? self::AVAILABILITY_IN_STOCK : self::AVAILABILITY_OUT,
                'url'           => $url,
            ],
        ];

        $sku = (string) $product->getSku();
        if ($sku !== '') {
            $node['sku'] = $sku;
        }

        $description = $this->description($product);
        if ($description !== '') {
            $node['description'] = $description;
        }

        $image = $this->image($product);
        if ($image !== '') {
            $node['image'] = $image;
        }

        return $node;
    }

    /**
     * Resolve the final price as a formatted string.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return string
     */
    private function price(ProductInterface $product): string
    {
        try {
            $value = $product->getPriceInfo()->getPrice('final_price')->getValue();
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- fall back to 0
            $value = 0.0;
        }
        // PriceInfo amounts are already in the current (display) currency — core
        // RegularPrice / SpecialPrice::getValue() convert with PriceCurrency — which is
        // the priceCurrency emitted with them. Converting again would apply the rate twice.
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * Resolve a plain-text description (short, then full), trimmed to 300 chars.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return string
     */
    private function description(ProductInterface $product): string
    {
        $raw = (string) ($product->getShortDescription() ?: $product->getDescription());
        if ($raw === '') {
            return '';
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- plain-text from rich HTML
        $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // One line of text: tags leave runs of newlines and indentation behind.
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= self::DESCRIPTION_MAX) {
            return $text;
        }

        // Cut at the last word boundary so a word is never split; fall back to a hard
        // cut only for a single very long word.
        $cut   = mb_substr($text, 0, self::DESCRIPTION_MAX + 1);
        $space = mb_strrpos($cut, ' ');
        $cut   = $space !== false && $space > (int) (self::DESCRIPTION_MAX * 0.6)
            ? mb_substr($cut, 0, $space)
            : mb_substr($text, 0, self::DESCRIPTION_MAX);

        return rtrim($cut, " \t,.;:-") . '…';
    }

    /**
     * Resolve an absolute product image URL, or empty string.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return string
     */
    private function image(ProductInterface $product): string
    {
        $image = (string) $product->getImage();
        if ($image === '' || $image === 'no_selection') {
            return '';
        }

        try {
            /** @var Store $store */
            $store    = $this->storeManager->getStore();
            $mediaUrl = rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/');
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- no media url
            return '';
        }

        return $mediaUrl . '/catalog/product' . $image;
    }
}
