# MageOS_Seo

A comprehensive Magento 2 **SEO + AEO + GEO** module: JSON-LD structured data, Open Graph / Twitter meta, canonical & robots management, hreflang, FAQ rich results, answer-engine identity (LocalBusiness / Article / Event / Speakable), and generative/agentic discoverability via `/llms.txt`, `/llms.jsonl`, AI-crawler robots directives, and `/.well-known/` manifests (UCP, ai-plugin.json, security.txt).

Every cross-cutting concern is built as an **extensible provider pool** — a second module contributes a provider through its own `di.xml` without ever editing this module.

---

## What it covers

- **SEO** — structured data, meta tags, canonicals, robots meta, pagination handling, hreflang.
- **AEO** (Answer Engine Optimisation) — FAQ rich results, LocalBusiness, Article, Event, Speakable, aggregate ratings, merchant policies.
- **GEO** (Generative Engine Optimisation / agentic commerce) — `/llms.txt`, `/llms.jsonl`, AI-crawler robots directives, and the `/.well-known/` agentic-discovery manifests.

---

## Features

### Structured data (JSON-LD)

- Organization, WebSite, BreadcrumbList, CollectionPage, and per-product schemas output as `<script type="application/ld+json">` in `<head>`, all cross-referenced by a shared `@id`.
- **16 product schema templates** — GenericProduct, Food, Apparel, Jewelry, HomeDecor, Book, Software, Toy, HealthProduct, Cosmetics, Pet, ArtAndCraft, ElectronicsSimple, Tool, Stationery, LocalExperience.
- **AggregateOffer** (lowPrice/highPrice) for configurable products.
- **Aggregate ratings** — a priority pool with a native Magento reviews provider built in; review vendors (Yotpo, Trustpilot, …) plug in a higher-priority provider.
- **Merchant policies** — shipping details, return policy, and item condition merged into product offers via an offer-enricher pool (required for Google Merchant free listings).

### Meta & crawl control

- **Open Graph + Twitter** — og:title/description/image/type/site_name/locale and twitter:card on product, category, and site pages.
- **Canonical URL management** — automatic canonicals on product, category, CMS, and home pages; deduplicates if one is already present.
- **Robots meta pool** — global INDEX/FOLLOW defaults for product, category, **and CMS** pages, overridable per category/product, plus a pagination provider for `?p=N` pages and enriched directives (`max-snippet`, `max-image-preview`, `noarchive`, `noai`, …).

### Multistore

- **hreflang** — `<head>` alternate links, and the same alternates inline in `sitemap.xml`, with language-only and x-default handling. Resolvers are a pool (product/category/CMS built in; vendor/blog pages plug in).

### Answer-engine (AEO)

- **FAQ subsystem** — managed FAQ records with a theme-agnostic `<details>` renderer, a Magento **Widget**, a native **Page Builder** content type, and a request-scoped collector that keeps FAQPage JSON-LD in parity with visible content. FAQ blocks carry cache identities, so FPC pages are purged automatically when a FAQ changes.
- **LocalBusiness** — address/geo/contact/price-range fields on the Organisation record.
- **Article / Event / Speakable** — bridge pools (empty by default) fed by blog/event modules, plus a configurable Speakable selector set.

### Generative / agentic (GEO)

- **`/llms.txt`** (concise) and **`/llms-full.txt`** (extended, with category tree) for LLM crawlers.
- **`/llms.jsonl`** — NDJSON product catalogue feed for AI catalogue consumers (off by default).
- **AI-crawler robots directives** — per-user-agent Allow/Disallow blocks appended to `robots.txt` for 14 known AI crawlers (off by default).
- **`/.well-known/` registry** — `ucp` (Universal Commerce Protocol profile), `ai-plugin.json`, and `security.txt`, all served through a pluggable endpoint registry; ECDSA P-256 signing-key generation via CLI.

---

## Requirements

- PHP 8.3 – 8.5
- Magento Open Source / Mage-OS **2.4.7 or newer** (`magento/framework
  ^103.0.7`), including 2.4.9 on PHP 8.5. The module runs unmodified across the
  whole range and carries no version polyfills: 2.4.7 is the first release with
  `Magento\Framework\ObjectManager\ResetAfterRequestInterface`, which the
  request-scoped services implement directly.
