# Planned Feature: llms.jsonl Product Catalog Endpoint

**Status:** Planned — not yet implemented  
**Module:** `MageOS_Seo` (extends the existing llms.txt feature)  
**Complexity:** Medium  
**Priority driver:** AI crawlers and LLM agents increasingly look for a machine-readable product catalog at a well-known URL. The `llms.txt` spec's companion format for structured data is JSON Lines: each line is a self-contained JSON-LD Product node, consumable by streaming parsers without loading the whole document into memory.

---

## What llms.jsonl is

A plain-text file served at `/llms.jsonl` where each line is a complete, valid JSON object describing one product. Unlike `llms.txt` (a narrative summary), `llms.jsonl` is a **structured catalog** — one product per line in JSON-LD format. AI agents, comparison tools, and LLM-based shopping assistants can stream it line by line without parsing HTML or calling REST endpoints.

**Spec status:** The official [llmstxt.org](https://llmstxt.org) specification covers only the Markdown `llms.txt` format — it has no mention of `llms.jsonl` or JSON Lines. The `llms.jsonl` format is an **emerging de-facto standard** that has been adopted by the AEO tooling ecosystem and AI crawlers (such as Google's product feed pipelines) independently of the Markdown spec. Implement it as a companion endpoint alongside `llms.txt` and `llms-full.txt`, not as a replacement.

The format complements:

- `llms.txt` — human-oriented store summary, minimal structured data
- `llms-full.txt` — category tree + schema type list
- `llms.jsonl` — full product catalog, machine-oriented, JSON-LD per line

Audit check (signal 3) validates: JSON Lines format validity, required fields present on every line, eCommerce fields populated, and locale/currency consistency matching the store config.

---

## Endpoint

**URL:** `/llms.jsonl`  
Served via the same controller-plus-URL-rewrite pattern as `/llms.txt`:

| Request path | Target path | Redirect type |
| --- | --- | --- |
| `llms.jsonl` | `llms/llms_jsonl/index` | No (custom) |

Add this rewrite in **Marketing → URL Rewrites** (same instruction pattern as the existing llms.txt setup).

**Response headers:**

- `Content-Type: application/x-ndjson` (NDJSON / JSON Lines MIME type)
- `Cache-Control: public, max-age=3600`
- `Content-Language: en-GB` (store locale)
- `Vary: Accept-Encoding`

Gzip compression via standard nginx `gzip_types` — no special controller handling needed.

Returns `404` if the config toggle is off.

---

## Per-line schema

Each line is a compact JSON-LD `Product` node. Lines must be valid JSON in isolation (no multi-line values). Example:

```json
{"@context":"https://schema.org","@type":"Product","@id":"https://uk.example.com/blue-ceramic-mug.html","name":"Blue Ceramic Mug","description":"Hand-thrown ceramic mug in cobalt blue. Dishwasher safe, holds 350ml.","sku":"MUG-001","url":"https://uk.example.com/blue-ceramic-mug.html","image":"https://uk.example.com/media/catalog/product/m/u/mug-blue.jpg","brand":{"@type":"Brand","name":"Potter's Workshop"},"category":"Ceramics > Mugs","inProductGroupWithID":null,"offers":{"@type":"Offer","priceCurrency":"GBP","price":"24.99","availability":"https://schema.org/InStock","url":"https://uk.example.com/blue-ceramic-mug.html"}}
```

### Required fields (must be present on every line)

| Field | Source |
| --- | --- |
| `@context` | Literal `"https://schema.org"` |
| `@type` | Literal `"Product"` |
| `@id` | Product canonical URL |
| `name` | Product name |
| `url` | Product canonical URL (same as `@id`) |
| `offers.@type` | Literal `"Offer"` |
| `offers.price` | Final price (special price if active, otherwise regular price) |
| `offers.priceCurrency` | Store currency code (`GBP`, `USD`, etc.) |
| `offers.availability` | `"https://schema.org/InStock"` or `"https://schema.org/OutOfStock"` |

### eCommerce fields (include when available)

| Field | Source | Notes |
| --- | --- | --- |
| `description` | Short description; fall back to first 300 chars of full description | Strip HTML tags |
| `sku` | Product SKU | |
| `image` | First product image, full URL | Use `getMediaUrl()` to build absolute URL |
| `brand` | `manufacturer` attribute label | Omit if empty |
| `category` | Deepest category breadcrumb, `" > "` separated | Batch-loaded, see performance section |
| `gtin13` / `gtin8` / `mpn` | Product attribute if mapped | Include whichever is populated |
| `offers.url` | Product canonical URL | Same as `@id` |
| `offers.priceValidUntil` | `special_price_to_date` if special price is active | ISO 8601 date string |

### Omitted fields

Do not include fields with empty or null values — they add byte weight with no signal value. Use `array_filter` with explicit null/empty-string check (not bare `array_filter`) to avoid dropping price `0.00`.

---

## Collection loading strategy

Loading a full product catalog efficiently requires careful use of the collection API. Do not load via product repository in a loop — that is O(N) DB queries.

### Approach: single collection with selective attributes

```php
$collection = $this->productCollectionFactory->create();
$collection->addStoreFilter($storeId);
$collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
$collection->addAttributeToFilter('visibility', ['in' => [
    Visibility::VISIBILITY_IN_CATALOG,
    Visibility::VISIBILITY_BOTH,
]]);
$collection->addAttributeToSelect([
    'name', 'short_description', 'description',
    'sku', 'price', 'special_price', 'special_from_date', 'special_to_date',
    'manufacturer', 'image',
]);
$collection->addUrlRewrite();
$collection->addMinimalPrice();
$collection->addFinalPrice();
```

`addUrlRewrite()` joins the `url_rewrite` table to get each product's canonical URL for the current store — one query, not one per product.

`addFinalPrice()` provides the final customer-group-agnostic price including special price logic.

### Category batch loading

Loading the deepest category name per product naively would require N queries (one per product). Instead:

1. Collect all unique category IDs from the loaded products via `$product->getCategoryIds()`.
2. Load a single `CategoryCollection` for those IDs with `addAttributeToSelect('name')` and `addAttributeToSelect('path')`.
3. Build a `[categoryId => 'Ceramics > Mugs']` map in memory.
4. Look up each product's deepest non-root category from the map.

### Stock availability

Use `Magento\CatalogInventory\Api\StockRegistryInterface::getProductStockStatus(int $productId)` after the collection load — this is pre-indexed and fast. Alternatively add a join via `Magento\CatalogInventory\Model\ResourceModel\Stock\Status::addStockDataToCollection()`.

### Performance thresholds

| Catalog size | Strategy |
| --- | --- |
| < 5,000 products | Load all, cache result, single request |
| 5,000 – 20,000 | Load all, stream output with `flush()` between batches, long TTL cache |
| > 20,000 | Warn in plan — consider moving to a cron-generated static file served from `pub/` |

For Completely Shropshire's scale (artisan marketplace), the < 5,000 threshold applies for the foreseeable future.

---

## Store scoping

The endpoint is fully store-scoped:

- Products filtered by the current store's catalog (visibility + status per store)
- Prices in the store's currency
- URLs are store-specific canonical URLs from `url_rewrite`
- `offers.priceCurrency` reflects `$store->getCurrentCurrencyCode()`
- `Content-Language` header reflects store locale

On a multistore setup, `/llms.jsonl` on `uk.example.com` returns UK products at GBP prices; on `us.example.com` it returns US products at USD prices.

---

## Caching

Same pattern as `llms.txt`:

- FPC cache with tag `MAGEOS_SEO_LLMS_JSONL`
- TTL: 3600 seconds (1 hour)
- Invalidated by `catalog_product_save_after` → `InvalidateLlmsJsonlCache` observer

The existing `InvalidateLlmsTxtCache` observer is the pattern — create a separate observer (or extend the existing one) for the new cache tag.

---

## Extensibility

A `JsonlLineProviderInterface` injectable pool allows bridge modules to contribute additional lines to the output, or to modify product lines before output.

```php
interface JsonlLineProviderInterface
{
    /**
     * Called once after all product lines are generated.
     * Return additional JSON-LD lines to append, or [].
     *
     * @return array<int, array> each element serialised to one JSON line
     */
    public function getAdditionalLines(int $storeId): array;
}
```

A bridge module could use this to append vendor `LocalBusiness` lines to the JSONL output, making the file a combined catalog + venue directory.

---

## Config fields

| Field | Path | Scope | Default | Description |
| --- | --- | --- | --- | --- |
| Enable llms.jsonl | `mageos_seo_llms/jsonl/enabled` | Store | 0 | Serve `/llms.jsonl`. Off by default until tested. |
| Include Description | `mageos_seo_llms/jsonl/include_description` | Store | 1 | Include `description` field (adds ~100 bytes per product) |
| Include Brand | `mageos_seo_llms/jsonl/include_brand` | Store | 1 | Include `brand` field from manufacturer attribute |
| Include Category | `mageos_seo_llms/jsonl/include_category` | Store | 1 | Include `category` breadcrumb (requires batch category load) |

Add getters to `Model/Config.php`: `isLlmsJsonlEnabled`, `isLlmsJsonlIncludeDescription`, `isLlmsJsonlIncludeBrand`, `isLlmsJsonlIncludeCategory`.

---

## New files to create

| File | Purpose |
| --- | --- |
| `Model/LlmsJsonl/JsonlBuilder.php` | Builds the full JSONL string from product collection |
| `Model/LlmsJsonl/ProductLineBuilder.php` | Converts a single product to a JSON-LD array |
| `Model/LlmsJsonl/CategoryPathResolver.php` | Batch-loads category paths for a set of category IDs |
| `Api/JsonlLineProviderInterface.php` | Bridge extensibility hook |
| `Controller/LlmsJsonl/Index.php` | Serves `/llms/llms_jsonl/index` |
| `Observer/InvalidateLlmsJsonlCache.php` | Invalidates `MAGEOS_SEO_LLMS_JSONL` on product save |

### Files to modify

| File | Change |
| --- | --- |
| `etc/config.xml` | Add defaults for four new config paths |
| `etc/adminhtml/system.xml` | Add `jsonl` group to `mageos_seo_llms` section (or create section if it doesn't exist) |
| `etc/frontend/routes.xml` | Register `llms` frontend route (check if already registered for llms.txt) |
| `etc/events.xml` | Register `InvalidateLlmsJsonlCache` on `catalog_product_save_after` |
| `etc/di.xml` | Wire `JsonlLineProviderInterface` pool (empty by default) |
| `Model/Config.php` | Add four new JSONL getters |

---

## Implementation order

1. `Model/Config.php` — four new getters
2. `etc/adminhtml/system.xml` + `etc/config.xml` — config UI and defaults
3. `Model/LlmsJsonl/CategoryPathResolver.php` — standalone utility, testable independently
4. `Model/LlmsJsonl/ProductLineBuilder.php` — single product → JSON-LD array
5. `Model/LlmsJsonl/JsonlBuilder.php` — collection load + orchestration
6. `Api/JsonlLineProviderInterface.php`
7. `Controller/LlmsJsonl/Index.php` — config check, build, cache headers, 404 fallback
8. `Observer/InvalidateLlmsJsonlCache.php`
9. `etc/events.xml` + `etc/di.xml` + `etc/frontend/routes.xml`
10. — **Tests** —
11. Unit: `ProductLineBuilder` — required fields always present, empty manufacturer omitted, special price used when active
12. Unit: `CategoryPathResolver` — batch loads correctly, returns empty string for uncategorised products
13. Integration: `GET /llms.jsonl` returns 200 with valid NDJSON, each line parses as JSON, required fields present
14. Integration: disabled toggle returns 404
15. Integration: product save triggers cache invalidation

---

## Open questions

1. **Route conflict** — does the existing `llms` frontend route (for `llms.txt`) already cover this, or does `llms_jsonl` need a separate route? Confirm by reading `etc/frontend/routes.xml` — if the existing `llms` routeFrontName covers it, just add a new controller action.

2. **Configurable products** — should configurable products appear once (as a single Product line with `AggregateOffer`) or should each simple child appear as its own line? Recommendation: one line per configurable product using `AggregateOffer` with `lowPrice`/`highPrice`, consistent with the existing product schema template approach.

3. **Out-of-stock products** — include them with `OutOfStock` availability, or exclude entirely? Recommendation: include — AI agents should know the product exists and can be watched/back-ordered.

4. **Admin confirmation of URL rewrite** — the `/llms.jsonl` URL rewrite must be created manually in Marketing → URL Rewrites. Should the module use an `UpgradeData` to create this automatically, or is manual setup acceptable (matching the llms.txt precedent)?
