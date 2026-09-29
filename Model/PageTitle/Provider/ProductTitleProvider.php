<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\PageTitle\Provider;

use Magento\Catalog\Model\Product;
use MageOS\Seo\Api\PageTitleProviderInterface;
use MageOS\Seo\Model\Catalog\CurrentEntity;

class ProductTitleProvider implements PageTitleProviderInterface
{
    /**
     * @param CurrentEntity $currentEntity
     */
    public function __construct(
        private readonly CurrentEntity $currentEntity
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
     *
     * Only speaks when there is an explicit meta_title: returning the product name here would
     * override the merchant's meta_title, which core already applies (with name as its own
     * fallback).
     */
    public function getTitle(): string
    {
        $product = $this->currentEntity->getProduct();
        /** @var Product $product */
        return $product ? (string) $product->getData('meta_title') : '';
    }
}
