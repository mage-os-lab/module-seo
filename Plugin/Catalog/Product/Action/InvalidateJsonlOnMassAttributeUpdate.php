<?php

declare(strict_types=1);

namespace MageOS\Seo\Plugin\Catalog\Product\Action;

use Magento\Catalog\Model\Product\Action;
use MageOS\Seo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Model\Feed\LlmsInvalidationPolicy;
use MageOS\Seo\Model\Rebuild\Invalidator;

/**
 * Queues a rebuild of /llms.jsonl after a mass attribute update.
 *
 * Mass updates — the admin "Update attributes" action, mass enable/disable, and anything else
 * using this service — write attribute values straight to the EAV tables. No product is saved,
 * so catalog_product_save_after never fires and the save observers cannot see the change; the
 * only post-update hook core offers is this method's return. The llms.txt documents list
 * categories and counts, which no attribute value changes, so only the jsonl feed is queued.
 *
 * Website assignment changes (updateWebsites()) do dispatch an event and are handled by the
 * observers registered for catalog_product_to_website_change.
 */
class InvalidateJsonlOnMassAttributeUpdate
{
    /**
     * @param Invalidator $invalidator
     * @param LlmsInvalidationPolicy $invalidationPolicy
     */
    public function __construct(
        private readonly Invalidator            $invalidator,
        private readonly LlmsInvalidationPolicy $invalidationPolicy
    ) {
    }

    /**
     * Queue a rebuild of the jsonl feed when the updated attributes can appear in it.
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
        if ($attributeCodes === []) {
            // An observer of catalog_product_attribute_update_before can empty the set.
            return $result;
        }

        if ($this->invalidationPolicy->isRelevantAttributeUpdate(FeedRegenerator::GROUP_JSONL, $attributeCodes)) {
            $this->invalidator->invalidate(FeedRegenerator::GROUP_JSONL);
        }

        return $result;
    }
}
