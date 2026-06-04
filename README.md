# MageOS_Seo

A Magento 2 SEO module providing JSON-LD structured data, Open Graph meta tags, canonical URL management, per-category/product configuration, robots meta control, and AI-crawler discoverability via `/llms.txt`.

Requires [`reessolutions/module-base`](https://github.com/reessolutions/module-base).

---

## Features

- **JSON-LD structured data** — Organization, WebSite, BreadcrumbList, CollectionPage, and per-product schemas output as `<script type="application/ld+json">` in `<head>`
- **16 product schema templates** — GenericProduct, Food, Apparel, Jewelry, HomeDecor, Book, Software, Toy, HealthProduct, Cosmetics, Pet, ArtAndCraft, ElectronicsSimple, Tool, Stationery, LocalExperience
- **Open Graph meta tags** — og:title, og:description, og:image, og:type on product and category pages
- **Canonical URL management** — automatic canonicals on product, category, CMS, and home pages; deduplicates if a canonical is already present
- **Robots meta defaults** — global INDEX/FOLLOW defaults for product and category pages, overridable per category or product
- **Per-category SEO config** — schema template, enabled optional fields, field value overrides, ItemList toggle, robots meta
- **Per-product SEO config** — field value overrides and robots meta (editable in the product edit form)
- **AI discoverability** — `/llms.txt` (concise) and `/llms-full.txt` (extended, with category tree) for LLM crawlers

---

## Requirements

- PHP 8.0+
- Magento 2.4.x
- `reessolutions/module-base`

---

## Installation

```bash
composer require reessolutions/module-seo
bin/magento module:enable MageOS_Seo
bin/magento setup:upgrade
bin/magento cache:flush
```

---

## Important: configure Organisation before going live

> **The module works immediately after install, but structured data, `/llms.txt`, and the Organisation JSON-LD node will be empty until you fill in the Organisation details.**

Go to **Marketing > SEO > Organisation** and complete all fields before putting the site live.

| Field | Purpose |
| --- | --- |
| Name | Your organisation's display name (falls back to store name in llms.txt if blank) |
| URL | Canonical URL of your organisation (e.g. `https://example.com`) |
| Description | Short tagline — shown in JSON-LD and at the top of `/llms.txt` |
| Organisation type | Schema.org `@type`: Organization, Corporation, NGO, etc. |
| Logo path | Media-relative path to your logo image |
| Logo width / height | Pixel dimensions — required for valid Organization schema |
| Social profiles | Social profile URLs (Twitter, LinkedIn, etc.) |
| Contact point | contactType, email, availableLanguage for the ContactPoint node |

Without a Name and URL saved, the Organization node in JSON-LD will render with empty values, which search engines and validators will flag as invalid.

---

## Admin configuration

**Marketing > SEO > Configuration** (`Stores > Configuration > MageOS > SEO`)

### Open Graph Tags

| Setting | Default | Notes |
| --- | --- | --- |
| Enable OG Tags | Yes | Outputs og:title, og:description, og:image, og:type on product and category pages |

### Structured Data (JSON-LD)

| Setting | Default | Notes |
| --- | --- | --- |
| Enable JSON-LD Output | Yes | Master switch for all `<script type="application/ld+json">` output |
| Default Product Schema Template | GenericProduct | Used for products in categories with no template configured |
| Enable Category ItemList Schema | Yes | Outputs an ItemList schema on category pages |
| Category ItemList Max Items | 36 | Matches the default toolbar page size |
| hasVariant Max Entries | 50 | Limits variant nodes in a product's hasVariant array |
| Price Valid Until (months) | 12 | Sets `priceValidUntil` on Offer nodes relative to today |

### AI Discoverability (llms.txt)

| Setting | Default | Notes |
| --- | --- | --- |
| Enable /llms.txt | Yes | Serves a concise site summary at `/llms.txt` |
| Enable /llms-full.txt | Yes | Serves an extended document with full category tree at `/llms-full.txt` |

### Robots Meta Defaults

| Setting | Default |
| --- | --- |
| Product pages | INDEX,FOLLOW |
| Category pages | INDEX,FOLLOW |

---

## Per-category SEO

In the category edit form (Catalog > Categories), an **Advanced SEO** fieldset is injected with:

- **Schema template** — which of the 16 product schema templates to use for products in this category
- **Enabled optional fields** — which optional schema fields to include (e.g. `color`, `material`, `gtin`)
- **Field overrides** — hard-coded values that override product attribute data in the schema output
- **ItemList schema** — enable/disable/inherit-global for this category
- **Robots meta** — override the global default for this category

Template and field settings are inherited from ancestor categories when no setting is configured at the current level, walking up the category path to the root.

---

## Per-product SEO

In the product edit form (Catalog > Products), an **Advanced SEO** tab is injected with:

- **Field overrides** — store-specific hard-coded values that override what is read from product attributes in the schema output
- **Robots meta** — override the global default for this product and store view

---

## Product schema templates

Each template maps to a `ProductSchemaBuilderInterface` implementation and produces a `schema.org/Product` (or sub-type) node tailored to that product category:

| Code | Label | Schema type |
| --- | --- | --- |
| GenericProduct | Generic Product | Product |
| Food | Food & Grocery | FoodProduct |
| Apparel | Clothing & Apparel | Apparel |
| Jewelry | Jewelry | Jewelry |
| HomeDecor | Home Decor & Furniture | Product |
| Book | Books | Book |
| Software | Software & Apps | SoftwareApplication |
| Toy | Toys & Games | Product |
| HealthProduct | Health & Wellness | HealthAndBeautyBusiness |
| Cosmetics | Beauty & Cosmetics | Product |
| Pet | Pet Supplies | Product |
| ArtAndCraft | Art & Craft | VisualArtwork |
| ElectronicsSimple | Electronics | Product |
| Tool | Tools & Hardware | Product |
| Stationery | Stationery & Office | Product |
| LocalExperience | Local Experience | Product |

The default template (`GenericProduct`) is used when no template is configured for the product's category. You can change the default under **Configuration > Structured Data > Default Product Schema Template**.

---

## AI discoverability (llms.txt)

Two plain-text documents are served at known URLs so LLM crawlers and AI commerce agents can understand the site without full crawl cycles:

| URL | Content |
| --- | --- |
| `/llms.txt` | Concise: org name, description, base URL, locale, available schema types, AI contact email |
| `/llms-full.txt` | Extended: all of the above plus social profiles, full category tree with product counts, per-template schema type list |

Both documents draw the organisation name and description from the Organisation record — **configure Organisation first or these documents will be empty/incomplete**.

The content is extensible via `SectionProviderInterface` — register implementations in `di.xml` to inject additional sections (e.g. vendor listings from a marketplace module) without coupling to this module.

---

## Extending the module

All major composition points are wired via `di.xml` virtual types and constructor injection arrays, so they can be extended without modifying this module:

| Extension point | Interface / class | How to extend |
| --- | --- | --- |
| Structured data providers | `StructuredDataProviderInterface` | Register in the `Compositor` providers array in `di.xml` |
| Meta tag providers | `MetaTagProviderInterface` | Register in the `MetaTag\Compositor` providers array |
| Page title providers | `PageTitleProviderInterface` | Register in the `PageTitle\Compositor` providers array |
| Product schema builders | `ProductSchemaBuilderInterface` | Register in the `SchemaBuilderPool` builders array |
| llms.txt section providers | `SectionProviderInterface` | Register in the `LlmsTxtBuilder` sectionProviders array |

---

## Development

```bash
composer install

# Run all quality gates
composer test

# Or individually
vendor/bin/phpunit -c phpunit.xml.dist --testsuite unit
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes
vendor/bin/phpcs --standard=phpcs.xml.dist
XDEBUG_MODE=coverage vendor/bin/infection --min-msi=75 --threads=4
```

Integration tests live under `Test/Integration/` and run in CI against a live Magento install via [`graycoreio/github-actions-magento2`](https://github.com/graycoreio/github-actions-magento2). They cannot be run locally without a full Magento installation.
