<?php

declare(strict_types=1);

namespace MageOS\Seo\Model\PageCache;

/**
 * The page cache tags of this module's per-entity SEO settings.
 *
 * A page built from an entity's SEO settings carries the entity's tag (DependencyCollector), and a
 * change to those settings purges it (Purger), so the pages that read them are rebuilt and no
 * others. A category's settings are inherited by every category beneath it and read by the schema
 * of products in them, so their pages carry the tag of every category on the way up.
 *
 * One tag per entity, whatever the store view: a store view's row purges the entity's pages in
 * every store view, which keeps one tag per entity, and the store-0 row — the one every store view
 * inherits — needs exactly that anyway.
 */
class SeoCacheTags
{
    /**
     * A category's SEO settings: its own page, the pages of every category beneath it, and the
     * pages of products whose schema reads them.
     */
    public const CATEGORY = 'mageos_seo_cat';

    /**
     * A product's SEO overrides.
     */
    public const PRODUCT = 'mageos_seo_prod';

    /**
     * A CMS page's SEO settings.
     */
    public const CMS_PAGE = 'mageos_seo_cms';

    /**
     * The members of a CMS translation group, which every page of the group lists in its head.
     */
    public const HREFLANG_GROUP = 'mageos_seo_hreflang';

    /**
     * The tag of a category's SEO settings.
     *
     * @param int $categoryId
     * @return string
     */
    public function category(int $categoryId): string
    {
        return self::CATEGORY . '_' . $categoryId;
    }

    /**
     * The tag of a product's SEO overrides.
     *
     * @param int $productId
     * @return string
     */
    public function product(int $productId): string
    {
        return self::PRODUCT . '_' . $productId;
    }

    /**
     * The tag of a CMS page's SEO settings.
     *
     * @param int $pageId
     * @return string
     */
    public function cmsPage(int $pageId): string
    {
        return self::CMS_PAGE . '_' . $pageId;
    }

    /**
     * The tag of a CMS translation group's members.
     *
     * Hashed: a group is free text, and a tag travels in a comma-separated header and in the
     * pattern of a Varnish ban.
     *
     * @param string $group
     * @return string
     */
    public function hreflangGroup(string $group): string
    {
        return self::HREFLANG_GROUP . '_' . substr(sha1($group), 0, 16);
    }
}
