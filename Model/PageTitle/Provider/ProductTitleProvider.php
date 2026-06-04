<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\PageTitle\Provider;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Registry;
use MageOS\Seo\Api\PageTitleProviderInterface;

class ProductTitleProvider implements PageTitleProviderInterface
{
    private const VARIANT_DATA_PARAM = 'variant_slug_data';

    /**
     * @param Registry $registry
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly Registry         $registry,
        private readonly RequestInterface $request
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
    public function getSortOrder(): int
    {
        return 100;
    }

    /**
     * @inheritdoc
     */
    public function getTitle(): string
    {
        $variantData = $this->request->getParam(self::VARIANT_DATA_PARAM, []);
        if (!empty($variantData['_title'])) {
            return (string) $variantData['_title'];
        }

        $product = $this->registry->registry('current_product');
        return $product ? (string) $product->getName() : '';
    }
}
