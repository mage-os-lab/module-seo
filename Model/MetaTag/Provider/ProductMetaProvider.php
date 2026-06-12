<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\MetaTag\Provider;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use MageOS\Seo\Api\MetaTagProviderInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Service\CurrencyService;

class ProductMetaProvider implements MetaTagProviderInterface
{
    private const VARIANT_DATA_PARAM = 'variant_slug_data';

    /**
     * @param Registry $registry
     * @param CurrencyService $currencyService
     * @param ImageHelper $imageHelper
     * @param Config $seoConfig
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly Registry         $registry,
        private readonly CurrencyService  $currencyService,
        private readonly ImageHelper      $imageHelper,
        private readonly Config           $seoConfig,
        private readonly RequestInterface $request,
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

        $product = $this->registry->registry('current_product');
        if (!$product) {
            return [];
        }

        $variantData = $this->request->getParam(self::VARIANT_DATA_PARAM, []);
        $isVariant   = !empty($variantData) && \is_array($variantData);

        $title = $product->getName();
        if ($isVariant && !empty($variantData['_title'])) {
            $title = $variantData['_title'];
        }

        $description = mb_substr(
            strip_tags((string) $product->getShortDescription() ?: (string) $product->getDescription()),
            0,
            160
        );

        $url = $product->getProductUrl();
        if ($isVariant && !empty($variantData['_canonical_url'])) {
            $url = $variantData['_canonical_url'];
        }

        // Image
        $imageUrl = '';
        try {
            $imageUrl = (string) $this->imageHelper
                ->init($product, 'product_page_image_large')
                ->getUrl();
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- no image, leave empty
        }

        $price    = '';
        $currency = $this->currencyService->getCurrentCurrencyCode();
        try {
            if ($isVariant && !empty($variantData['_price'])) {
                $price = number_format((float) $variantData['_price'], 2, '.', '');
            } else {
                $finalPrice = $product->getPriceInfo()->getPrice('final_price')->getValue();
                $price      = number_format((float) $finalPrice, 2, '.', '');
            }
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch
        }

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
