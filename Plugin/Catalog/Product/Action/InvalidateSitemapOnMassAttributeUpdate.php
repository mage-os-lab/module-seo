<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\Catalog\Product\Action;

use Magento\Catalog\Model\Product\Action;
use MageOS\Seo\Model\Rebuild\Invalidator;
use MageOS\Seo\Model\Sitemap\InvalidationPolicy;

/**
 * Queues a rebuild of the products in the sitemaps after a mass attribute update that can change
 * which products are listed, or at which URL.
 *
 * Mass updates — the admin "Update attributes" action, mass enable/disable, and anything else
 * using this service — write attribute values straight to the EAV tables. No product is saved,
 * so catalog_product_save_after never fires and the save observers cannot see the change; the
 * only post-update hook core offers is this method's return.
 *
 * Website assignment changes (updateWebsites()) do dispatch an event and are handled by the
 * observers registered for catalog_product_to_website_change.
 */
class InvalidateSitemapOnMassAttributeUpdate
{
    /**
     * @param Invalidator $invalidator
     * @param InvalidationPolicy $invalidationPolicy
     */
    public function __construct(
        private readonly Invalidator        $invalidator,
        private readonly InvalidationPolicy $invalidationPolicy
    ) {
    }

    /**
     * Queue a rebuild of every sitemap type the updated attributes can alter.
     *
     * @param Action $subject
     * @param Action $result
     * @param int[] $productIds
     * @param array<string,mixed> $attrData
     * @param int $storeId
     * @return Action
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function afterUpdateAttributes(
        Action $subject,
        Action $result,
        $productIds,
        $attrData,
        $storeId
    ): Action {
        $attributeCodes = array_map('strval', array_keys(\is_array($attrData) ? $attrData : []));
        foreach ($this->invalidationPolicy->sitemapTypesAffectedByAttributeUpdate($attributeCodes) as $type) {
            $this->invalidator->invalidateSitemap($type);
        }

        return $result;
    }
}
