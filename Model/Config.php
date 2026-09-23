<?php

declare(strict_types=1);

namespace MageOS\Seo\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const XML_OG_TAGS_ENABLED               = 'mageos_seo_general/og_tags/enabled';
    public const XML_SD_ENABLED                    = 'mageos_seo_general/structured_data/enabled';
    public const XML_SD_DEFAULT_TEMPLATE           = 'mageos_seo_general/structured_data/default_product_template';
    public const XML_SD_CATEGORY_ITEM_LIST_ENABLED = 'mageos_seo_general/structured_data/category_item_list_enabled';
    public const XML_SD_CATEGORY_ITEM_LIST_MAX     = 'mageos_seo_general/structured_data/category_item_list_max';
    public const XML_SD_HAS_VARIANT_MAX            = 'mageos_seo_general/structured_data/has_variant_max';
    public const XML_SD_PRICE_VALID_UNTIL_MONTHS   = 'mageos_seo_general/structured_data/price_valid_until_months';
    public const XML_SD_AGGREGATE_RATING_ENABLED   = 'mageos_seo_general/structured_data/aggregate_rating_enabled';

    public const XML_CATEGORY_INHERITANCE_STRATEGY = 'mageos_seo_general/category_config/inheritance_strategy';

    /**
     * The strategy used when configuration names none, and the one shipped as the default.
     */
    public const DEFAULT_INHERITANCE_STRATEGY = 'category_first';
    public const XML_LLMS_ENABLED                  = 'mageos_seo_general/llms_txt/enabled';
    public const XML_LLMS_FULL_ENABLED             = 'mageos_seo_general/llms_txt/full_enabled';
    public const XML_LLMS_JSONL_ENABLED            = 'mageos_seo_general/llms_txt/jsonl_enabled';
    public const XML_FEEDS_STORAGE_DIR             = 'mageos_seo_general/feeds/storage_dir';
    public const XML_ROBOTS_PRODUCT_DEFAULT        = 'mageos_seo_general/robots_meta/product_default';
    public const XML_ROBOTS_CATEGORY_DEFAULT       = 'mageos_seo_general/robots_meta/category_default';
    public const XML_ROBOTS_CMS_DEFAULT            = 'mageos_seo_general/robots_meta/cms_page_default';
    public const XML_ROBOTS_PAGINATED_ENABLED      = 'mageos_seo_general/robots_meta/paginated_enabled';
    public const XML_ROBOTS_PAGINATED              = 'mageos_seo_general/robots_meta/paginated_robots';
    public const XML_HREFLANG_ENABLED              = 'mageos_seo_general/hreflang/enabled';
    public const XML_HREFLANG_XDEFAULT_STORE       = 'mageos_seo_general/hreflang/xdefault_store_id';
    public const XML_HREFLANG_EXCLUDED_STORES      = 'mageos_seo_general/hreflang/excluded_store_ids';
    public const XML_HREFLANG_LANGUAGE_ONLY        = 'mageos_seo_general/hreflang/language_only_enabled';
    public const XML_HREFLANG_SITEMAP_ENABLED      = 'mageos_seo_general/hreflang/sitemap_enabled';
    public const XML_HREFLANG_SAME_WEBSITE_ONLY    = 'mageos_seo_general/hreflang/same_website_only';
    public const XML_HREFLANG_CODES                = 'mageos_seo_general/hreflang/codes';
    public const XML_AEO_SPEAKABLE_ENABLED         = 'mageos_seo_general/aeo/speakable_enabled';
    public const XML_AEO_SPEAKABLE_SELECTORS       = 'mageos_seo_general/aeo/speakable_css_selectors';
    public const XML_AI_ROBOTS_ENABLED             = 'mageos_seo_general/ai_robots/enabled';
    public const XML_AI_ROBOTS_DISALLOWED          = 'mageos_seo_general/ai_robots/disallowed';

    /**
     * Initialize Config with scope configuration.
     *
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Check if Open Graph tags output is enabled.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isOgTagsEnabled(int|string|null $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_OG_TAGS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if structured data output is enabled.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isStructuredDataEnabled(int|string|null $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_SD_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Return the default product schema template code.
     *
     * @param int|string|null $storeId
     * @return string
     */
    public function getDefaultProductTemplate(int|string|null $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_SD_DEFAULT_TEMPLATE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if the category ItemList schema block is enabled.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isCategoryItemListEnabled(int|string|null $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_SD_CATEGORY_ITEM_LIST_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Return the maximum number of products to include in a category ItemList.
     *
     * @param int|string|null $storeId
     * @return int
     */
    public function getCategoryItemListMax(int|string|null $storeId = null): int
    {
        return max(1, (int) ($this->scopeConfig->getValue(
            self::XML_SD_CATEGORY_ITEM_LIST_MAX,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 36));
    }

    /**
     * Return the code of the configured category inheritance strategy.
     *
     * Read at default scope and takes no store ID: this decides how store-view scope itself is
     * resolved against the category tree, so letting it vary per store view would mean the rule
     * for choosing between scopes depended on the scope it was choosing.
     *
     * @return string
     */
    public function getCategoryInheritanceStrategy(): string
    {
        $configured = (string) $this->scopeConfig->getValue(self::XML_CATEGORY_INHERITANCE_STRATEGY);

        return $configured !== '' ? $configured : self::DEFAULT_INHERITANCE_STRATEGY;
    }

    /**
     * Return the maximum number of hasVariant offers to render per product.
     *
     * @param int|string|null $storeId
     * @return int
     */
    public function getHasVariantMax(int|string|null $storeId = null): int
    {
        return max(1, (int) ($this->scopeConfig->getValue(
            self::XML_SD_HAS_VARIANT_MAX,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ) ?: 50));
    }

    /**
     * Return the number of months used to calculate priceValidUntil.
     *
     * @param int|string|null $storeId
     * @return int
     */
    public function getPriceValidUntilMonths(int|string|null $storeId = null): int
    {
        return max(1, (int) $this->scopeConfig->getValue(
            self::XML_SD_PRICE_VALID_UNTIL_MONTHS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    /**
     * Check if AggregateRating output on product schema is enabled.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isAggregateRatingEnabled(int|string|null $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_SD_AGGREGATE_RATING_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if the llms.txt endpoint is enabled.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isLlmsTxtEnabled(int|string|null $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_LLMS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if the llms-full.txt endpoint is enabled.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isLlmsFullTxtEnabled(int|string|null $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_LLMS_FULL_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if the /llms.jsonl product catalog endpoint is enabled.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isLlmsJsonlEnabled(int|string|null $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_LLMS_JSONL_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Absolute directory for pre-generated feed files, or '' for the default (var/mageos_seo).
     *
     * Scaled deployments point this at a mount shared between the web hosts and the
     * host running cron/queue consumers; var/ is host-local on multi-server setups.
     *
     * @return string
     */
    public function getFeedStorageDir(): string
    {
        return trim((string) $this->scopeConfig->getValue(self::XML_FEEDS_STORAGE_DIR));
    }

    /**
     * Return the default robots meta value for product pages.
     *
     * @param int|string|null $storeId
     * @return string
     */
    public function getRobotsProductDefault(int|string|null $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_ROBOTS_PRODUCT_DEFAULT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Return the default robots meta value for category pages.
     *
     * @param int|string|null $storeId
     * @return string
     */
    public function getRobotsCategoryDefault(int|string|null $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_ROBOTS_CATEGORY_DEFAULT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Return the default robots meta value for CMS pages.
     *
     * @param int|string|null $storeId
     * @return string
     */
    public function getRobotsCmsDefault(int|string|null $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_ROBOTS_CMS_DEFAULT,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check whether a dedicated robots meta is applied to paginated listing pages (?p=N, N>1).
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isPaginatedRobotsEnabled(int|string|null $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_ROBOTS_PAGINATED_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Return the robots meta value applied to paginated listing pages (?p=N, N>1).
     *
     * @param int|string|null $storeId
     * @return string
     */
    public function getRobotsPaginated(int|string|null $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_ROBOTS_PAGINATED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Check if hreflang alternate output is enabled.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isHreflangEnabled(int|string|null $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_HREFLANG_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Return the store view ID to advertise as hreflang x-default (0 = none).
     *
     * Read at website scope: on an installation with several websites — a .com and a .co.uk, say —
     * each has its own idea of which store view a visitor matching no alternate should land on.
     * A website with no value of its own inherits the default-scope one.
     *
     * @param int|null $websiteId null reads the default scope
     * @return int
     */
    public function getHreflangXDefaultStoreId(?int $websiteId = null): int
    {
        if ($websiteId === null) {
            return (int) $this->scopeConfig->getValue(self::XML_HREFLANG_XDEFAULT_STORE);
        }

        return (int) $this->scopeConfig->getValue(
            self::XML_HREFLANG_XDEFAULT_STORE,
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );
    }

    /**
     * Return the hreflang codes a store view claims, normalised, or none when it relies on its locale.
     *
     * @param int $storeId
     * @return string[]
     */
    public function getHreflangCodes(int $storeId): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_HREFLANG_CODES,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn (string $code): bool => $code !== ''
        ));
    }

    /**
     * Return store view IDs excluded from all hreflang output.
     *
     * @return int[]
     */
    public function getHreflangExcludedStoreIds(): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_HREFLANG_EXCLUDED_STORES);
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $id): int => (int) trim($id),
            explode(',', $raw)
        )));
    }

    /**
     * Check if automatic language-only hreflang tags are enabled.
     *
     * @return bool
     */
    public function isHreflangLanguageOnlyEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_HREFLANG_LANGUAGE_ONLY);
    }

    /**
     * Check if the /hreflang-sitemap.xml endpoint is enabled.
     *
     * @return bool
     */
    public function isHreflangSitemapEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_HREFLANG_SITEMAP_ENABLED);
    }

    /**
     * Check if hreflang alternates are limited to stores of the current website.
     *
     * @return bool
     */
    public function isHreflangSameWebsiteOnly(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_HREFLANG_SAME_WEBSITE_ONLY);
    }

    /**
     * Check if Speakable structured data output is enabled.
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isSpeakableEnabled(int|string|null $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_AEO_SPEAKABLE_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Return the configured Speakable CSS selectors (one per line in admin).
     *
     * @param int|string|null $storeId
     * @return string[]
     */
    public function getSpeakableCssSelectors(int|string|null $storeId = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_AEO_SPEAKABLE_SELECTORS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: [])));
    }

    /**
     * Check if AI-crawler directives are appended to robots.txt.
     *
     * @return bool
     */
    public function isAiRobotsEnabled(): bool
    {
        return (bool) $this->scopeConfig->getValue(self::XML_AI_ROBOTS_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Return the AI user-agents to disallow in robots.txt.
     *
     * @return string[]
     */
    public function getAiDisallowedBots(): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_AI_ROBOTS_DISALLOWED, ScopeInterface::SCOPE_STORE);
        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
