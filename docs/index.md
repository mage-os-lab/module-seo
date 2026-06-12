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
| [AI Discoverability (llms.txt)](llms-txt.md) | `/llms.txt` and `/llms-full.txt` for LLM crawlers | Admin / developer |
| [Extending the Module](extending.md) | Adding providers, builders, and section content | Developer |

---

## Quick setup checklist

After installing and running `bin/magento setup:upgrade`:

1. Go to **Marketing → SEO → Organisation** and fill in Name, URL, Description, Logo, and any social profiles. Without this, JSON-LD and `/llms.txt` will output empty values.
2. Go to **Stores → Configuration → MageOS → SEO** and verify the defaults suit your store.
3. Assign a schema template to each top-level category via **Catalog → Categories → SEO (Structured Data) tab**.
4. Add the two URL rewrites so `/llms.txt` and `/llms-full.txt` work at clean paths (see [llms-txt.md](llms-txt.md)).
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
| `mage-os_seo_organisation` | Organisation identity settings, one row per scope (store/website/default) |
| `mage-os_seo_category_config` | Per-category SEO overrides, one row per category per store view |
| `mage-os_seo_product_override` | Per-product field overrides, one row per product per store view |

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
| `mageos_seo_general/structured_data/price_valid_until_months` | 12 | Months ahead for priceValidUntil |
| `mageos_seo_general/llms_txt/enabled` | 1 | Serve /llms.txt |
| `mageos_seo_general/llms_txt/full_enabled` | 1 | Serve /llms-full.txt |
| `mageos_seo_general/robots_meta/product_default` | INDEX,FOLLOW | Default for product pages |
| `mageos_seo_general/robots_meta/category_default` | INDEX,FOLLOW | Default for category pages |

All paths support store-view and website scope except `has_variant_max`, which is global only.
