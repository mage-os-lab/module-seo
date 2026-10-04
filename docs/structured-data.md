# Structured Data (JSON-LD)

The module outputs JSON-LD `<script type="application/ld+json">` blocks in the `<head>` of every page. Each block contains one or more schema.org nodes assembled from registered providers.

---

## What gets output on each page type

| Page | Schema nodes |
|---|---|
| All pages | Organization, WebSite (with SearchAction), BreadcrumbList |
| Category page | CollectionPage, ItemList (if enabled) |
| Product page | Product (or sub-type), via the template system; a ProductGroup of its variants for a configurable product (see [Configurable products](#configurable-products)) |
| CMS pages, the home page included | WebPage, `@id` `{url}#webpage` — the home page's URL is the store base URL (see [canonical-urls.md](canonical-urls.md#cms-pages)) |

The Organization and WebSite nodes appear on every page because they are site-wide identity data. The BreadcrumbList node follows the trail the page shows:

- **Hyvä** exposes its breadcrumbs block's crumbs, and the node is built from them — the trail as rendered.
- **Luma** keeps its crumbs to itself and draws the product trail in JavaScript, so the node is rebuilt from core's catalog breadcrumb path. On a product page it has the category only when the URL carries one (a category-path product URL); otherwise it is **Home › Product**, as Luma shows it. Core would otherwise supply the category the visitor last browsed, from their session, and the page cache would keep that one visitor's trail for everyone.
- The last crumb has no `item`: [Google documents](https://developers.google.com/search/docs/appearance/structured-data/breadcrumb) that it is not required there, and uses the page's own URL.

A page that builds its own breadcrumb schema can switch this one off with the `excludedHandles` argument (see [extending.md](extending.md)).

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
| `CmsPageSchemaProvider` | `cms_page_view` (every CMS page and the home page) | WebPage node at `CmsPageResolver::currentUrl()` |
| `ArticleSchemaProvider` | `*` | BlogPosting, from the first `ArticleDataProviderInterface` with an article for the page — none registered by default |
| `EventSchemaProvider` | `*` | One Event per event from every matching `EventDataProviderInterface` — none registered by default |
| `SpeakableProvider` | `catalog_product_view` | With Speakable on: the product page's WebPage, carrying `speakable` (see [Speakable](#speakable)) |

Bridge modules add their own providers by registering them in their own `di.xml` — the Seo module is never modified.

---

## Product schema registry

The product schema node is assembled in stages, so another module can adjust it without producing a duplicate node:

1. `ProductSchemaProvider` builds the node with the category's template builder, turns a configurable product's node into a ProductGroup of its variants (below), and stores the result in `SchemaRegistry`.
2. Any provider that runs after it can change the node in the registry (`merge()`, `mergeNested()`).
3. The compositor serialises whatever is in the registry as a single node, after every provider has run.

This means the product JSON-LD block always contains exactly one product node, regardless of how many providers contributed to it.

---

## An offer's priceValidUntil

`priceValidUntil` is published only where the catalogue has the date, never made up. An offer
carries it when both of these hold:

- the product has a **special price** with a value, and
- its **special price end date** (`special_to_date`) is today or later, in the store's time.

The end date is inclusive: a special price ending today holds for the whole of that day, so the date
is still published on its last day.

In every other case there is no `priceValidUntil`: no special price, a special price without an end
date, an end date that has passed, or an end date without a special price. Google accepts an Offer
without one.

Earlier versions filled the gap with a synthetic date, today plus **Price Valid Until (months)**.
That date promised a validity nothing in the store backed, so it and its setting have been removed.
Values saved for the old setting stay in `core_config_data` and are no longer read.

`Model\Product\OfferBuilder` decides this for every offer, a variant's included. To publish a date
from another source — a catalog price rule's end, a campaign calendar — register an
`Api\OfferEnricherInterface` that returns `priceValidUntil` for the products it knows a date for.
Enrichers are merged into the offer after it is built, so the enricher's date is the one published.

---

## Configurable products

A configurable product is described the way Google's [product variant guidance](https://developers.google.com/search/docs/appearance/structured-data/product-variants) asks, from the children the storefront sells: core's `ConfigurableOptionsProviderInterface`, after its filters (enabled children, and in-stock ones unless out-of-stock products are displayed).

**Up to `has_variant_max` sellable children** (Stores → Configuration → MageOS SEO → SEO → Structured Data (JSON-LD) → *Most Variants per Configurable Product*, default 50) — a `ProductGroup`:

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

## Speakable

**Stores → Configuration → MageOS SEO → SEO → Answer Engine Optimization (AEO) → Enable Speakable Schema** (off by default) marks page sections for text-to-speech, with the CSS selectors in **Speakable CSS Selectors** (defaults: `.page-title`, `.product.attribute.overview`, `.category-description`).

The `speakable` property sits on the node that describes the page — Google: *"Speakable is used by the Article or Webpage object"* — built once by `Model\StructuredData\SpeakableSpecification`:

| Page | Node carrying `speakable` |
|---|---|
| CMS page, home page included | its `WebPage`, `{url}#webpage` |
| Category | its `CollectionPage`, `{url}#collectionpage` |
| Blog post (from a bridge's article data) | its `BlogPosting`, `{url}#article` |
| Product | a `WebPage` for the page, `{url}#webpage`, whose `mainEntity` is the product node `{url}#product` — `speakable` isn't a Product property |
| Anything else (search, contact, account…) | none — there is no page node to carry it |

```json
{
  "@type": "WebPage",
  "@id": "https://example.com/tee.html#webpage",
  "url": "https://example.com/tee.html",
  "name": "Tee",
  "mainEntity": { "@id": "https://example.com/tee.html#product" },
  "speakable": { "@type": "SpeakableSpecification", "cssSelector": [".page-title", ".product.attribute.overview"] }
}
```

Only `cssSelector` is emitted: Google takes `cssSelector` or `xPath`, never both. Google's own use of speakable is narrow — beta, users in the U.S. with Google Home devices set to English, news content read aloud by Google Assistant — which is why the setting is off by default.

---

## Master on/off switch

**Stores → Configuration → MageOS SEO → SEO → Structured Data (JSON-LD) → Enable JSON-LD Output**

When disabled, the `Block\JsonLd` block renders an empty string. No JSON-LD is output anywhere on the site. This is a per-store-view setting.

---

## XSS protection

The compositor encodes the JSON with:

```php
json_encode($schemas, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
```

`JSON_HEX_TAG` and `JSON_HEX_AMP` write `<`, `>` and `&` as `<`, `>` and `&`, so
neither `</script>` nor `<!--` can appear inside the inline `<script>` and the output stays valid
JSON. A `str_replace()` after encoding cannot promise both. `Block\ItemListJsonLd`, and MageOS_Faq's
`Block\FaqJsonLd`, encode the same way. Do not bypass this or add your own raw `json_encode()`
output to `<head>`.

---

## Caching

All JSON-LD output is fully FPC-cacheable. The `Block\JsonLd` block has no `cacheable="false"` attribute. Data is URL-keyed; Varnish and the FPC cache one variant per unique URL, so paginated category pages (`?p=2`) get their own correct cache entries.

Organisation data is not configuration: it comes from its own table, `mageos_seo_organization`. `Block\JsonLd` sits on every page and carries the Organisation cache tag (`Model\Organization::CACHE_TAG`, `mageos_seo_organization`), so every cached page is tagged with it. Saving or deleting an Organisation record cleans that tag — core's `AbstractModel` dispatches `clean_cache_by_tags` after either — and the FPC and Varnish drop every page that showed the old values. No cache type is invalidated and nothing needs flushing by hand.

---

## Adding a new provider

See [extending.md](extending.md) for step-by-step instructions.
