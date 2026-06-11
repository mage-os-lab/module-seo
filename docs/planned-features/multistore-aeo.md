# Planned Feature: Multistore Answer Engine Optimization (AEO)

**Status:** Planned — not yet implemented  
**Complexity:** Very high (multiple discrete sub-features)  
**Priority driver:** AI answer engines (Google AI Overviews, Bing Copilot, Perplexity, ChatGPT) now surface structured answers directly from schema.org data and `llms.txt`-style feeds — sites without rich AEO signals are increasingly invisible in AI-generated responses.

---

## What AEO means for this project

Search is bifurcating: traditional blue-link results coexist with AI-generated answer panels that synthesise information from structured data, `llms.txt` feeds, and scraped content. AEO is the discipline of ensuring that information about your store — products, FAQs, events, articles, opening hours, reviews — is machine-readable enough that answer engines can extract, attribute, and cite it correctly.

For a **multistore** setup, every structured data node must be correctly scoped to the store it describes. An `en-GB` store and an `en-US` store have different Organisation identities, different URLs, potentially different FAQ answers, different events, and different review counts. The patterns here follow the same store-scoped architecture as the rest of this module.

---

## Scope

Eight distinct sub-features, all sharing the same provider pool infrastructure:

1. **FAQ schema** — `FAQPage` structured data with a full per-store admin UI
2. **WebSite + SearchAction** — Sitelinks search box schema, per store
3. **LocalBusiness expansion** — Full address, geo, opening hours, contact in Organisation schema
4. **AggregateRating** — Product review scores from native Magento reviews, with a bridge extensibility point
5. **Event schema** — `Event` structured data via a bridge hook (data from a bridge events module)
6. **Article / BlogPosting schema** — Blog post structured data via a bridge hook
7. **Speakable schema** — Marks content for voice assistant and audio-response AEO
8. **llms.txt AEO enhancements** — Inject FAQs and structured answer patterns into existing llms.txt/llms-full.txt output

---

## Feature 1: FAQ schema with admin UI

### Why

FAQ rich results and "People Also Ask" panels are the most direct AEO signal: if your `FAQPage` schema answers a query, Google can surface the exact answer text in AI Overviews and featured snippets without the user visiting the site — and will attribute the source. Well-structured FAQs are also among the most reliable training signals for LLM-based answer engines.

### Data model

New table: `mage-os_seo_faq`

| Column | Type | Notes |
| --- | --- | --- |
| `entity_id` | int UNSIGNED PK | Auto-increment |
| `question` | varchar(512) | Not null |
| `answer` | text | Not null |
| `store_id` | smallint UNSIGNED | 0 = all stores, N = specific store view |
| `page_type` | varchar(16) | `'global'`, `'product'`, `'category'`, `'cms'` |
| `page_id` | int UNSIGNED nullable | Product ID, category ID, or CMS page ID |
| `sort_order` | smallint UNSIGNED | Default 0 |
| `is_active` | tinyint UNSIGNED | Default 1 |

This covers four content surfaces:

- **Global FAQs** (`page_type='global'`, `page_id=null`) — appear on every page of the store
- **Product FAQs** (`page_type='product'`, `page_id={product_id}`) — appear only on that product's page, merged with global FAQs
- **Category FAQs** (`page_type='category'`, `page_id={category_id}`) — appear on the category page
- **CMS page FAQs** (`page_type='cms'`, `page_id={cms_page_id}`) — appear on that CMS page

Store scoping: `store_id=0` rows appear on all stores unless overridden by a `store_id=N` row for the same `page_type` + `page_id` combination.

### Admin UI

- Menu location: **MageOS → SEO → FAQ Manager**
- Grid: question (truncated), page type, page ID, store view, sort order, status, actions
- Form fields:
  - Question (text input, required)
  - Answer (textarea or WYSIWYG, required)
  - Store View (multi-select, source: store views; "All Store Views" = `store_id=0`)
  - Applies To (dropdown: All Pages, Specific Product, Specific Category, Specific CMS Page)
  - Page selector (appears when Applies To ≠ All Pages — autocomplete by name/ID)
  - Sort Order (numeric)
  - Status (Enable / Disable)

### FaqSchemaProvider

Implements `StructuredDataProviderInterface`. Returns `['*']` from `getHandles()` — active on every page but loads context-specific FAQs based on current page type.

