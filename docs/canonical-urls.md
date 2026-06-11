# Canonical URLs

The module manages canonical `<link rel="canonical">` tags to ensure search engines index the correct URL for each page and avoid duplicate content penalties.

---

## Why this module manages canonicals

Magento adds a canonical tag automatically for product and category pages. However, when a product variant URL is active (via a product variant URL module), the correct canonical is the variant URL — not the base product URL. Magento's default canonical and the variant canonical would both be present in the `<head>`, which is invalid.

`CanonicalUrlManager::setCanonical()` solves this by:

1. Scanning the page asset collection for any existing canonical that matches the entity's URL key.
2. Removing the default Magento canonical.
3. Adding the correct canonical URL.

This service is the single authoritative place for canonical manipulation across all MageOS SEO module components.

---

## Where canonicals are set

| Page type | Who sets the canonical | URL used |
|---|---|---|
| Product page (no variant) | `ProductRobotsMetaPlugin` / `Block\Canonical` | Product URL from `getProductUrl()` |
| Product page (variant active) | `ProductVariantUrlSeo` enricher | Variant-specific URL |
| Category page | `Block\Canonical` | Category URL |
| CMS / home page | Magento core | Standard Magento canonical |

---

## How it works in code

`CanonicalUrlManager` is a simple service with one public method:

```php
$canonicalManager->setCanonical(
    $canonicalUrl,   // the URL to use as canonical
    $pageConfig,     // Magento\Framework\View\Page\Config
    $urlKey          // the entity's URL key (used to find and remove the default)
);
```

The `$urlKey` parameter is used to identify the default Magento canonical in the asset collection. The asset identifier typically ends with `/{urlKey}` or `/{urlKey}.html`. The manager removes any asset whose identifier ends with either of those patterns, then adds the new canonical.

---

## Duplicate canonical prevention

Without this logic, a variant product page could contain two canonical tags:

```html
<!-- Added by Magento core -->
<link rel="canonical" href="https://example.com/blue-t-shirt.html"/>
<!-- Added by the SEO module -->
<link rel="canonical" href="https://example.com/blue-t-shirt/blue.html"/>
```

With the manager, the first one is removed before the second is added, so only one canonical is ever present.

---

## CMS pages

Canonical management for CMS pages is handled by Magento's core CMS module. The `CmsPageResolver` reads the current CMS page and resolves its canonical URL using the standard Magento approach. This does not interact with `CanonicalUrlManager`.

---

## Multi-store canonicals

Canonical URLs are always absolute. The product and category URL methods return store-aware absolute URLs, so canonicals are correct for each store view without any extra configuration.

For stores sharing a product catalogue (e.g. the same product visible on two store views), Magento's built-in canonical handling applies — each store view's canonical points to that store view's URL. If cross-store-view canonical consolidation is needed, that requires hreflang and alternate link management, which is out of scope for this module.
