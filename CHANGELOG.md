# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/). Releases are cut from git tags —
the tag is the source of truth for the version (composer.json carries no
hardcoded version field).

## [Unreleased]

Pre-release review hardening pass (July 2026). Breaking renames are included
deliberately: nothing has shipped yet, so names are settled now, before they
become public contract.

### Fixed

- Admin product and category edit pages no longer fail with `ReflectionException:
  Class "…\Form\Modifier\Pool" does not exist`. The SEO form modifiers were
  registered with `<type>` on the virtual-type pool names, which replaced the pool
  definitions in the merged DI config (and dropped every other module's product
  form modifiers). The product modifier now extends Magento_Catalog's
  `<virtualType>`; the category pool is declared in full and wired into the
  category form data provider, so the SEO fieldset also appears on installations
  without Mage-OS's AutomaticTranslation module (which declares the same pool).
- Table names are now resolved through `ResourceConnection::getTableName()` so
  installations with a DB table prefix work (adapter `getTableName()` never
  applied the prefix).
- JSON-LD output uses `JSON_HEX_TAG | JSON_HEX_AMP` instead of a post-encode
  `str_replace` that produced the invalid `\!` escape and corrupted the whole
  payload when content contained `<!--`.
- Offer enricher pool and aggregate-rating resolver are now required constructor
  dependencies: as optional arguments the ObjectManager passed `null` and every
  builder silently ran with empty pools, so merchant policies and
  aggregateRating were never emitted. All optional collaborator arguments across
  the module were made required for the same reason.
- JSON-LD / Open Graph prices are converted to the display currency before being
  paired with the display currency code (previously base-currency amounts were
  labelled with the display code).
- `priceValidUntil` prefers an active `special_to_date`; the synthetic
  "today + N months" window is store-timezone-aware and can be disabled by
  configuring 0 months. `itemCondition` is no longer hardcoded to NewCondition
  (the configurable ItemConditionEnricher supplies it). Backorderable
  out-of-stock products emit `BackOrder` availability.
- GTIN values are validated (length + GS1 check digit) and emitted under the
  matching property (`gtin8`/`gtin12`/`gtin13`/`gtin14`); invalid values are
  omitted instead of producing Search Console errors. The Electronics, Tool and
  Stationery builders previously set `gtin13` from the barcode attribute without
  this validation (only the override path was validated); they now route the
  attribute value through the validator like every other builder.
- `CanonicalUrlManager` removes existing canonicals by asset content type
  instead of a URL-key pattern that could remove arbitrary CSS/JS assets.
- Per-store category/product SEO overrides read and write the store view from
  the `store`/`store_id` request parameter; previously the adminhtml current
  store (always 0) was used, making per-store overrides unreachable from the UI.
  The category form now also shows values inherited from ancestor categories.
- Catalog save plugins moved to `etc/adminhtml/di.xml`, wrap SEO persistence in
  try/catch (an SEO-table failure no longer aborts an already-committed product/
  category save), and surface invalid override-JSON as an admin error instead of
  silently wiping stored overrides.
- BreadcrumbList schema now renders on Luma and other themes without a public
  `getCrumbs()` on the breadcrumbs block, via the catalog breadcrumb path.
- FAQ widget no longer sets a block-cache `ttl`, which could FPC-cache pages
  without their FAQPage JSON-LD.
- robots.txt AI-crawler directives emit `Disallow: /` groups only for
  disallowed bots; allowed bots previously received dedicated `Allow: /` groups
  that exempted them from all `User-agent: *` rules.
- Request-scoped shared instances (FAQ collector, product schema registry, CMS
  page resolver, hreflang store-locale map, category config/product-override
  repositories) implement `ResetAfterRequestInterface` so their state cannot
  leak between requests under long-lived application servers (e.g. FrankenPHP
  worker mode). The category repositories also stop caching the DB adapter at
  construction — `ResourceConnection::_resetState()` closes connections between
  requests, which would leave a cached handle stale.

### Changed

