# Canonical URLs

The module manages canonical `<link rel="canonical">` tags to ensure search engines index the correct URL for each page and avoid duplicate content penalties.

---

## Why this module manages canonicals

Magento adds a canonical tag automatically for product and category pages. However, when a product variant URL is active (via a product variant URL module), the correct canonical is the variant URL — not the base product URL. Magento's default canonical and the variant canonical would both be present in the `<head>`, which is invalid.

`CanonicalUrlManager::setCanonical()` solves this by:

1. Removing every existing canonical link asset (matched by asset content type `canonical`,
   never by URL pattern — the asset collection also holds all CSS/JS/image assets).
2. Adding the correct canonical URL.

This service is the single authoritative place for canonical manipulation across all MageOS SEO module components. Nothing inside MageOS_Seo itself calls it — it is a bridge API for sibling modules (e.g. product-variant URL modules).

---

## Where canonicals are set

| Page type | Who sets the canonical | URL used |
|---|---|---|
| Product page (no variant) | Magento core (`catalog/seo/product_canonical_tag`) | Product URL |
| Product page (variant active) | Bridge module via `CanonicalUrlManager` | Variant-specific URL |
| Category page | Magento core (`catalog/seo/category_canonical_tag`) | Category URL |
| Home page | `Block\Canonical` (fallback) | Store base URL |
| CMS page | `Block\Canonical` (fallback) | Store base URL + page identifier |

The fallback block only renders on the home page and `cms_page_view`, and only when no other
canonical asset is already present (detected by asset content type). It deliberately stays off
search, cart, checkout and account pages — canonicalising those URLs would legitimise duplicate
URLs instead of consolidating them — and never derives URLs from the request Host header.

Core's product/category canonical tags are **disabled by default** in Magento; enable them under
**Stores → Configuration → Catalog → Catalog → Search Engine Optimization** if you want catalog
canonicals and no bridge module manages them.

---

## How it works in code

`CanonicalUrlManager` is a simple service with one public method:

```php
$canonicalManager->setCanonical(
    $canonicalUrl,   // the URL to use as canonical
    $pageConfig      // Magento\Framework\View\Page\Config
);
```

The manager removes every asset in the page asset collection whose content type is `canonical`
(this is how both core and this module register canonical link assets), then adds the new one.
The legacy third `$urlKey` parameter is retained for backward compatibility but ignored.

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

Magento core does not emit canonicals for CMS pages, so `Block\Canonical` covers them: it
resolves the current page via `CmsPageResolver` and builds the canonical from the store base URL
plus the page identifier (the home page canonicalises to the bare base URL). This does not
interact with `CanonicalUrlManager`.

---

## Multi-store canonicals

Canonical URLs are always absolute. The product and category URL methods return store-aware absolute URLs, so canonicals are correct for each store view without any extra configuration.

For stores sharing a product catalogue (e.g. the same product visible on two store views), Magento's built-in canonical handling applies — each store view's canonical points to that store view's URL. Telling search engines that those URLs are versions of one another is the job of hreflang alternates, which this module publishes both in the page head and as a sitemap — see [hreflang.md](hreflang.md).
