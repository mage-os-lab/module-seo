<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Feed;

use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\Value as ConfigValue;
use Magento\Framework\Event;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Aeo\Config;
use MageOS\Seo\Model\Rebuild\ChangeInspector;

/**
 * Decides whether a change can affect the llms documents, so saves that cannot change them queue no
 * rebuild.
 *
 * - A group that no store view can build (disabled everywhere) is never queued.
 * - Saves are checked for the fields the group's content depends on. Anything the policy does not
 *   recognise — store saves, deletions, category moves, the FAQs, the Organization, unknown events —
 *   counts as relevant: a spare rebuild is cheap, a missed one leaves a document stale until the
 *   nightly cron.
 */
class LlmsInvalidationPolicy
{
    private const EVENT_PRODUCT_SAVE = 'catalog_product_save_after';

    /**
     * Configuration that can change what /llms.txt and /llms-full.txt show.
     *
     * - general/locale/: the `> Locale:` line;
     * - trans_email/ident_support/: the AI contact, when the Organization has none
     *   (Organization\ContactEmail);
     * - mageos_aeo/llms_txt/: whether each document is written, and the FAQ groups;
     * - web/: the base URL every link in the documents starts with;
     * - catalog/seo/: the category URL suffix in the category tree.
     */
    private const LLMS_CONFIG_PREFIXES = [
        'general/locale/',
        'trans_email/ident_support/',
        'mageos_aeo/llms_txt/',
        'web/',
        'catalog/seo/',
    ];

    /**
     * @param StoreManagerInterface $storeManager
     * @param Config $aeoConfig
     * @param ChangeInspector $changeInspector
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config                $aeoConfig,
        private readonly ChangeInspector       $changeInspector
    ) {
    }

    /**
     * Whether at least one active store view can build the group.
     *
     * @param string $group One of FeedRegenerator::GROUPS
     * @return bool
     */
    public function isGroupEnabled(string $group): bool
    {
        foreach ($this->activeStoreIds() as $storeId) {
            $enabled = match ($group) {
                FeedRegenerator::GROUP_LLMS  => $this->aeoConfig->isLlmsTxtEnabled($storeId)
                    || $this->aeoConfig->isLlmsFullTxtEnabled($storeId),
                FeedRegenerator::GROUP_JSONL => $this->aeoConfig->isLlmsJsonlEnabled($storeId),
                default                      => false,
            };
            if ($enabled) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the change behind an event can alter the group's content.
     *
     * @param string $group One of FeedRegenerator::GROUPS
     * @param Event $event
     * @return bool
     */
    public function isRelevantChange(string $group, Event $event): bool
    {
        $eventName = (string) $event->getName();
        $entity    = $event->getData('data_object');

        return match ($group) {
            FeedRegenerator::GROUP_LLMS => $this->affectsLlms($eventName, $entity),
            default                     => true,
        };
    }

    /**
     * Whether a mass attribute update can alter the group's content.
     *
     * Mass updates (admin "Update attributes", mass enable/disable, the REST equivalents) write
     * attribute values straight to the EAV tables without saving the products, so they carry no
     * entity to inspect — only the attribute codes that were written.
     *
     * @param string $group One of FeedRegenerator::GROUPS
     * @param string[] $attributeCodes
     * @return bool
     */
    public function isRelevantAttributeUpdate(string $group, array $attributeCodes): bool
    {
        return match ($group) {
            // Every attribute the update can carry appears in a product's jsonl line.
            FeedRegenerator::GROUP_JSONL => true,
            // The llms documents list categories and product counts, never attribute values.
            default                      => false,
        };
    }

    /**
     * Decide whether a change affects llms.txt / llms-full.txt.
     *
     * The documents show product counts per category. Core counts the category's product links
     * joined to catalog_product_website for the store's website, so a product save matters for a
     * new product, changed category assignments and changed website assignments — but not for
     * attribute values, which the documents never show.
     *
     * Configuration matters when a value the documents show changed (LLMS_CONFIG_PREFIXES).
     *
     * @param string $eventName
     * @param mixed $entity
     * @return bool
     */
    private function affectsLlms(string $eventName, mixed $entity): bool
    {
        if ($eventName === self::EVENT_PRODUCT_SAVE && $entity instanceof Product) {
            // is_changed_categories is set by core's category link save handler during the save.
            return $this->changeInspector->isNew($entity)
                || (bool) $entity->getData('is_changed_categories')
                || $this->changeInspector->websitesChanged($entity);
        }
        if ($entity instanceof ConfigValue) {
            return $this->changeInspector->isChangedConfigUnder($eventName, $entity, self::LLMS_CONFIG_PREFIXES);
        }

        return true;
    }

    /**
     * IDs of the active store views.
     *
     * @return int[]
     */
    private function activeStoreIds(): array
    {
        $ids = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ($store->getIsActive()) {
                $ids[] = (int) $store->getId();
            }
        }

        return $ids;
    }
}
