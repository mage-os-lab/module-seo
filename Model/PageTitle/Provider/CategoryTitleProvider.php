<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\PageTitle\Provider;

use Magento\Catalog\Model\Layer\Resolver as LayerResolver;
use MageOS\Seo\Api\PageTitleProviderInterface;

class CategoryTitleProvider implements PageTitleProviderInterface
{
    /**
     * @param LayerResolver $layerResolver
     */
    public function __construct(
        private readonly LayerResolver $layerResolver
    ) {
    }

    /**
     * @inheritdoc
     */
    public function getHandles(): array
    {
        return ['catalog_category_view'];
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
        try {
            $category = $this->layerResolver->get()->getCurrentCategory();
            return $category ? (string) $category->getName() : '';
        } catch (\Exception) {
            return '';
        }
    }
}
