<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\Sitemap;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Cms\Model\Page;
use Magento\Framework\App\Config\Value as ConfigValue;
use Magento\Framework\Event;
use Magento\Framework\Model\AbstractModel;
use MageOS\Seo\Api\Sitemap\ItemProviderInterface;
use MageOS\Seo\Model\Rebuild\ChangeInspector;

/**
 * Decides which sitemap types a change can alter, so saves that cannot change a sitemap queue no
 * rebuild.
 *
 * - Nothing is queued while no sitemap would be rebuilt (see RebuildableSitemaps).
 * - Saves are checked for the fields a sitemap depends on. Anything the policy does not recognise —
 *   store saves, deletions, category moves, unknown events — counts as relevant: a spare rebuild is
 *   cheap, a missed one leaves a sitemap stale until the next.
 *
 * A change is relevant when it can change what a sitemap lists — which pages, at which URLs, with
 * which alternates, left out as NOINDEX or not. `<lastmod>` and images are left to the nightly run:
 * counting them would rebuild the products for almost every catalogue edit.
 */
class InvalidationPolicy
{
    public const EVENT_PRODUCT_SAVE  = 'catalog_product_save_after';
    public const EVENT_CATEGORY_SAVE = 'catalog_category_save_after';
    public const EVENT_CMS_PAGE_SAVE = 'cms_page_save_after';

    /**
     * Product fields that change which products a sitemap lists, or at which URL.
     */
    private const PRODUCT_FIELDS = ['url_key', 'status', 'visibility'];

    /**
     * Category fields that change which categories a sitemap lists, or at which URL.
     */
    private const CATEGORY_FIELDS = ['url_key', 'is_active'];

    /**
     * CMS page fields that change which pages a sitemap lists, or at which URL.
     */
    private const CMS_PAGE_FIELDS = ['identifier', 'is_active', 'store_id'];

    /**
     * Fields of this module's own per-entity settings that change a sitemap: the robots directive
     * decides whether a page is listed, a CMS page's translation group its alternates.
     */
    private const OVERRIDE_FIELDS = ['robots_meta', 'hreflang_group'];

    /**
     * This module's per-entity settings, by the event prefix their models save under, and the
     * sitemap type they belong to.
     */
    private const OVERRIDE_EVENTS = [
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
    private const CONFIG_PREFIXES = [
        'sitemap/',
        'web/',
        'catalog/seo/',
        'general/locale/',
        'design/search_engine_robots/',
        'mageos_seo_general/hreflang/',
        'mageos_seo_general/robots_meta/',
    ];

    /**
     * @param RebuildableSitemaps $rebuildableSitemaps
     * @param ChangeInspector $changeInspector
     */
    public function __construct(
        private readonly RebuildableSitemaps $rebuildableSitemaps,
        private readonly ChangeInspector     $changeInspector
    ) {
    }

    /**
     * Whether a sitemap rebuild is worth queueing at all: one would be rebuilt.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->rebuildableSitemaps->exist();
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
                || $this->changeInspector->isNew($entity)
                || $this->changeInspector->anyChanged($entity, self::PRODUCT_FIELDS)
                || $this->changeInspector->websitesChanged($entity)
                ? [ItemProviderInterface::TYPE_PRODUCTS]
                : [];
        }
        if ($entity instanceof Category) {
            return $eventName !== self::EVENT_CATEGORY_SAVE
                || $this->changeInspector->isNew($entity)
                || $this->changeInspector->anyChanged($entity, self::CATEGORY_FIELDS)
                ? [ItemProviderInterface::TYPE_CATEGORIES]
                : [];
        }
        if ($entity instanceof Page) {
            return $eventName !== self::EVENT_CMS_PAGE_SAVE
                || $this->changeInspector->isNew($entity)
                || $this->changeInspector->anyChanged($entity, self::CMS_PAGE_FIELDS)
                ? [ItemProviderInterface::TYPE_PAGES]
                : [];
        }
        if ($entity instanceof ConfigValue) {
            return $this->changeInspector->isChangedConfigUnder($eventName, $entity, self::CONFIG_PREFIXES)
                ? [RebuildGroup::ALL_TYPES]
                : [];
        }
        foreach (self::OVERRIDE_EVENTS as $prefix => $type) {
            if (str_starts_with($eventName, $prefix . '_') && $entity instanceof AbstractModel) {
                // A new row counts only if it sets one of the fields: it changes them from nothing.
                return str_ends_with($eventName, '_delete_after')
                    || $this->changeInspector->anyChanged($entity, self::OVERRIDE_FIELDS)
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
        return array_intersect($attributeCodes, self::PRODUCT_FIELDS) !== []
            ? [ItemProviderInterface::TYPE_PRODUCTS]
            : [];
    }
}
