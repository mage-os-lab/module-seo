<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Feed;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page;
use Magento\Framework\App\Config\Value as ConfigValue;
use Magento\Framework\Event;
use Magento\Framework\Model\AbstractModel;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\Sitemap\ItemProviderInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Sitemap\RebuildableSitemaps;
use MageOS\Seo\Model\Sitemap\RebuildGroup;

/**
 * Decides whether a change can affect a feed group, so saves that cannot change a feed
 * queue no rebuild.
 *
 * - A group that no store view can build (disabled everywhere; for a sitemap group, no sitemap a
 *   change rebuilds) is never queued.
 * - Saves are checked for the fields the group's content depends on. Anything the policy
 *   does not recognise — store saves, deletions, category moves, unknown events — counts
 *   as relevant: a spare rebuild is cheap, a missed one leaves a feed stale until the
 *   nightly cron.
 *
 * For the sitemap, a change is relevant when it can change what a sitemap lists — which pages, at
 * which URLs, with which alternates, left out as NOINDEX or not. `<lastmod>` and images are left to
 * the nightly run: counting them would rebuild the products for almost every catalogue edit.
 */
class InvalidationPolicy
{
    public const EVENT_PRODUCT_SAVE  = 'catalog_product_save_after';
    public const EVENT_CATEGORY_SAVE = 'catalog_category_save_after';
    public const EVENT_CMS_PAGE_SAVE = 'cms_page_save_after';

    /**
     * Product fields that change which products a sitemap lists, or at which URL.
     */
    private const SITEMAP_PRODUCT_FIELDS = ['url_key', 'status', 'visibility'];

    /**
     * Category fields that change which categories a sitemap lists, or at which URL.
     */
    private const SITEMAP_CATEGORY_FIELDS = ['url_key', 'is_active'];

    /**
     * CMS page fields that change which pages a sitemap lists, or at which URL.
     */
    private const SITEMAP_CMS_PAGE_FIELDS = ['identifier', 'is_active', 'store_id'];

    /**
     * Fields of this module's own per-entity settings that change a sitemap: the robots directive
     * decides whether a page is listed, a CMS page's translation group its alternates.
     */
    private const SITEMAP_OVERRIDE_FIELDS = ['robots_meta', 'hreflang_group'];

    /**
     * This module's per-entity settings, by the event prefix their models save under, and the
     * sitemap type they belong to.
     */
    private const SITEMAP_OVERRIDE_EVENTS = [
        'mageos_seo_product_override' => ItemProviderInterface::TYPE_PRODUCTS,
        'mageos_seo_category_config'  => ItemProviderInterface::TYPE_CATEGORIES,
        'mageos_seo_cms_page_config'  => ItemProviderInterface::TYPE_PAGES,
    ];

    /**
     * Configuration that can change any URL a sitemap lists, or whether it is listed.
     *
     * Base URLs and the home page (web/), URL suffixes (catalog/seo/), locales and hreflang codes,
     * robots defaults — this module's and core's — and the sitemap settings themselves.
     */
    private const SITEMAP_CONFIG_PREFIXES = [
        'sitemap/',
        'web/',
        'catalog/seo/',
        'general/locale/',
        'design/search_engine_robots/',
        'mageos_seo_general/hreflang/',
        'mageos_seo_general/robots_meta/',
    ];

    /**
     * @param StoreManagerInterface $storeManager
     * @param Config $seoConfig
     * @param RebuildableSitemaps $rebuildableSitemaps
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Config                $seoConfig,
        private readonly RebuildableSitemaps   $rebuildableSitemaps
    ) {
    }

    /**
     * Whether at least one active store view can build the feed group.
     *
     * @param string $group One of FeedRegenerator::GROUPS
     * @return bool
     */
    public function isGroupEnabled(string $group): bool
    {
        $storeIds = $this->activeStoreIds();

        switch ($group) {
            case FeedRegenerator::GROUP_LLMS:
                foreach ($storeIds as $storeId) {
                    if ($this->seoConfig->isLlmsTxtEnabled($storeId)
                        || $this->seoConfig->isLlmsFullTxtEnabled($storeId)
                    ) {
                        return true;
                    }
                }
                return false;

            case FeedRegenerator::GROUP_JSONL:
                foreach ($storeIds as $storeId) {
                    if ($this->seoConfig->isLlmsJsonlEnabled($storeId)) {
                        return true;
                    }
                }
                return false;

            default:
                // A sitemap type: only when a sitemap would be rebuilt for it.
                return str_starts_with($group, RebuildGroup::PREFIX) && $this->rebuildableSitemaps->exist();
        }
    }

