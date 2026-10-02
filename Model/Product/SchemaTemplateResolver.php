<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Product;

use MageOS\Seo\Model\Config;

/**
 * The product schema template in effect for a category in a store view.
 *
 * One answer for both places that need it: the storefront builds a product's schema with this
 * template, and the category form lists this template's optional fields.
 */
class SchemaTemplateResolver
{
    /**
     * The template used when nothing else applies, and for a code no builder is registered for.
     */
    public const FALLBACK = 'GenericProduct';

    /**
     * @param Config $config
     * @param SchemaBuilderPool $builderPool
     */
    public function __construct(
        private readonly Config            $config,
        private readonly SchemaBuilderPool $builderPool
    ) {
    }

    /**
     * The category's template, else the store view's default, else GenericProduct.
     *
     * A code no builder is registered for — a template whose module has gone, or a code saved
     * before the default was a select — resolves to GenericProduct, which is what the pool builds
     * for it.
     *
     * @param string $categoryTemplate The category's own or inherited template; '' for none
     * @param int $storeId
     * @return string
     */
    public function resolve(string $categoryTemplate, int $storeId): string
    {
        $templateCode = $categoryTemplate !== ''
            ? $categoryTemplate
            : $this->config->getDefaultProductTemplate($storeId);

        return $templateCode !== '' && $this->builderPool->has($templateCode)
            ? $templateCode
            : self::FALLBACK;
    }
}
