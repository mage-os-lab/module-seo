# Structured Data (JSON-LD)

The module outputs JSON-LD `<script type="application/ld+json">` blocks in the `<head>` of every page. Each block contains one or more schema.org nodes assembled from registered providers.

---

## What gets output on each page type

| Page | Schema nodes |
|---|---|
| All pages | Organization, WebSite (with SearchAction), BreadcrumbList |
| Category page | CollectionPage, ItemList (if enabled) |
| Product page | Product (or sub-type), via the template system; a ProductGroup of its variants for a configurable product (see [Configurable products](#configurable-products)) |
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
| `OrganisationProvider` | `*` | Organization node + WebSite node with SearchAction |
| `BreadcrumbListProvider` | `*` | BreadcrumbList (reads layout breadcrumbs block) |
| `CategorySchemaProvider` | `catalog_category_view` | CollectionPage + optional ItemList |
| `ProductSchemaProvider` | `catalog_product_view` | Dispatches to template builder pool |
| `CmsPageSchemaProvider` | CMS handles | WebPage node |

Bridge modules add their own providers by registering them in their own `di.xml` — the Seo module is never modified.

---

## Product schema registry

The product schema node is assembled in stages, so another module can adjust it without producing a duplicate node:

1. `ProductSchemaProvider` builds the node with the category's template builder, turns a configurable product's node into a ProductGroup of its variants (below), and stores the result in `SchemaRegistry`.
2. Any provider that runs after it can change the node in the registry (`merge()`, `mergeNested()`).
3. The compositor serialises whatever is in the registry as a single node, after every provider has run.

This means the product JSON-LD block always contains exactly one product node, regardless of how many providers contributed to it.

---

## Configurable products

A configurable product is described the way Google's [product variant guidance](https://developers.google.com/search/docs/appearance/structured-data/product-variants) asks, from the children the storefront sells: core's `ConfigurableOptionsProviderInterface`, after its filters (enabled children, and in-stock ones unless out-of-stock products are displayed).

**Up to `has_variant_max` sellable children** (Stores → Configuration → MageOS → SEO → Structured Data → *Most Variants per Configurable Product*, default 50) — a `ProductGroup`:

```json
{
  "@type": "ProductGroup",
  "@id": "https://example.com/tee.html#product",
  "name": "Tee",
  "url": "https://example.com/tee.html",
  "sku": "TEE",
  "description": "A soft cotton tee.",
  "productGroupID": "TEE",
  "variesBy": ["https://schema.org/size"],
  "hasVariant": [
    {
      "@type": "Product",
      "name": "Tee S",
      "sku": "TEE-S",
      "gtin13": "4006381333931",
      "description": "A soft cotton tee.",
      "image": "https://example.com/media/catalog/product/t/e/tee-s.jpg",
      "size": "S",
      "additionalProperty": [{ "@type": "PropertyValue", "name": "Fit", "value": "Regular" }],
      "offers": {
        "@type": "Offer",
        "url": "https://example.com/tee.html?size=167&fit=201",
        "price": "10.00",
        "priceCurrency": "USD",
        "availability": "https://schema.org/InStock"
      }
    }
  ]
}
```

- **The group** keeps what the template built — name, description, images, brand, aggregate rating — with `ProductGroup` in `Product`'s place (beside a template's own type: `["ProductGroup", "Book"]`), `productGroupID` set to the SKU, and **no `offers`**: Google wants offers on the variants only. A property the variants differ by is taken off the group, where a template may have set it from the parent product.
- **Each variant** is a `Product` with its name, SKU, the group's description (children rarely have their own, and it is the text the page shows), its first gallery image (else the group's), what it varies by, and its own offer. The offer comes from the same `Model\Product\OfferBuilder` as every other product's — price, availability, `priceValidUntil`, and every registered offer enricher.
- **GTIN.** When the category enables its template's GTIN field (`gtin13`), each variant carries its own GTIN, read in one load from the first non-empty of the `gtin13`, `gtin`, `barcode` and `ean` attributes and validated like every GTIN the module writes — one that fails its check digit is left out.

**What a variant varies by** comes from the product's own configurable attributes; there is no attribute map to maintain. Google's `variesBy` accepts six properties only, so:

| Configurable attribute code | Written on the variant as | In `variesBy` |
|---|---|---|
| `color`, `size`, `material`, `pattern` | that property: `"size": "S"` | yes |
| `suggested_gender` (or `suggestedGender`) | `audience.suggestedGender` on a `PeopleAudience` | yes |
| `suggested_age` (or `suggestedAge`), option labels that are numbers | `audience.suggestedAge` as a `QuantitativeValue` in years | yes |
| `suggested_age` with labels that are not numbers ("Adult") | an `additionalProperty` — a `QuantitativeValue` can't hold them | no |
| any other code — `gender`, `fit`, `shoe_width`, … | an `additionalProperty`: the attribute's store label and the option's | no |

Codes are matched case- and underscore-insensitively. Values are the options' store-view labels.

**Variant URLs** are the product's URL with `?{attribute_code}={option_id}` for each configurable attribute — Google's single-page pattern. The page keeps one canonical URL, and Luma's swatch renderer preselects the options the query names. A theme whose option widget reads something else (Luma's dropdown-only widget reads the URL hash), or a store that gives variants pages of their own, replaces the rule with a preference for `MageOS\Seo\Api\ProductVariantUrlResolverInterface`:

```xml
<preference for="MageOS\Seo\Api\ProductVariantUrlResolverInterface"
            type="YourVendor\Module\Model\VariantUrlResolver"/>
```

**More sellable children than `has_variant_max`, or the setting at 0** — one `Product` whose offer is an `AggregateOffer` from the lowest child's price to the highest, rather than a variant list cut short that would misstate what the store sells. Children that all share one price keep a single `Offer`.

Why a limit at all: Google sets none. Each variant adds an offer to the page — and a salability check on a product page that isn't cached — so the setting bounds page weight and build time for configurables with very many children.

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
