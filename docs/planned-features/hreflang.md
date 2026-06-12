# Planned Feature: Multistore Hreflang Support

**Status:** Planned — not yet implemented  
**Complexity:** High  
**Priority driver:** Multi-store / multi-language SEO correctness

---

## What hreflang is

Hreflang tells search engines which URLs represent the same content in different languages or regions. Without it, Google may treat your English UK and English US stores as duplicate content and suppress one from results, or show the wrong regional version to users.

The `<head>` output is a set of `<link rel="alternate">` tags, one per store view:

```html
<link rel="alternate" hreflang="en-GB" href="https://uk.example.com/blue-t-shirt.html"/>
<link rel="alternate" hreflang="en-US" href="https://us.example.com/blue-t-shirt.html"/>
<link rel="alternate" hreflang="en"    href="https://uk.example.com/blue-t-shirt.html"/>
<link rel="alternate" hreflang="x-default" href="https://uk.example.com/blue-t-shirt.html"/>
```

The sitemap equivalent uses `<xhtml:link>` elements inside each `<url>` block. Google accepts either or both.

**Google's rules:**

- Every page in a hreflang set must include all the `<link>` tags — not just its own.
- The current page's URL must appear in the set as its own language tag (self-referencing).
- `x-default` should be included for pages with a clear primary version (recommended, not mandatory).
- Each `hreflang` value is a BCP 47 language tag: `en-GB`, `de-DE`, `fr-FR` (Magento's `en_GB` locale format with underscore replaced by hyphen).
- A language-only tag (e.g. `en`) is valid and acts as a catch-all for that language; it should point to the most appropriate URL for that language.

---

## Scope

- Product pages — `<head>` hreflang tags
- Category pages — `<head>` hreflang tags
- CMS pages (including home page) — `<head>` hreflang tags
- Stores with different product catalogues — handled correctly by design (see URL resolution section)
- Language-only tags (e.g. `en` in addition to `en-GB`) — automatic where unambiguous
- Hreflang XML sitemap — a dedicated cacheable sitemap endpoint at `/hreflang-sitemap.xml`
- Extensibility for bridge modules (custom page types via resolver pool)

---

## Architecture overview

Two independent deliverables share the same core data layer:

```text
StoreLocaleMap          — active store views, locale codes, base URLs (shared)
UrlRewriteFetcher       — batches URL rewrite queries (shared)

Deliverable 1: <head> tags
  HreflangResolverInterface  ← implemented per page type
  ResolverPool               ← iterates resolvers, appends language-only + x-default
  Block/Hreflang             ← thin block, calls pool, renders <link> tags

Deliverable 2: /hreflang-sitemap.xml
  HreflangSitemapGenerator   ← batch-loads all products/categories/cms, builds XML
  Controller/HreflangSitemap/Index  ← serves the sitemap, handles caching
  Observer/InvalidateHreflangSitemap ← invalidates on entity/store changes
```

---

## New files to create

| File | Purpose |
|---|---|
| `Api/HreflangResolverInterface.php` | Contract for per-page-type URL resolvers |
| `Model/Hreflang/StoreLocaleMap.php` | Builds and caches the store → locale map |
| `Model/Hreflang/UrlRewriteFetcher.php` | Shared DB queries against `url_rewrite` table |
| `Model/Hreflang/ResolverPool.php` | Iterates resolvers, appends language-only tags + x-default |
| `Model/Hreflang/Resolver/ProductHreflangResolver.php` | Product URL across store views |
| `Model/Hreflang/Resolver/CategoryHreflangResolver.php` | Category URL across store views |
| `Model/Hreflang/Resolver/CmsPageHreflangResolver.php` | CMS page URL across store views |
| `Model/Hreflang/SitemapGenerator.php` | Batch-builds the hreflang sitemap XML string |
| `Block/Hreflang.php` | Calls pool, renders `<link>` tags in `<head>` |
| `Controller/HreflangSitemap/Index.php` | Serves `/hreflang-sitemap.xml` |
| `Observer/InvalidateHreflangSitemapCache.php` | Flushes sitemap cache on entity changes |
| `Model/Config/Source/StoreViews.php` | Admin dropdown source: all active store views |
| `view/frontend/templates/seo/hreflang.phtml` | Renders `<link rel="alternate">` tags |

### Files to modify

| File | Change |
|---|---|
| `etc/di.xml` | Wire pool + resolvers + observer |
| `etc/config.xml` | Add defaults for new config paths |
| `etc/adminhtml/system.xml` | Add Hreflang config group |
| `etc/frontend/routes.xml` | Register `hreflang-sitemap` route |
| `etc/events.xml` | Register invalidation observer on product/category/cms save |
| `Model/Config.php` | Add five new hreflang getters |
| `view/frontend/layout/default.xml` | Inject `Block\Hreflang` into `<head>` |

---

## HreflangResolverInterface

```php
interface HreflangResolverInterface
{
    /**
     * Layout handles this resolver applies to. ['*'] = every page.
     */
    public function getHandles(): array;

    /**
     * Return alternate URL entries for the current page across all active store views.
     *
     * Each entry: ['hreflang' => 'en-GB', 'url' => 'https://...', 'store_id' => 1]
     *
     * Return [] if not applicable to current page state
     * (e.g. no current product in registry, or no URL rewrites found).
     * Do NOT include language-only tags or x-default — the pool appends those.
     *
     * @return array<int, array{hreflang: string, url: string, store_id: int}>
     */
    public function getLinks(): array;
}
```

---

## StoreLocaleMap

A request-scoped service that builds the `store_id → [base_url, locale, language]` map once and caches it for the request lifetime.

```php
class StoreLocaleMap
{
    /** @var array<int, array{base_url: string, locale: string, language: string}>|null */
    private ?array $map = null;

    /** store_id => ['base_url' => 'https://...', 'locale' => 'en-GB', 'language' => 'en'] */
    public function getMap(): array;

    /** 'en_GB' → 'en-GB'  (Magento locale code to BCP 47) */
    public function formatLocale(string $magentoLocale): string;

    /** 'en-GB' → 'en' */
    public function extractLanguage(string $bcp47Locale): string;

    /** true if the store is in the excluded_store_ids config list or is inactive */
    public function isStoreExcluded(int $storeId): bool;
}
```

Locale code conversion: `str_replace('_', '-', $localeCode)`.  
Language extraction: `explode('-', $locale)[0]`.  
Only active stores (`is_active = 1`) are included. Excluded stores are filtered at this layer so all downstream code never sees them.

---

## UrlRewriteFetcher

A shared service that wraps `url_rewrite` queries, preventing duplication across resolvers and the sitemap generator.

```php
class UrlRewriteFetcher
{
    /**
     * Fetch URL rewrites for a single entity (used by head tag resolvers).
     *
     * @return array<int, array{store_id: int, request_path: string}>
     */
    public function fetchForEntity(string $entityType, int $entityId): array;

    /**
     * Fetch URL rewrites for all entities of a type in a single query (used by sitemap generator).
     * Returns: entity_id → [store_id => request_path]
     *
     * @param int[] $storeIds
     * @return array<int, array<int, string>>
     */
    public function fetchAllForType(string $entityType, array $storeIds): array;
}
```

The single-entity method is used on page requests (fast, per-request).  
The bulk method is used during sitemap generation (one query per entity type for the entire catalogue).

---

## ResolverPool

The pool handles everything above the raw link list that resolvers return:

1. Checks page handles against each resolver.
2. Calls `getLinks()` on the first matching resolver that returns a non-empty set.
3. **Adds language-only tags** — see section below.
4. **Appends x-default** using the configured store view.
5. Silently returns `[]` if fewer than two distinct hreflang values are present (single-store — no point outputting hreflang).

---

## Language-only tags

A language-only tag (e.g. `hreflang="en"`) acts as a catch-all for that language and is particularly useful when you have multiple regional variants (`en-GB`, `en-US`) and want to serve any unmatched English-speaking region.

**Automatic rule implemented in `ResolverPool`:**

- Count how many distinct `hreflang` values share the same base language (e.g. both `en-GB` and `en-US` have base language `en`).
- If exactly **one** store uses that base language: also output a language-only tag pointing to the same URL. This costs nothing and catches unmatched regions.
- If **two or more** stores share the same base language: do **not** automatically add a language-only tag — it would be ambiguous. The admin must configure this explicitly via the x-default setting if desired.

This logic lives entirely in `ResolverPool::appendLanguageOnlyTags()` and requires no config — it is deterministic from the store locale map.

**Config toggle:** Add `mageos_seo_general/hreflang/language_only_enabled` (Yes/No, default Yes) so it can be suppressed if the automatic behaviour is unwanted.

Example — three stores: `en-GB`, `en-US`, `de-DE`:

- `de-DE` is the only German store → auto-append `hreflang="de"` pointing to the same URL.
- `en-GB` and `en-US` share `en` → no automatic `en` tag added (ambiguous).

---

## Stores with different product catalogues

The URL rewrite table approach handles catalogue differences **by design** without any special logic:

- If a product is not assigned to a store, Magento does not generate a URL rewrite for that store.
- `fetchForEntity()` returns only rows that exist — no URL rewrite row means no alternate link for that store.
- The product's hreflang set therefore naturally contains only stores where the product is actually accessible.

**This is the correct behaviour.** A search engine should not be told that `https://uk.example.com/blue-t-shirt.html` has an alternate at `https://us.example.com/blue-t-shirt.html` if the product doesn't exist on the US store.

**Categories** work identically — if a category has no URL rewrite for a store (because it's not assigned to that store's root category), it is omitted.

**CMS pages** work identically — only stores with a URL rewrite row for that `entity_id` are included.

This behaviour should be validated with an integration test: a product on store A but not store B should produce a hreflang set containing only store A.

---

## CmsPageHreflangResolver

CMS pages need extra care because the home page has no URL rewrite entry.

**For the home page:** Detect by checking if the current request path is empty or `/`. In that case, each active store's base URL becomes its alternate. There is no URL rewrite query — just iterate `StoreLocaleMap::getMap()` and use `base_url` directly.

**For all other CMS pages:** Look up the current page's `entity_id` via `CmsPageResolver` (already in the module at `Model/Cms/CmsPageResolver.php`), then call `UrlRewriteFetcher::fetchForEntity('cms-page', $entityId)`. Only include stores where a URL rewrite row exists — this naturally handles CMS pages that are only published on some stores.

---

## Hreflang sitemap

### Why a dedicated sitemap

Magento's `Magento_Sitemap` module generates XML sitemaps during a cron job. Its internal XML builder does not natively support `<xhtml:link>` elements inside `<url>` blocks. Patching the core sitemap generator would require overriding several internal classes and would be fragile across upgrades.

The clean alternative — consistent with the `/llms.txt` pattern already in this module — is a dedicated cacheable endpoint:

**`/hreflang-sitemap.xml`** — served on request, fully cached, invalidated on entity changes.

Google explicitly supports submitting multiple sitemaps via the sitemap index or Search Console. This approach requires no changes to the core sitemap generator.

### Format

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml">

  <url>
    <loc>https://uk.example.com/blue-t-shirt.html</loc>
    <xhtml:link rel="alternate" hreflang="en-GB" href="https://uk.example.com/blue-t-shirt.html"/>
    <xhtml:link rel="alternate" hreflang="en-US" href="https://us.example.com/blue-t-shirt.html"/>
    <xhtml:link rel="alternate" hreflang="en"    href="https://uk.example.com/blue-t-shirt.html"/>
    <xhtml:link rel="alternate" hreflang="x-default" href="https://uk.example.com/blue-t-shirt.html"/>
  </url>

  ...one <url> block per entity per store view...

</urlset>
```

Every entity generates one `<url>` block **per store view** it appears on — each with the full set of alternates. This is what Google requires: every URL in the set must be present as a `<loc>` entry with identical `<xhtml:link>` annotations.

### SitemapGenerator

`Model/Hreflang/SitemapGenerator` builds the full XML string:

1. Load `StoreLocaleMap` for active store IDs and their base URLs.
2. Call `UrlRewriteFetcher::fetchAllForType('product', $storeIds)` — one query, returns `entity_id → [store_id => request_path]`.
3. Call `UrlRewriteFetcher::fetchAllForType('category', $storeIds)` — one query.
4. Call `UrlRewriteFetcher::fetchAllForType('cms-page', $storeIds)` — one query.
5. Add home page entries manually (one per store, using `base_url` directly).
6. For each entity, compute the full alternate set (applying the same language-only and x-default rules as `ResolverPool`).
7. Emit one `<url>` block per entity per store view.

Three queries total for the entire catalogue, regardless of product/category count. This is the only acceptable performance model for a sitemap that could cover tens of thousands of entities.

### Controller

`Controller/HreflangSitemap/Index` follows the pattern of `Controller/Llms/Index`:

- Returns `404` if hreflang is disabled in config OR if there is only one active store view (nothing useful to output).
- Returns the generated XML with `Content-Type: application/xml`.
- Sets `Cache-Control: public, max-age=86400` (24-hour TTL — longer than llms.txt, since the sitemap changes less frequently).
- Uses a dedicated Varnish/FPC cache tag `RS_HREFLANG_SITEMAP` for targeted invalidation.

### Cache invalidation

`Observer/InvalidateHreflangSitemapCache` listens on:

- `catalog_product_save_after` — product URL keys may have changed
- `catalog_category_save_after` — category URL keys may have changed
- `cms_page_save_after` — CMS page identifiers may have changed
- `store_save_after` — store locale or base URL may have changed (rare but important)

Register all four in `etc/events.xml`.

### Sitemap index integration

Submitting the hreflang sitemap to Google:

**Option A (recommended for now):** Submit `/hreflang-sitemap.xml` directly in Google Search Console as an additional sitemap alongside the main `sitemap.xml`. No code change needed.

**Option B (future):** Plugin into `Magento_Sitemap`'s index generator to append a `<sitemap>` entry pointing to `/hreflang-sitemap.xml`. This keeps everything in the sitemap index automatically but requires a plugin on `Magento\Sitemap\Model\Sitemap::generate()`.

---

## Config fields

Add a new group `hreflang` under `mageos_seo_general` in `system.xml`:

| Field | Path | Type | Scope | Default | Description |
| --- | --- | --- | --- | --- | --- |
| Enable Hreflang Tags | `mageos_seo_general/hreflang/enabled` | Yes/No | Store | 1 | Master on/off for `<head>` tags and sitemap |
| x-default Store View | `mageos_seo_general/hreflang/xdefault_store_id` | Select | Global | (default store) | Store view to tag as `x-default`. Source: `StoreViews` model. |
| Exclude Store Views | `mageos_seo_general/hreflang/excluded_store_ids` | Multiselect | Global | (none) | Store views to omit from all hreflang output (staging, internal stores). |
| Add Language-only Tags | `mageos_seo_general/hreflang/language_only_enabled` | Yes/No | Global | 1 | Auto-add `hreflang="en"` where a language has only one store view. |
| Enable Hreflang Sitemap | `mageos_seo_general/hreflang/sitemap_enabled` | Yes/No | Global | 1 | Serve `/hreflang-sitemap.xml`. |

The `xdefault_store_id`, `excluded_store_ids`, `language_only_enabled`, and `sitemap_enabled` fields are global scope only — they define site-wide relationships between stores.

Add `Model/Config.php` getters:

- `isHreflangEnabled(int|string|null $storeId = null): bool`
- `getHreflangXDefaultStoreId(): int`
- `getHreflangExcludedStoreIds(): array`
- `isHreflangLanguageOnlyEnabled(): bool`
- `isHreflangSitemapEnabled(): bool`

---

## Block and template

`Block/Hreflang.php`:

1. Returns `[]` immediately if `isHreflangEnabled()` is false.
2. Calls `ResolverPool::getLinks()`.
3. Returns `[]` if fewer than two links (single-store, nothing useful to output).
4. Overrides `_toHtml()` to return empty string on empty link set.

`hreflang.phtml`:

```php
<?php foreach ($block->getLinks() as $link): ?>
<link rel="alternate" hreflang="<?= $escaper->escapeHtmlAttr($link['hreflang']) ?>"
      href="<?= $escaper->escapeUrl($link['url']) ?>"/>
<?php endforeach; ?>
```

---

## di.xml wiring

```xml
<!-- Resolver pool -->
<type name="MageOS\Seo\Model\Hreflang\ResolverPool">
    <arguments>
        <argument name="resolvers" xsi:type="array">
            <item name="product"  xsi:type="object">MageOS\Seo\Model\Hreflang\Resolver\ProductHreflangResolver</item>
            <item name="category" xsi:type="object">MageOS\Seo\Model\Hreflang\Resolver\CategoryHreflangResolver</item>
            <item name="cmsPage"  xsi:type="object">MageOS\Seo\Model\Hreflang\Resolver\CmsPageHreflangResolver</item>
        </argument>
    </arguments>
</type>
```

Bridge modules add their own resolver and sitemap section provider via their own `di.xml` — the Seo module is never modified.

---

## layout/default.xml

Inject the hreflang block into `<head>` immediately after the canonical block:

```xml
<referenceContainer name="head.additional">
    <block name="mageos_seo.hreflang"
           class="MageOS\Seo\Block\Hreflang"
           template="MageOS_Seo::seo/hreflang.phtml"
           after="mageos_seo.canonical"/>
</referenceContainer>
```

---

## Cache considerations

**`<head>` block:** Fully FPC-cacheable. URL rewrites are not session-dependent. Output varies only by URL, which is already the FPC cache key. No `cacheable="false"` — ever.

**Sitemap:** 24-hour TTL. Cache tag `RS_HREFLANG_SITEMAP` invalidated by the four observers listed above. If a store's base URL or locale code changes in config, the admin must flush the full page cache manually (same as all config-driven head content changes).

---

## Open questions before implementation

1. **Are there currently multiple active store views?** If the site is single-store today, hreflang will silently produce no output. Implementation can proceed, but testing requires a second store view.

2. **What is the x-default?** Which store view is the primary version — the one that should be served to users whose region doesn't match any specific store? This determines the default value of `xdefault_store_id`.

3. **Custom page types (bridge modules):** Any custom URL type (vendor profiles, blog posts, etc.) needs its own `HreflangResolverInterface` implementation in a bridge module. The resolver pool is the extension point — register via `di.xml` without modifying this module.

4. **Should CMS pages be included in the sitemap?** Privacy policy, T&Cs, blog posts — some of these are identical across stores and should be in the hreflang sitemap; others may be store-specific. A future toggle (`mageos_seo_general/hreflang/sitemap_include_cms`) could control this, but default-on is sensible.

5. **Sitemap index integration:** Confirm whether Option A (manual submission via Search Console) is sufficient for launch, or whether Option B (automatic inclusion in sitemap index) is required from day one.

---

## Implementation order

1. `Api/HreflangResolverInterface.php`
2. `Model/Hreflang/StoreLocaleMap.php`
3. `Model/Hreflang/UrlRewriteFetcher.php`
4. `Model/Config/Source/StoreViews.php`
5. `Model/Config.php` — add five new getters
6. `etc/adminhtml/system.xml` — add hreflang group
7. `etc/config.xml` — add defaults
8. `Model/Hreflang/ResolverPool.php` — including language-only and x-default logic
9. `Model/Hreflang/Resolver/ProductHreflangResolver.php`
10. `Model/Hreflang/Resolver/CategoryHreflangResolver.php`
11. `Model/Hreflang/Resolver/CmsPageHreflangResolver.php` — including home page handling
12. `Block/Hreflang.php`
13. `view/frontend/templates/seo/hreflang.phtml`
14. `view/frontend/layout/default.xml` — inject block
15. `etc/di.xml` — wire resolver pool
16. — **Sitemap deliverable** —
17. `Model/Hreflang/SitemapGenerator.php`
18. `Controller/HreflangSitemap/Index.php`
19. `Observer/InvalidateHreflangSitemapCache.php`
20. `etc/frontend/routes.xml` — register `hreflang-sitemap` route
21. `etc/events.xml` — register four invalidation observers
22. — **Tests** —
23. Unit tests: `StoreLocaleMap`, `ResolverPool` (language-only logic, x-default, single-store no-output)
24. Unit test: `SitemapGenerator` output format and batch query logic
25. Integration test: product on two stores → correct `<link>` tags in `<head>`
26. Integration test: product on one store only → no hreflang output
27. Integration test: `GET /hreflang-sitemap.xml` → valid XML, correct `<xhtml:link>` entries

---

## Related files already in the module

| File | Relevance |
|---|---|
| `Model/Cms/CmsPageResolver.php` | Resolves current CMS page entity_id — reuse in `CmsPageHreflangResolver` |
| `Block/Canonical.php` | Pattern for a cacheable `<head>` block returning empty on no-op |
| `Controller/Llms/Index.php` | Pattern for the sitemap controller (config check, cache headers, plain response) |
| `Observer/InvalidateLlmsTxtCache.php` | Pattern for the sitemap cache invalidation observer |
| `Model/Config.php` | Add the five new hreflang config getters here |
| `etc/adminhtml/system.xml` | Add the new `hreflang` group alongside existing groups |
| `etc/events.xml` | Add the four new observer registrations here |
