<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\Catalog\Product\Action;

use Magento\Catalog\Model\Product\Action;
use MageOS\Seo\Model\Feed\FeedInvalidator;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\InvalidationPolicy;

/**
 * Queues feed rebuilds after a mass attribute update.
 *
 * Mass updates — the admin "Update attributes" action, mass enable/disable, and anything else
 * using this service — write attribute values straight to the EAV tables. No product is saved,
 * so catalog_product_save_after never fires and the save observers cannot see the change; the
 * only post-update hook core offers is this method's return.
 *
 * Website assignment changes (updateWebsites()) do dispatch an event and are handled by the
 * observers registered for catalog_product_to_website_change.
 */
class InvalidateFeedsOnMassAttributeUpdate
{
    /**
     * @param FeedInvalidator $feedInvalidator
     * @param InvalidationPolicy $invalidationPolicy
     */
    public function __construct(
        private readonly FeedInvalidator    $feedInvalidator,
        private readonly InvalidationPolicy $invalidationPolicy
    ) {
    }

    /**
     * Queue a rebuild of every feed the updated attributes can appear in.
     *
     * @param Action $subject
     * @param Action $result
     * @param int[]|string[] $productIds
     * @param array<string,mixed> $attrData Attribute code => value
     * @param int $storeId
     * @return Action
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterUpdateAttributes(
        Action $subject,
        Action $result,
        $productIds,
        $attrData,
        $storeId
    ): Action {
        $attributeCodes = array_map('strval', array_keys(\is_array($attrData) ? $attrData : []));
        if ($attributeCodes === []) {
            // An observer of catalog_product_attribute_update_before can empty the set.
            return $result;
        }

        if ($this->invalidationPolicy->isRelevantAttributeUpdate(FeedRegenerator::GROUP_JSONL, $attributeCodes)) {
            $this->feedInvalidator->invalidateJsonl();
        }
        if ($this->invalidationPolicy->isRelevantAttributeUpdate(FeedRegenerator::GROUP_HREFLANG, $attributeCodes)) {
            $this->feedInvalidator->invalidateHreflangSitemap();
        }

        return $result;
    }
}