- Magento MSI (`Inventory*`) modules — a hard dependency: product availability
  is resolved through the MSI service contracts.

---

## Installation

```bash
composer require mage-os/module-seo
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
| Local presence | Address, geo coordinates, telephone, email, price range (LocalBusiness AEO) |

Without a Name and URL saved, the Organization node in JSON-LD will render with empty values, which search engines and validators will flag as invalid.

---

## Admin configuration

**Stores > Configuration > MageOS** holds three sections:

### SEO Configuration (`mageos_seo_general`)

| Group | Key settings | Default |
| --- | --- | --- |
| Open Graph Tags | Enable OG/Twitter tags | Yes |
| Structured Data (JSON-LD) | Master switch, default product template, ItemList toggle & max, hasVariant max, priceValidUntil months, aggregate rating | Yes / GenericProduct |
| AI Discoverability | `/llms.txt`, `/llms-full.txt`, `/llms.jsonl` | Yes / Yes / **No** |
| Robots Meta | Product / category / **CMS** defaults, pagination policy | *(empty — Magento default applies)* |
| Hreflang | Enable, language-only, sitemap | Yes |
| Answer Engine (AEO) | Speakable toggle + CSS selectors | No |
| AI Crawler robots.txt | Append directives, disallow list | **No** / CCBot,Bytespider |

### SEO Merchant Policies (`mageos_seo_merchant`)

| Group | Purpose | Default |
| --- | --- | --- |
| Item Condition | Default schema.org itemCondition on offers | NewCondition |
| Return Policy | `hasMerchantReturnPolicy` on offers | Off |
| Shipping Details | `OfferShippingDetails` on offers | Off |

### SEO Agentic Commerce / UCP (`mageos_seo_ucp`)

| Group | Purpose | Default |
| --- | --- | --- |
| UCP Profile | Serve `/.well-known/ucp` + merchant id/name | Off |
| Capabilities | Advertise catalog/cart/checkout/identity/order APIs | All off |
| Signing Keys | Public JWK + encrypted private key (set by keygen CLI) | — |
| AI Plugin Manifest | Serve `/.well-known/ai-plugin.json` | Off |
| security.txt | Serve `/.well-known/security.txt` (RFC 9116) | Off |

---

## Per-category & per-product SEO

In the **category** edit form, an **Advanced SEO** fieldset adds: schema template, enabled optional fields, field overrides, ItemList toggle, and robots meta. Template/field settings inherit from ancestor categories when unset.

In the **product** edit form, an **Advanced SEO** tab adds store-specific field overrides and a robots-meta override.

---

## FAQ rich results

Manage FAQs under **Marketing > SEO > FAQ Manager**. Each FAQ set has an identifier you reference from either placement:

- **Widget** — add the *SEO FAQ List* widget to any CMS block/page or layout (works in any theme).
- **Page Builder** — drop the native *FAQ* content type into any Page Builder stage.

Both render the same theme-agnostic `<details>/<summary>` markup (no JS) and feed a single request-scoped collector, so the emitted `FAQPage` JSON-LD always matches the visible questions — even under full-page / block cache.

---

## AI discoverability

| URL | Content | Default |
| --- | --- | --- |
| `/llms.txt` | Concise: org name, description, base URL, locale, schema types, AI contact | On |
| `/llms-full.txt` | Extended: the above plus social profiles, full category tree, FAQ section | On |
| `/llms.jsonl` | NDJSON, one compact JSON-LD `Product` node per line | Off |
| `robots.txt` additions | Per-user-agent Allow/Disallow for known AI crawlers | Off |

`/llms.txt` content draws the organisation name and description from the Organisation record — **configure Organisation first** or these documents will be incomplete.

These documents are pre-generated to files (by default `var/mageos_seo/`) and served from there; web requests never build them. **Multi-server deployments** must point the web servers and the cron/consumer host at a shared mount, which takes one entry in each machine's `app/etc/env.php` as well as the admin setting — see [Storing the feeds outside var/](docs/feeds.md#storing-the-feeds-outside-var-multi-server).

---

## Agentic commerce (`/.well-known/`)

A pluggable endpoint registry serves agentic-discovery manifests (all off by default):

| URL | Purpose |
| --- | --- |
| `/.well-known/ucp` | Universal Commerce Protocol profile — merchant identity, transports, advertised capabilities, public signing keys |
| `/.well-known/ai-plugin.json` | OpenAI-style plugin manifest pointing at Magento's REST schema |
| `/.well-known/security.txt` | RFC 9116 security contact disclosure |

### Generate UCP signing keys

```bash
bin/magento mageos:seo:ucp:keygen --website=1
bin/magento cache:flush config
```

This generates an ECDSA P-256 keypair, stores the private key **encrypted**, and stores/prints the public JWK. The private key is never printed and the served manifest is guaranteed never to contain it.

---

## Product schema templates

Each template maps to a `ProductSchemaBuilderInterface` implementation. Every template emits a
`schema.org/Product` node (Google's Product rich results and merchant listings require the
Product type); templates for creative works add a secondary type alongside Product, and
category-specific data with no valid Product property is expressed via `additionalProperty`:

| Code | Label | Schema type |
| --- | --- | --- |
| GenericProduct | Generic Product | Product |
| Food | Food & Grocery | Product |
| Apparel | Clothing & Apparel | Product |
| Jewelry | Jewelry | Product |
| HomeDecor | Home Decor & Furniture | Product |
| Book | Books | Product + Book |
| Software | Software & Apps | Product + SoftwareApplication |
| Toy | Toys & Games | Product |
| HealthProduct | Health & Wellness | Product |
| Cosmetics | Beauty & Cosmetics | Product |
| Pet | Pet Supplies | Product |
| ArtAndCraft | Art & Craft | Product + VisualArtwork |
| ElectronicsSimple | Electronics | Product |
| Tool | Tools & Hardware | Product |
| Stationery | Stationery & Office | Product |
| LocalExperience | Local Experience | Product |

The default template (`GenericProduct`) is used when no template is configured for the product's category. Change it under **Configuration > Structured Data > Default Product Schema Template**.

---

## Extending the module

Every cross-cutting concern is a provider pool wired via `di.xml`, so another module contributes a provider from its **own** `di.xml` without modifying this one. Most provider interfaces expose `getHandles()` (`['*']` = all pages) for layout-handle scoping; resolution is either *collect-all* or *first-wins / highest-priority*.

| Extension point | Interface | Resolution |
| --- | --- | --- |
| Structured data providers | `StructuredDataProviderInterface` | collect-all |
| Meta tag providers | `MetaTagProviderInterface` | collect-all |
| Page title providers | `PageTitleProviderInterface` | first-wins |
| Product schema builders | `ProductSchemaBuilderInterface` | by template code |
| Robots meta providers | `RobotsMetaProviderInterface` | first-wins by sortOrder |
| Aggregate rating providers | `AggregateRatingProviderInterface` | highest-priority non-null |
| Offer enrichers | `OfferEnricherInterface` | collect-all (merged into offers) |
| Hreflang resolvers | `HreflangResolverInterface` | collect-all |
| Article / Event data providers | `ArticleDataProviderInterface` / `EventDataProviderInterface` | collect-all |
| FAQ source providers | `FaqSourceProviderInterface` | collect-all |
| llms.txt section providers | `SectionProviderInterface` | collect-all |
| llms.jsonl line providers | `JsonlLineProviderInterface` | collect-all |
| Well-known endpoints | `WellKnownEndpointInterface` | by path segment |
| UCP capability providers | `UcpCapabilityProviderInterface` | collect-all |

Example — add a robots-meta provider for blog pages from your module's `di.xml`:

```xml
<type name="MageOS\Seo\Model\RobotsMeta\Resolver">
    <arguments>
        <argument name="providers" xsi:type="array">
            <item name="blog" xsi:type="object">Vendor\Blog\Model\BlogRobotsProvider</item>
        </argument>
    </arguments>
</type>
```

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
XDEBUG_MODE=coverage vendor/bin/infection --threads=4  # gate: minMsi in infection.json5
```

Integration tests live under `Test/Integration/` and run in CI against a live Magento install via [`graycoreio/github-actions-magento2`](https://github.com/graycoreio/github-actions-magento2). They cannot be run locally without a full Magento installation.
