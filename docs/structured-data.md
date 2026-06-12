# Structured Data (JSON-LD)

The module outputs JSON-LD `<script type="application/ld+json">` blocks in the `<head>` of every page. Each block contains one or more schema.org nodes assembled from registered providers.

---

## What gets output on each page type

| Page | Schema nodes |
|---|---|
| All pages | Organization, WebSite (with SearchAction), BreadcrumbList |
| Category page | CollectionPage, ItemList (if enabled) |
| Product page | Product (or sub-type), via the template system |
| CMS pages | WebPage |

The Organization and WebSite nodes appear on every page because they are site-wide identity data. The BreadcrumbList node is built from the breadcrumb block already rendered on the page, so it costs nothing extra.

---

## The compositor and provider system

All JSON-LD output flows through a single **Compositor** (`Model\StructuredData\Compositor`). It holds a pool of **providers** — each provider is responsible for one or more schema nodes on specific page types.

Each provider declares which layout handles it applies to:

```php
public function getHandles(): array
{
    return ['catalog_category_view'];  // only on category pages
    // or ['*'] for every page
}
```

The compositor checks the current page's active layout handles against each provider's declared handles, then calls `getSchemas()` on every matching provider. The combined output is serialised to JSON and written into the `<head>`.

---

## Provider pool

Built-in providers, in order:

| Provider | Handle | Output |
|---|---|---|
| `OrganizationProvider` | `*` | Organization node + WebSite node with SearchAction |
| `BreadcrumbListProvider` | `*` | BreadcrumbList (reads layout breadcrumbs block) |
| `CategorySchemaProvider` | `catalog_category_view` | CollectionPage + optional ItemList |
| `ProductSchemaProvider` | `catalog_product_view` | Dispatches to template builder pool |
| `CmsPageSchemaProvider` | CMS handles | WebPage node |

Bridge modules add their own providers by registering them in their own `di.xml` — the Seo module is never modified.

---

## Product schema registry

The product schema node is assembled in two stages, separated to allow bridge modules to enrich it without producing duplicate nodes:

1. `ProductSchemaProvider` builds the base product schema using the configured template builder and stores it in `SchemaRegistry`.
2. After this, `ProductVariantUrlSeo`'s enricher runs and mutates the schema in the registry — adding variant price/availability, updating the canonical URL, and appending `hasVariant` entries.
3. The compositor serialises whatever is in the registry as a single node.

This means the product JSON-LD block always contains exactly one Product node, regardless of how many providers contributed to it.

---

## Master on/off switch

**Stores → Configuration → MageOS → SEO → Structured Data → Enable JSON-LD Output**

When disabled, the `Block\JsonLd` block renders an empty string. No JSON-LD is output anywhere on the site. This is a per-store-view setting.

---

## XSS protection

Before the JSON is written to the page, the compositor runs:

```php
str_replace(['</', '<!--'], ['<\/', '<\!--'], $json)
```

This prevents `</script>` injection within JSON-LD. Do not bypass this or add your own raw `json_encode()` output to `<head>`.

---

## Caching

All JSON-LD output is fully FPC-cacheable. The `Block\JsonLd` block has no `cacheable="false"` attribute. Data is URL-keyed; Varnish and the FPC cache one variant per unique URL, so paginated category pages (`?p=2`) get their own correct cache entries.

Organisation data is cached via the standard config cache — changing Organisation settings invalidates the config cache which flushes the FPC.

---

## Adding a new provider

See [extending.md](extending.md) for step-by-step instructions.
