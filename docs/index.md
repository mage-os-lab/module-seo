# MageOS_Seo — Documentation

The SEO module provides structured data (JSON-LD), Open Graph meta tags, canonical URL management, robots meta control, per-category and per-product SEO configuration, and AI-crawler discoverability for MageOS / Magento 2.

---

## Feature docs

| Topic | File | Who it's for |
|---|---|---|
| [Structured Data (JSON-LD)](structured-data.md) | How JSON-LD output works, which schemas are produced on which pages | Developer / SEO manager |
| [Product Schema Templates](product-schema-templates.md) | The 16 built-in product templates and how to add new ones | Developer / merchandiser |
| [Organisation Settings](organisation.md) | Configuring site identity, logo, socials, contact — per store/website | Admin / developer |
| [Open Graph Tags](og-tags.md) | OG meta tag output on product and category pages | Admin / SEO manager |
| [Canonical URLs](canonical-urls.md) | How canonicals are managed and deduplicated | Developer / SEO manager |
| [Robots Meta](robots-meta.md) | Global defaults and per-page overrides | Admin / SEO manager |
| [Per-Category SEO](category-seo.md) | Schema template, field config, robots, ItemList per category | Admin / merchandiser |
| [Per-Product SEO](product-seo.md) | Field overrides and robots meta per product per store | Admin / merchandiser |
| [AI Discoverability (llms.txt)](llms-txt.md) | `/llms.txt`, `/llms-full.txt` and `/llms.jsonl` — what they contain | Admin / developer |
| [Hreflang Alternates & Sitemap](hreflang.md) | Head alternates and those in `sitemap.xml` — what appears in them | Developer / SEO manager |
| [XML Sitemap](sitemap.md) | The sitemap generator, its file layout, rebuilding on change, and how to extend it | Developer / SEO manager |
| [Pre-generated Feeds](feeds.md) | The machinery behind the three llms documents: rebuilds, caching, storage, multi-server, CLI | Developer / DevOps |
| [Extending the Module](extending.md) | Adding providers, builders, and section content | Developer |

---

## Planned / roadmap

Future SEO / AEO / GEO work is planned in [`planned-features/`](planned-features/). Start with the
master roadmap, which orders the phases and states the provider-pool architecture every feature
follows:

- [SEO / AEO / GEO Roadmap](planned-features/_roadmap.md) — phases, dependencies, quality gates.
- [Provider Pool Architecture](planned-features/architecture-provider-pools.md) — the universal
  extension pattern used by all planned features.

---

## Quick setup checklist

After installing and running `bin/magento setup:upgrade`:

1. Go to **Marketing → SEO → Organisation** and fill in Name, URL, Description, Logo, and any social profiles. Without this, JSON-LD and `/llms.txt` will output empty values.
2. Go to **Stores → Configuration → MageOS → SEO** and verify the defaults suit your store.
3. Assign a schema template to each top-level category via **Catalog → Categories → SEO (Structured Data) tab**.
4. Nothing to do for `/llms.txt`, `/llms-full.txt` or `/llms.jsonl`: a router serves them at those paths. Do **not** add URL rewrites for them — a rewrite fights the router (see [feeds.md](feeds.md)).
5. Flush the cache.

---

## Admin menu locations

| Menu path | Purpose |
|---|---|
| Marketing → SEO → Organisation | Site identity settings — name, URL, logo, socials |
| Stores → Configuration → MageOS → SEO | All feature toggles and defaults |
| Catalog → Categories → (open a category) → SEO (Structured Data) | Per-category schema template and overrides |
| Catalog → Products → (open a product) → Advanced SEO | Per-product field overrides and robots |

---

## Database tables

| Table | Purpose |
|---|---|
| `mageos_seo_organisation` | Organisation identity settings, one row per scope (store/website/default) |
| `mageos_seo_category_config` | Per-category SEO overrides, one row per category per store view |
| `mageos_seo_product_override` | Per-product field overrides, one row per product per store view |
| `mageos_seo_faq` | FAQ entries, grouped by identifier, one row per entry per store view |

Records go when what they describe goes. `mageos_seo_category_config`,
`mageos_seo_product_override` and `mageos_seo_faq` carry foreign keys with `ON DELETE CASCADE`
to `catalog_category_entity`, `catalog_product_entity` and `store`, so deleting a category, a
product or a store view removes its SEO records with it — including when a deleted website or
store group takes its store views down with it, which happens in the database without any event
a module could observe.

`mageos_seo_organisation` is the exception: its `scope_id` points at a website or a store view
depending on the `scope` column, which no single foreign key can express (`core_config_data` is
built the same way). `MageOS\Seo\Observer\RemoveOrganisationOnScopeDelete` clears it instead,
exactly as core clears its configuration table from `Website::beforeDelete()` and
`Group::beforeDelete()`.

---

## System config paths (for programmatic access)

All paths live under `mageos_seo_general/`:

| Path | Default | Notes |
|---|---|---|
| `mageos_seo_general/og_tags/enabled` | 1 | Master switch for OG tags |
| `mageos_seo_general/structured_data/enabled` | 1 | Master switch for JSON-LD |
| `mageos_seo_general/structured_data/default_product_template` | GenericProduct | Fallback template |
| `mageos_seo_general/structured_data/category_item_list_enabled` | 1 | ItemList on category pages |
| `mageos_seo_general/structured_data/category_item_list_max` | 36 | Max items in ItemList |
| `mageos_seo_general/structured_data/has_variant_max` | 50 | Max hasVariant entries (global only) |
| `mageos_seo_general/structured_data/price_valid_until_months` | 12 | Months ahead for priceValidUntil when no special-price end date applies (0 = omit) |
| `mageos_seo_general/feeds/storage_dir` | *(empty)* | Where the pre-generated feeds are written; empty = `var/mageos_seo`. Restricted: inside the installation only `var/`, and anywhere else only under a root declared in `app/etc/env.php` as `mageos_seo/feed_storage_roots` — see [feeds.md](feeds.md#storing-the-feeds-outside-var-multi-server) |
| `mageos_seo_general/llms_txt/enabled` | 1 | Serve /llms.txt |
| `mageos_seo_general/llms_txt/full_enabled` | 1 | Serve /llms-full.txt |
| `mageos_seo_general/robots_meta/product_default` | *(empty)* | Default for product pages (empty = Magento's Design → Search Engine Robots setting) |
| `mageos_seo_general/robots_meta/category_default` | *(empty)* | Default for category pages (empty = Magento's Design → Search Engine Robots setting) |

All paths support store-view and website scope except `has_variant_max`, which is global only.
