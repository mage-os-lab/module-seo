<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\MetaTag\Provider;

use Magento\Catalog\Helper\Image as ImageHelper;
use MageOS\Seo\Api\MetaTagProviderInterface;
use MageOS\Seo\Model\Catalog\CurrentEntity;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\FinalPrice;
use MageOS\Seo\Service\CurrencyService;

class ProductMetaProvider implements MetaTagProviderInterface
{
    /**
     * @param CurrentEntity $currentEntity
     * @param CurrencyService $currencyService
     * @param ImageHelper $imageHelper
     * @param Config $seoConfig
     * @param FinalPrice $finalPrice
     */
    public function __construct(
        private readonly CurrentEntity   $currentEntity,
        private readonly CurrencyService $currencyService,
        private readonly ImageHelper     $imageHelper,
        private readonly Config          $seoConfig,
        private readonly FinalPrice      $finalPrice
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHandles(): array
    {
        return ['catalog_product_view'];
    }

    /**
     * @inheritdoc
     */
    public function getMetaTags(): array
    {
        if (!$this->seoConfig->isOgTagsEnabled()) {
            return [];
        }

        $product = $this->currentEntity->getProduct();
        if (!$product) {
            return [];
        }
        /** @var \Magento\Catalog\Model\Product $product */

        $title = $product->getName();

        $description = mb_substr(
            strip_tags((string) $product->getShortDescription() ?: (string) $product->getDescription()),
            0,
            160
        );

        $url = $product->getProductUrl();

        // Image
        $imageUrl = '';
        try {
            $imageUrl = (string) $this->imageHelper
                ->init($product, 'product_page_image_large')
                ->getUrl();
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- no image, leave empty
        }

        // The price the structured data publishes, or none (issue #38): an unknown price — a
        // composite nothing can price, or a lookup that failed, which FinalPrice logs — is left out
        // rather than written as 0.00, which reads as free. FinalPrice's amount is already in the
        // display currency.
        $amount   = $this->finalPrice->get($product);
        $price    = $amount === null ? '' : $this->currencyService->formatAmountForLlms($amount);
        $currency = $this->currencyService->getCurrentCurrencyCode();

        $tags = [
            ['property' => 'og:type',        'content' => 'product'],
            ['property' => 'og:title',       'content' => $title],
            ['property' => 'og:url',         'content' => $url],
        ];

        if ($description !== '') {
            $tags[] = ['property' => 'og:description', 'content' => $description];
        }

        if ($imageUrl !== '') {
            $tags[] = ['property' => 'og:image', 'content' => $imageUrl];
        }

        if ($price !== '') {
            $tags[] = ['property' => 'product:price:amount',   'content' => $price];
            $tags[] = ['property' => 'product:price:currency', 'content' => $currency];
        }

        $availability = ($product->isSalable()) ? 'instock' : 'oos';
        $tags[] = ['property' => 'product:availability', 'content' => $availability];

        return $tags;
    }
}