Resolution logic in `getSchemas()`:

1. Identify current page type (product/category/cms/other) from layout handles.
2. Load global FAQs for current `store_id`.
3. If on a product/category/cms page, load page-specific FAQs for that `entity_id` + `store_id`.
4. Merge: page-specific rows first (lower sort order wins within each group), then globals.
5. If the merged set is empty, return `[]` (no schema emitted).
6. Return a single `FAQPage` node:

```json
{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "What is your returns policy?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "We accept returns within 30 days of purchase for unused items in original packaging."
      }
    }
  ]
}
```

### FaqRepository

- `getForPage(string $pageType, ?int $pageId, int $storeId): array` — primary read method, returns merged rows ordered by sort_order
- `save(FaqInterface $faq): FaqInterface`
- `delete(FaqInterface $faq): void`
- `getById(int $entityId): FaqInterface`

Cache key pattern: `"faq_{$pageType}_{$pageId}_{$storeId}"`. Invalidated on FAQ save.

---

## Feature 2: WebSite + SearchAction schema

Every store view should emit a `WebSite` node with a `SearchAction` (which drives Google's Sitelinks search box in knowledge panels and answer results). This is a global AEO baseline — it tells answer engines what the site is and how to search it.

### WebSiteSearchActionProvider

Implements `StructuredDataProviderInterface`, handles `['*']` (every page), lowest priority so it is always present but never overrides page-specific schema.

```json
{
  "@context": "https://schema.org",
  "@type": "WebSite",
  "@id": "https://uk.example.com/#website",
  "url": "https://uk.example.com/",
  "name": "Completely Shropshire",
  "description": "Handmade and locally sourced goods from Shropshire makers",
  "inLanguage": "en-GB",
  "potentialAction": {
    "@type": "SearchAction",
    "target": {
      "@type": "EntryPoint",
      "urlTemplate": "https://uk.example.com/catalogsearch/result?q={search_term_string}"
    },
    "query-input": "required name=search_term_string"
  }
}
```

Data sources:

| Field | Source |
| --- | --- |
| `url` | `StoreManagerInterface::getStore()->getBaseUrl()` |
| `name` | Organisation name (or store name fallback) |
| `description` | Organisation description (or store config fallback) |
| `inLanguage` | Store locale code, BCP 47 formatted (`en_GB` → `en-GB`) |
| `urlTemplate` | Store base URL + Magento search route |

Config toggle: `mageos_seo_general/aeo/website_schema_enabled` (default on).

No new config fields required for the URL template — the search URL is deterministic from the store's base URL and Magento's standard route.

---

## Feature 3: LocalBusiness schema expansion

The existing `Organisation` model already stores name, description, logo, and social profiles. `LocalBusiness` is a schema.org subtype of `Organization` that adds physical presence signals — the kind of data that feeds Google Knowledge Panels and local answer results.

### New Organisation columns

Extend `mage-os_seo_organisation` with new nullable columns (all default null so existing data is unaffected):

| Column | Type | Notes |
| --- | --- | --- |
| `schema_type` | varchar(64) | `'Organization'` (default) or `'LocalBusiness'` or a subtype like `'Store'`, `'FoodEstablishment'` |
| `street_address` | varchar(255) | PostalAddress street |
| `address_locality` | varchar(128) | City / town |
| `address_region` | varchar(128) | County / state |
| `postal_code` | varchar(32) | Postcode |
| `address_country` | varchar(2) | ISO 3166-1 alpha-2 (`GB`, `US`) |
| `telephone` | varchar(64) | |
| `email` | varchar(255) | |
| `latitude` | decimal(10,7) | GeoCoordinates |
| `longitude` | decimal(10,7) | GeoCoordinates |
| `opening_hours` | text | JSON: `[{"dayOfWeek":["Monday"],"opens":"09:00","closes":"17:00"}]` |
| `price_range` | varchar(8) | `'£'`, `'££'`, `'£££'` — for LocalBusiness subtype |

The existing Organisation scope system (stores/websites/default fallback) applies to all new fields automatically — no schema change beyond adding the columns.

### Admin form additions

New "Local Presence" fieldset in the Organisation edit form:

- Schema Type (select: Organisation / Local Business / Store / Food Establishment / ...)
- Telephone, Email
- Address (street, locality, region, postcode, country)
- Latitude / Longitude (manual entry + future map picker)
- Opening Hours (dynamic rows: day of week checkboxes + open/close time per row)
- Price Range (select)

### LocalBusinessProvider

Implements `StructuredDataProviderInterface`, handles `['*']`.

When `schema_type = 'Organization'` (default), outputs the existing Organisation schema unchanged.

When `schema_type` is a `LocalBusiness` subtype, outputs the enriched node:

```json
{
  "@context": "https://schema.org",
  "@type": "Store",
  "@id": "https://uk.example.com/#organisation",
  "name": "Completely Shropshire",
  "url": "https://uk.example.com",
  "telephone": "+44 1743 000000",
  "email": "hello@example.com",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "14 The Square",
    "addressLocality": "Shrewsbury",
    "addressRegion": "Shropshire",
    "postalCode": "SY1 1AA",
    "addressCountry": "GB"
  },
  "geo": {
    "@type": "GeoCoordinates",
    "latitude": 52.7081,
    "longitude": -2.7549
  },
  "openingHoursSpecification": [
    {
      "@type": "OpeningHoursSpecification",
      "dayOfWeek": ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"],
      "opens": "09:00",
      "closes": "17:00"
    }
  ],
  "priceRange": "££"
}
```

This replaces (rather than supplements) the existing `OrganizationProvider` output when LocalBusiness fields are populated. `LocalBusinessProvider` should be registered at higher `sortOrder` than the existing `OrganizationProvider` in di.xml so it wins when `schema_type` is set.

---

## Feature 4: AggregateRating on products

Product schema with verified review scores is a strong AEO signal — AI answer engines treat average ratings as facts that can be surfaced in product comparison answers.

### AggregateRatingProviderInterface

```php
interface AggregateRatingProviderInterface
{
    /**
     * Return ['ratingValue' => '4.5', 'reviewCount' => '23', 'bestRating' => '5', 'worstRating' => '1']
     * or null if no reviews are available for this product.
     */
    public function getRating(int $productId, int $storeId): ?array;
}
```

### NativeAggregateRatingProvider

Default implementation using Magento's `review_entity_summary` table (the pre-aggregated ratings table):

```sql
SELECT rating_summary, reviews_count
FROM review_entity_summary
WHERE entity_pk_value = :productId
  AND store_id = :storeId
  AND entity_type = 1
```

`rating_summary` is a percentage (0–100). Convert to a 5-star scale: `ratingValue = round(rating_summary / 20, 1)`.

Return null if `reviews_count = 0` — do not emit an `AggregateRating` node with zero reviews.

### Integration into product schema

`AbstractBuilder::buildBase()` (the shared base node builder) gains an optional `AggregateRatingProviderInterface` constructor argument. If an `AggregateRating` node is available, it is merged into the product schema before `applyOverrides()`:

```json
"aggregateRating": {
  "@type": "AggregateRating",
  "ratingValue": "4.3",
  "reviewCount": "17",
  "bestRating": "5",
  "worstRating": "1"
}
```

Bridge modules (Yotpo, Trustpilot, etc.) register a replacement `AggregateRatingProviderInterface` binding via their own `di.xml` — the Seo module's code is never changed.

### Preference binding in di.xml

```xml
<preference for="MageOS\Seo\Api\AggregateRatingProviderInterface"
            type="MageOS\Seo\Model\Review\NativeAggregateRatingProvider"/>
```

---

## Feature 5: Event schema

Events are high-value AEO targets: Google surfaces event rich results prominently and AI answer engines extract event data for "what's on near me" queries. For a Shropshire artisan marketplace, Makers' markets, workshops, and craft fairs are directly relevant.

### Architecture

The Seo module defines the interface and provider; the bridge module provides the data.

### EventDataProviderInterface

```php
interface EventDataProviderInterface
{
    /**
     * Return events for the current page context, or an empty array.
     * Each entry must contain at minimum: name, startDate, location.
     *
     * @return array<int, array{
     *   name: string,
     *   description?: string,
     *   startDate: string,
     *   endDate?: string,
     *   location: array{name: string, address?: array},
     *   organizer?: array,
     *   image?: string,
     *   url?: string,
     *   eventStatus?: string,
     *   eventAttendanceMode?: string
     * }>
     */
    public function getEvents(int $storeId): array;

    /** Layout handles this provider applies to. */
    public function getHandles(): array;
}
```

### EventSchemaProvider

Implements `StructuredDataProviderInterface`. Iterates all registered `EventDataProviderInterface` implementations via an injectable pool. For each event returned, emits a full `Event` node:

```json
{
  "@context": "https://schema.org",
  "@type": "Event",
  "name": "Shropshire Makers' Spring Market",
  "startDate": "2026-04-18T10:00",
  "endDate": "2026-04-18T16:00",
  "eventStatus": "https://schema.org/EventScheduled",
  "eventAttendanceMode": "https://schema.org/OfflineEventAttendanceMode",
  "location": {
    "@type": "Place",
    "name": "Shrewsbury Market Hall",
    "address": {
      "@type": "PostalAddress",
      "streetAddress": "Market Hall, The Square",
      "addressLocality": "Shrewsbury",
      "addressRegion": "Shropshire",
      "postalCode": "SY1 1LH",
      "addressCountry": "GB"
    }
  },
  "organizer": {
    "@type": "Organization",
    "@id": "https://uk.example.com/#organisation"
  },
  "image": "https://uk.example.com/media/events/spring-market.jpg",
  "url": "https://uk.example.com/events/spring-market"
}
```

The `organizer` node references the store's Organisation by `@id` — linking events to the entity Google has already resolved for the store.

### di.xml registration (from bridge module)

```xml
<!-- YourVendor_YourModule/etc/di.xml -->
<type name="MageOS\Seo\Model\StructuredData\Provider\EventSchemaProvider">
    <arguments>
        <argument name="dataProviders" xsi:type="array">
            <item name="eventsProvider" xsi:type="object">
                YourVendor\YourModule\Model\EventDataProvider
            </item>
        </argument>
    </arguments>
</type>
```

---

## Feature 6: Article / BlogPosting schema

Blog posts attributed with `BlogPosting` schema receive direct content attribution in AI answer engines — the article appears as a source in AI-generated answers, with the `publisher` Organisation linked as the authority.

### Architecture

Same bridge pattern as Events. The Seo module defines the interface; the blog bridge module implements it for whatever blog extension is installed.

### ArticleDataProviderInterface

```php
interface ArticleDataProviderInterface
{
    /**
     * Return article data for the current request, or null if not on an article page.
     *
     * @return array{
     *   headline: string,
     *   description: string,
     *   datePublished: string,
     *   dateModified: string,
     *   image?: string,
     *   url: string,
     *   authorName?: string,
     *   keywords?: string[]
     * }|null
     */
    public function getArticle(int $storeId): ?array;

    /** Layout handles that identify a blog post page. */
    public function getHandles(): array;
}
```

### ArticleSchemaProvider

Implements `StructuredDataProviderInterface`. Iterates registered `ArticleDataProviderInterface` implementations. When an article is found, emits:

```json
{
  "@context": "https://schema.org",
  "@type": "BlogPosting",
  "@id": "https://uk.example.com/blog/best-makers-markets#article",
  "headline": "The Best Makers' Markets in Shropshire",
  "description": "Our guide to the finest artisan and craft markets across Shropshire.",
  "datePublished": "2026-05-10",
  "dateModified": "2026-06-01",
  "image": "https://uk.example.com/media/blog/makers-markets.jpg",
  "url": "https://uk.example.com/blog/best-makers-markets",
  "author": {
    "@type": "Organization",
    "@id": "https://uk.example.com/#organisation"
  },
  "publisher": {
    "@type": "Organization",
    "@id": "https://uk.example.com/#organisation",
    "name": "Completely Shropshire",
    "logo": {
      "@type": "ImageObject",
      "url": "https://uk.example.com/media/logo/logo.png"
    }
  },
  "keywords": ["Shropshire", "makers market", "artisan"]
}
```

The `author` and `publisher` both reference the store's Organisation `@id` — this is the correct pattern for brand-owned content and links content authority to the entity.

---

## Feature 7: Speakable schema

`Speakable` marks page sections as suitable for text-to-speech rendering by voice assistants and audio-mode AI responses (Google Assistant, Alexa skill integrations, some AI answer engines).

**Status note:** Google deprecated `Speakable` for standard web search results in 2023, but it remains valid for Google Actions, voice-first experiences, and is used by some AI answer engines when generating audio summaries. Include with a config toggle defaulting to **off** so stores can opt in when voice is a priority channel.

### SpeakableProvider

Implements `StructuredDataProviderInterface`. Returns `['*']` from `getHandles()` but is disabled by default.

When enabled, outputs:

```json
{
  "@context": "https://schema.org",
  "@type": "WebPage",
  "name": "Current page title",
  "speakable": {
    "@type": "SpeakableSpecification",
    "cssSelector": [".page-title", ".product-info-main .description", ".category-description"]
  },
  "url": "https://uk.example.com/current-page.html"
}
```

Config: `mageos_seo_general/aeo/speakable_enabled` (default 0) and `mageos_seo_general/aeo/speakable_css_selectors` (textarea, newline-separated list). The selector list defaults to a sensible set for Hyvä theme markup but can be customised per store.

---

## Feature 8: llms.txt AEO enhancements

The `llms.txt` and `llms-full.txt` documents are specifically designed for LLM crawlers. Two additions improve their AEO value:

### FaqLlmsSectionProvider

Implements `SectionProviderInterface`. Injects global FAQs into the documents using markdown format:

```text
## Frequently Asked Questions

**What is your returns policy?**
We accept returns within 30 days of purchase for unused items in original packaging.

**Do you ship internationally?**
We currently ship within the UK only.
```

`getConciseSection()` returns the first 5 FAQs (or fewer if fewer exist).  
`getFullSection()` returns all active global FAQs, sorted by sort_order.

Page-specific FAQs (product/category/CMS) are not included — the `llms.txt` document is a site-level summary, not page-specific.

Register in `etc/di.xml` against `MageOS\Seo\Model\LlmsTxt\LlmsTxtBuilder` → `sectionProviders`.

### Enhanced store context block

Update the existing llms.txt site summary to include:

```text
## Site Information
> Name: Completely Shropshire
> Base URL: https://uk.example.com
> Locale: en-GB
> Schema types: Organization, WebSite, Product, FAQPage, Event, BlogPosting, ...
> Hreflang sitemap: https://uk.example.com/hreflang-sitemap.xml
```

Adding the `hreflang-sitemap.xml` reference (when hreflang is enabled) and the extended schema type list (including types added by AEO features) improves LLM crawler understanding of the site's data model.

---

## Architecture: shared infrastructure

All eight sub-features use the existing `StructuredDataProviderInterface` → `Compositor` pool. No new base infrastructure is required for the schema output layer.

New shared infrastructure required:

| Component | Purpose |
| --- | --- |
| `mage-os_seo_faq` table | FAQ persistence |
| `FaqRepository` | FAQ data access, caching, store-scoped loading |
| Admin UI for FAQs | Editorial workflow |
| `AggregateRatingProviderInterface` | Extensibility hook for third-party review systems |

---

## New files to create

### FAQ

| File | Purpose |
| --- | --- |
| `Api/Data/FaqInterface.php` | Data model contract |
| `Api/FaqRepositoryInterface.php` | Repository contract |
| `Model/Faq.php` | Model |
| `Model/ResourceModel/Faq.php` | Resource model |
| `Model/ResourceModel/Faq/Collection.php` | Collection |
| `Model/FaqRepository.php` | Repository implementation |
| `Model/StructuredData/Provider/FaqSchemaProvider.php` | FAQPage schema output |
| `Model/LlmsTxt/FaqLlmsSectionProvider.php` | FAQ injection into llms.txt |
| `Controller/Adminhtml/Faq/Index.php` | Grid action |
| `Controller/Adminhtml/Faq/NewAction.php` | New record action |
| `Controller/Adminhtml/Faq/Edit.php` | Edit action |
| `Controller/Adminhtml/Faq/Save.php` | Save action |
| `Controller/Adminhtml/Faq/Delete.php` | Delete action |
| `Ui/DataProvider/Faq/ListingDataProvider.php` | Grid data provider |
| `Ui/DataProvider/Faq/Form/FaqDataProvider.php` | Form data provider |
| `view/adminhtml/ui_component/mage-os_seo_faq_listing.xml` | Grid UI component |
| `view/adminhtml/ui_component/mage-os_seo_faq_form.xml` | Form UI component |
| `view/adminhtml/layout/mage-os_seo_faq_index.xml` | Grid layout |
| `view/adminhtml/layout/mage-os_seo_faq_new.xml` | New form layout |
| `view/adminhtml/layout/mage-os_seo_faq_edit.xml` | Edit form layout |

### Schema providers

| File | Purpose |
| --- | --- |
| `Model/StructuredData/Provider/WebSiteSearchActionProvider.php` | WebSite + SearchAction |
| `Model/StructuredData/Provider/LocalBusinessProvider.php` | LocalBusiness with address/geo/hours |
| `Model/StructuredData/Provider/EventSchemaProvider.php` | Event schema (bridge data) |
| `Model/StructuredData/Provider/ArticleSchemaProvider.php` | BlogPosting schema (bridge data) |
| `Model/StructuredData/Provider/SpeakableProvider.php` | Speakable markup |

### Interfaces (bridge hooks)

| File | Purpose |
| --- | --- |
| `Api/AggregateRatingProviderInterface.php` | Review data contract |
| `Model/Review/NativeAggregateRatingProvider.php` | Magento native reviews implementation |
| `Api/EventDataProviderInterface.php` | Event data contract for bridge modules |
| `Api/ArticleDataProviderInterface.php` | Article data contract for bridge modules |

### Files to modify

| File | Change |
| --- | --- |
| `etc/db_schema.xml` | Add `mage-os_seo_faq` table; add 12 new columns to `mage-os_seo_organisation` |
| `etc/db_schema_whitelist.json` | Register new table and new Organisation columns |
| `etc/adminhtml/system.xml` | Add `aeo` config group (WebSite schema toggle, Speakable toggle + selectors) |
| `etc/config.xml` | Defaults for new config paths |
| `etc/di.xml` | Wire all new providers, `AggregateRatingProviderInterface` preference, FAQ section provider |
| `Model/Config.php` | Add getters: `isWebsiteSchemaEnabled`, `isSpeakableEnabled`, `getSpeakableCssSelectors`, `isFaqSchemaEnabled` |
| `Model/Organisation.php` | Getters/setters for 12 new address/geo/hours fields |
| `Api/Data/OrganisationInterface.php` | Constants and interface methods for new fields |
| `Model/StructuredData/Provider/OrganizationProvider.php` | Delegate to `LocalBusinessProvider` when schema_type is set |
| `Model/Product/Builder/AbstractBuilder.php` | Accept `AggregateRatingProviderInterface`, call in `buildBase()` |
| `etc/adminhtml/menu.xml` | Add FAQ Manager menu item under MageOS → SEO |
| `etc/adminhtml/acl.xml` | Add FAQ resource permissions |

---

## Config fields

New group `aeo` under `mageos_seo_general` in `system.xml`:

| Field | Path | Type | Scope | Default | Description |
| --- | --- | --- | --- | --- | --- |
| Enable WebSite Schema | `mageos_seo_general/aeo/website_schema_enabled` | Yes/No | Store | 1 | Emit WebSite + SearchAction on every page |
| Enable FAQ Schema | `mageos_seo_general/aeo/faq_schema_enabled` | Yes/No | Store | 1 | Emit FAQPage schema when FAQs exist for page |
| Enable Speakable | `mageos_seo_general/aeo/speakable_enabled` | Yes/No | Store | 0 | Emit Speakable schema (voice/audio AEO) |
| Speakable CSS Selectors | `mageos_seo_general/aeo/speakable_css_selectors` | Textarea | Store | (default set) | Newline-separated CSS selectors for speakable content |

FAQ content is managed in the FAQ Manager UI, not in system config.

---

## Entity relationship: @id cross-referencing

A key AEO pattern is linking all schema nodes to the same Organisation entity via `@id`. When an answer engine resolves the Organisation once, it can trust all nodes that reference it. This module uses `{storeBaseUrl}/#organisation` as the canonical `@id` for the Organisation node.

All schema providers that reference the organisation use this same ID:

```
WebSite     → publisher: { @id: .../#organisation }
BlogPosting → author + publisher: { @id: .../#organisation }
Event       → organizer: { @id: .../#organisation }
Product     → (via brand or seller field, future)
```

The `OrganizationProvider` / `LocalBusinessProvider` must declare `@id` consistently. Add `getOrganisationId(int $storeId): string` to `Model/Config.php` (returns `{baseUrl}/#organisation`) — all providers call this method rather than constructing the ID independently.

---

## Implementation order

Dependencies flow in this order — do not implement out of sequence:

1. `Api/Data/OrganisationInterface.php` — add 12 new field constants and methods
2. `Model/Organisation.php` — implement new getters/setters
3. `etc/db_schema.xml` — add 12 Organisation columns
4. `etc/db_schema_whitelist.json` — register new columns
5. `Model/Config.php` — add `getOrganisationId()` and four new AEO getters
6. `etc/adminhtml/system.xml` + `etc/config.xml` — add AEO config group
7. `Model/StructuredData/Provider/LocalBusinessProvider.php` — full LocalBusiness output
8. `Model/StructuredData/Provider/WebSiteSearchActionProvider.php`
9. `etc/di.xml` — register both providers
10. — **FAQ sub-feature** —
11. `etc/db_schema.xml` — add FAQ table
12. `Api/Data/FaqInterface.php` + `Api/FaqRepositoryInterface.php`
13. `Model/Faq.php` + `Model/ResourceModel/Faq.php` + `Collection.php`
14. `Model/FaqRepository.php`
15. `Controller/Adminhtml/Faq/` — all 5 actions
16. `Ui/DataProvider/Faq/` — listing + form providers
17. `view/adminhtml/ui_component/` — grid + form XML
18. `view/adminhtml/layout/` — 3 layout files
19. `etc/adminhtml/menu.xml` + `etc/adminhtml/acl.xml`
20. `Model/StructuredData/Provider/FaqSchemaProvider.php`
21. `Model/LlmsTxt/FaqLlmsSectionProvider.php`
22. `etc/di.xml` — register FAQ provider + section provider
23. — **AggregateRating sub-feature** —
24. `Api/AggregateRatingProviderInterface.php`
25. `Model/Review/NativeAggregateRatingProvider.php`
26. `Model/Product/Builder/AbstractBuilder.php` — inject provider, call in `buildBase()`
27. `etc/di.xml` — `AggregateRatingProviderInterface` preference binding
28. — **Bridge hook interfaces** —
29. `Api/EventDataProviderInterface.php`
30. `Api/ArticleDataProviderInterface.php`
31. `Model/StructuredData/Provider/EventSchemaProvider.php`
32. `Model/StructuredData/Provider/ArticleSchemaProvider.php`
33. `etc/di.xml` — register event + article providers (empty pools by default)
34. — **Speakable** —
35. `Model/StructuredData/Provider/SpeakableProvider.php`
36. `etc/di.xml` — register speakable provider
37. — **Tests** —
38. Unit: `FaqRepository` — store scoping, page-type merging, empty result handling
39. Unit: `NativeAggregateRatingProvider` — zero reviews returns null, percentage conversion
40. Unit: `ResolverPool`-style test for `EventSchemaProvider` with empty pool returning `[]`
41. Integration: product with reviews → AggregateRating in schema output
42. Integration: FAQ saved for product → FAQPage in schema on product page, absent on category page
43. Integration: FAQ store_id=0 appears on all stores; store_id=N appears only on store N

---

## Open questions before implementation

1. **Organisation schema type default** — should the default `schema_type` remain `'Organization'` for new stores, or should it be `'LocalBusiness'` given that Completely Shropshire has a physical presence? This decision affects what appears in Google's Knowledge Panel.

2. **Blog extension** — which blog extension will be used (Magefan, Aheadworks, etc.)? The `ArticleDataProviderInterface` implementation in the bridge module depends on the extension's data model. The Seo module's code is extension-agnostic.

3. **FAQ page IDs in admin UI** — the page selector for product/category/CMS FAQs needs an autocomplete. Confirm the preferred UX: free-text ID entry is simplest to implement; a name-based autocomplete (`Magento\Ui\Component\Form\Element\DataType\Text` with ajax source) gives a better editorial experience but takes longer to build.

4. **Review moderation** — the `review_entity_summary` table is populated by a Magento indexer. Confirm that the review indexer runs in this environment, otherwise `reviews_count` will be stale.

5. **Event data source** — what is the event data model and where does it live? This determines what the `EventDataProviderInterface` implementation looks like.