    /**
     * The sitemap types the change behind an event can alter: none, some, or RebuildGroup::ALL_TYPES.
     *
     * @param Event $event
     * @return string[]
     */
    public function sitemapTypesAffectedBy(Event $event): array
    {
        $eventName = (string) $event->getName();
        $entity    = $event->getData('data_object');

        if ($entity instanceof Product) {
            // Saved, or deleted: a deleted product has left every sitemap that listed it.
            return $eventName !== self::EVENT_PRODUCT_SAVE
                || $this->isNew($entity)
                || $this->anyChanged($entity, self::SITEMAP_PRODUCT_FIELDS)
                || $this->websitesChanged($entity)
                ? [ItemProviderInterface::TYPE_PRODUCTS]
                : [];
        }
        if ($entity instanceof Category) {
            return $eventName !== self::EVENT_CATEGORY_SAVE
                || $this->isNew($entity)
                || $this->anyChanged($entity, self::SITEMAP_CATEGORY_FIELDS)
                ? [ItemProviderInterface::TYPE_CATEGORIES]
                : [];
        }
        if ($entity instanceof Page) {
            return $eventName !== self::EVENT_CMS_PAGE_SAVE
                || $this->isNew($entity)
                || $this->anyChanged($entity, self::SITEMAP_CMS_PAGE_FIELDS)
                ? [ItemProviderInterface::TYPE_PAGES]
                : [];
        }
        if ($entity instanceof ConfigValue) {
            return $this->isSitemapConfig((string) $entity->getData('path'))
                && (str_ends_with($eventName, '_delete_after') || $entity->isValueChanged())
                ? [RebuildGroup::ALL_TYPES]
                : [];
        }
        foreach (self::SITEMAP_OVERRIDE_EVENTS as $prefix => $type) {
            if (str_starts_with($eventName, $prefix . '_') && $entity instanceof AbstractModel) {
                // A new row counts only if it sets one of the fields: it changes them from nothing.
                return str_ends_with($eventName, '_delete_after')
                    || $this->anyChanged($entity, self::SITEMAP_OVERRIDE_FIELDS)
                    ? [$type]
                    : [];
            }
        }

        return match ($eventName) {
            'category_move'                     => [ItemProviderInterface::TYPE_CATEGORIES],
            'catalog_product_to_website_change' => [ItemProviderInterface::TYPE_PRODUCTS],
            // Store saves, scope deletions, anything unrecognised: any URL can have changed.
            default                             => [RebuildGroup::ALL_TYPES],
        };
    }

    /**
     * The sitemap types a mass attribute update can alter.
     *
     * @param string[] $attributeCodes
     * @return string[]
     */
    public function sitemapTypesAffectedByAttributeUpdate(array $attributeCodes): array
    {
        return array_intersect($attributeCodes, self::SITEMAP_PRODUCT_FIELDS) !== []
            ? [ItemProviderInterface::TYPE_PRODUCTS]
            : [];
    }

    /**
     * Whether a configuration path can change what a sitemap lists.
     *
     * @param string $path
     * @return bool
     */
    private function isSitemapConfig(string $path): bool
    {
        foreach (self::SITEMAP_CONFIG_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the change behind an event can alter the feed group's content.
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
     * Whether a mass attribute update can alter the feed group's content.
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
     * @param string $eventName
     * @param mixed $entity
     * @return bool
     */
    private function affectsLlms(string $eventName, mixed $entity): bool
    {
        if ($eventName === self::EVENT_PRODUCT_SAVE && $entity instanceof Product) {
            // is_changed_categories is set by core's category link save handler during the save.
            return $this->isNew($entity)
                || (bool) $entity->getData('is_changed_categories')
                || $this->websitesChanged($entity);
        }

        return true;
    }

    /**
     * Whether the saved entity was created by this save.
     *
     * EAV resources flag new entities with isObjectNew(); other models are treated as new
     * when they were never loaded (no original data).
     *
     * @param AbstractModel $entity
     * @return bool
     */
    private function isNew(AbstractModel $entity): bool
    {
        return $entity->isObjectNew() || $entity->getOrigData() === null;
    }

    /**
     * Whether any of the fields differs from its loaded value.
     *
     * @param AbstractModel $entity
     * @param string[] $fields
     * @return bool
     */
    private function anyChanged(AbstractModel $entity, array $fields): bool
    {
        foreach ($fields as $field) {
            if ($entity->dataHasChangedFor($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the product's website assignments changed (same comparison as core's URL rewrite observer).
     *
     * @param Product $product
     * @return bool
     */
    private function websitesChanged(Product $product): bool
    {
        // Set by the product resource when it saves website links.
        if ($product->getData('is_changed_websites')) {
            return true;
        }

        $old = $product->getOrigData('website_ids');
        $new = $product->getWebsiteIds();
        if (!\is_array($old) || !\is_array($new)) {
            return false;
        }

        return array_diff($old, $new) !== [] || array_diff($new, $old) !== [];
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