- **Breaking (pre-release):** all `rs_seo`/`rs-seo` names renamed to
  `mageos_seo`/`mageos-seo` (routes, layout handles, block names, DI/observer/
  plugin names, admin form field names); cache tags `RS_*` renamed to
  `MAGEOS_SEO_*`; DB tables renamed from hyphenated `mage-os_seo_*` to
  `mageos_seo_*` with automatic data migration via declarative schema
  (`onCreate="migrateDataFromAnotherTable"`); `OrganizationProvider` renamed to
  `OrganisationProvider`; origin-project `makers_*` layout handles removed in
  favour of a DI-configurable `excludedHandles` argument; `GBP` currency
  fallback removed.
- Robots meta defaults ship empty ("no opinion") so Magento core's
  `design/search_engine_robots` configuration stays in charge until a merchant
  explicitly configures values. Installing the module no longer re-opens
  NOINDEXed environments.
- Organisation logo upload no longer accepts SVG (stored-XSS vector) and
  validates uploaded bytes are a real raster image.
- Admin controllers declare `HttpPostActionInterface`/`HttpGetActionInterface`;
  FAQ delete actions go through POST with form-key validation; FAQ save
  validates required fields server-side.
- The PageTitle compositor is now wired into page rendering via an observer;
  built-in providers only act when an explicit title exists (variant title or
  meta_title) so core behaviour is unchanged by default.
- Current product/category resolution goes through a single
  `Model\Catalog\CurrentEntity` shim instead of injecting the deprecated
  `Magento\Framework\Registry` into every provider.
- `Model\Cms\CmsPageResolver` resolves CMS pages through the
  `Magento\Cms\Api\GetPageByIdentifierInterface` service contract instead of
  `PageFactory`/`checkIdentifier()`; behaviour is unchanged (a missing
  identifier resolves to `null`).
- composer.json declares all hard module dependencies, a `license` field
  (OSL-3.0), and no longer hardcodes a package version.
- Support floor is Magento/Mage-OS **2.4.6-p15+** (`magento/framework`
  pinned `^103.0.6-p15`). The module runs unchanged across the range: a bundled
  `Compat/ResetAfterRequestInterface` polyfill (loaded from `registration.php`
  behind `interface_exists`) covers versions below 2.4.7 that lack the
  worker-mode reset interface, and `Model\Cache\CleaningMode` resolves the
  full-page-cache cleaning-mode identifier that only 2.4.9's
  `Magento\Framework\Cache\CacheConstants` exposes.
- Feed generation is queue-based: invalidations (and requests hitting a missing
  file) queue a rebuild on the `mageosSeoFeedRegenerate` consumer with duplicate
  requests collapsed via a pending flag; web requests never build feeds and answer
  503 Retry-After until the file exists. The nightly cron remains as a full-rebuild
  safety net. The feed storage directory is configurable
  (`mageos_seo_general/feeds/storage_dir`) for multi-server deployments.
- Organisation model and the JSON-LD block implement `IdentityInterface`, so saving
  Organisation settings purges the affected FPC/Varnish pages by tag automatically
  (replaces the manual full_page cache-type invalidation).
- Product availability is resolved through the MSI service contracts
  (`IsProductSalableInterface` + backorders via `GetStockItemConfigurationInterface`,
  batched with `AreProductsSalableInterface` for llms.jsonl) via a new
  `AvailabilityResolver` service, replacing the deprecated CatalogInventory
  `StockRegistry`/`Stock` helper which ignores multi-source stock assignment.
  composer dependencies move from `magento/module-catalog-inventory` to
  `magento/module-inventory-sales-api` + `magento/module-inventory-configuration-api`;
  installations that have physically removed the MSI modules cannot use this module.

## [1.1.0] — 2026 (pre-review baseline)

Baseline of the module as donated for Mage-OS review: JSON-LD structured data
(16 product templates, Organisation, FAQ, breadcrumbs, hreflang), meta/OG tags,
robots meta management, llms.txt / llms-full.txt / llms.jsonl endpoints and
.well-known documents.
