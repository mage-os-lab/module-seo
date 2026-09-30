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

### Fixed — llms.txt review (September 2026)

Checked against the llms.txt spec (v2, 10 August 2026), the reference parser
(`llms_txt` on PyPI, linked from llmstxt.org) and Lighthouse's llms-txt audit.

- **llms.txt follows the format.** Items under an H2 are `- [name](url)` links
  (Lighthouse fails a file with no markdown link, and the reference parser fails on
  any non-link line). Base URL, locale, search template, structured data and contact
  move to the details list before the first H2. The summary is one line; with no
  description there is no blockquote. FAQ entries are one-line plain text in the
  details. Square brackets in link labels become round ones.
- **Structured data line** names schema.org types instead of template codes, and no
  longer says "in use" for every registered template.
- **Sitemap link** is core's URL of the sitemap configured under Marketing → Site Map,
  not a hardcoded `/sitemap.xml`; no link when the store view has none.
- **`/llms.txt` answers 404, not 503, before the first build**, with `Retry-After` and
  `Cache-Control: no-store`. Lighthouse scores a 5xx as a failure and a 4xx as not
  applicable. `/llms-full.txt` and `/llms.jsonl` keep 503.
- **Disabled files are not claimed by the router**, so a static file or another module
  can serve the path.
- **`/llms.jsonl` description** is one line and cut at a word boundary, with `…`.
- `LlmsTxtBuilder` takes a new `SitemapUrlResolver` argument (before
  `sectionProviders`), and `LlmsTxtRouter` takes `Model\Aeo\Config`; with DI nothing
  changes.

### Added

- Configurable products are described as a `ProductGroup` of their variants, per Google's
  product-variant guidance, when they have up to **Most Variants per Configurable Product**
  (`has_variant_max`, default 50) sellable children: `productGroupID`, `variesBy`, and one
  `Product` per child with its own offer and the group's description — the offers move off the
  group. What a variant varies by
  comes from the product's own configurable attributes, no map: codes naming one of Google's six
  (`color`, `size`, `material`, `pattern`, `suggested_gender`, `suggested_age`) are written as that
  property and listed in `variesBy`; any other becomes an `additionalProperty`. Each variant's
  offer URL is the product URL with `?{attribute_code}={option_id}`, which Luma's swatches
  preselect; `Api\ProductVariantUrlResolverInterface` (`@api`) replaces the rule. With the
  template's GTIN field enabled, each variant carries its own GTIN, loaded in one query from
  `gtin13`, `gtin`, `barcode` or `ean`. See `docs/structured-data.md#configurable-products`.
- A sitemap generator for the sitemaps configured under Marketing → Site Map, selected by the
  new **Catalog → XML Sitemap → Generation Settings → Generator** (default **MageOS SEO**;
  **Magento** leaves core's generator in charge). It lists the same URLs as core's, writes one file
  per kind of page under a `sitemap.xml` index, streams the catalogue whatever core's Generation
  Method says, and never lets a file pass the configured size. Products are read a page at a time
  by its own reader (`ResourceModel\Sitemap\ProductStream`) with core's product sitemap query, on
  every supported version; plugins on core's `prepareSelectStatement()` hook still apply. See
  `docs/sitemap.md`.
- Hreflang alternates inline in `sitemap.xml`, beside each URL, as the MageOS SEO generator writes
  it — the same set the page's head declares, and only where the URL is among its own alternates.
- The MageOS SEO sitemap generator leaves out pages served NOINDEX (**Generation Settings → Leave
  Out NOINDEX Pages**, default Yes). The directive is the page's own — its override, the page
  type's default, else core's Design → Search Engine Robots — so a store set to NOINDEX there gets
  an empty sitemap.
- Sitemaps kept current between generations (**Generation Settings → Rebuild on Change**, default
  Yes): a change to what a sitemap lists — a product's URL key, status, visibility or websites, a
  category, a CMS page, a robots directive or translation group, a store view, a sitemap-related
  setting — rewrites that kind of page in the store view's sitemaps, through the feed queue,
  leaving the other files and their dates as they were. A sitemap with no file — a Site Map entry
  saved without generating it, or one whose file has gone — is written whole shortly after its
  entry is saved and after every setup run (the `sitemaps-missing` queue group), where Magento
  would wait for **Generate** or its own schedule, which is off by default. One lock per sitemap
  keeps a rebuild, the Generate button and Magento's cron from writing it at once. Other modules ask for their own
  type through `Api\Sitemap\RebuildRequesterInterface`, and
  `mageos:seo:feeds:regenerate -g sitemap-{type}` (or `'sitemap-*'`) rebuilds in process. See
  `docs/sitemap.md`.
- Sitemap extension points (`Api\Sitemap\*`): items carry their entity and a data bag; providers
  say which file their items belong in and stream them; enrichers, filters and row renderers are
  registered on the generator. Providers registered on core's composite keep working, written
  under "other".
- Hreflang: several codes per store view, validated against the country directory; x-default per
  website; the `mageos_seo_hreflang_alternates_after` event; CMS translation groups, which purge
  the other translations' cached pages when a page joins, leaves or is deleted.
- Per-CMS-page robots override, and `noarchive` with every index/follow combination.
- Data patches carrying `MageOS_MetaRobotsTag`'s flags and `MageOS_Hreflang`'s settings and CMS
  links into this module; the latter then switches `MageOS_Hreflang`'s head output off. Its
  sitemap setting is left alone: where this module generates the sitemap, `MageOS_Hreflang`'s rows
  are never written, and where Magento's generator is selected they are the only alternates.
- `bin/magento mageos:seo:feeds:regenerate [-g llms|jsonl]` rebuilds the
  pre-generated feeds in the running process, for deployment scripts and manual
  rebuilds. It reports per-store-view failures and exits non-zero on any, and ends with
  `Done.`
- Every `setup:install` / `setup:upgrade` queues a rebuild of the feeds the store
  views can build, so a fresh install (or a deployment that cleared `var/`) no
  longer serves `503` on `/llms.txt` until the nightly cron runs.
- **Robots Meta Defaults → Search Results Pages Default** (`mageos_seo_general/robots_meta/search_default`,
  per store view, empty by default so core's Design → Search Engine Robots decides, like the product,
  category and CMS defaults): the robots meta for quick and advanced search results
  (`RobotsMeta\Provider\SearchResultsRobotsProvider`). `docs/robots-meta.md` explains `NOINDEX,FOLLOW`
  here against a robots.txt `Disallow`, and why not both.
- **AI Discoverability → FAQ Groups** (`mageos_seo_general/llms_txt/faq_groups`, per store view,
  default `global`): which FAQ groups `/llms.txt` (the first 5 questions) and `/llms-full.txt`
  (all) include, in order. Selecting none leaves FAQs out. Only a group named `global` was read
  before, which only the FAQ form's field notice said. See `docs/llms-txt.md#faq-section`.
- `Api\UcpServiceProviderInterface` and `Model\Ucp\ServicePool`: a module that serves a UCP service
  declares each transport binding in `/.well-known/ucp` from its own di.xml. Every service and
  capability entry is checked against the UCP 2026-08-25 business schema (`Model\Ucp\EntryValidator`),
  including the namespace binding of its `schema` URL. An entry that fails is logged with its
  provider and the reasons, and left out. See `docs/ucp.md`.
- `Api\Rebuild\GroupHandlerInterface` (`@api`) and `Model\Rebuild\HandlerPool`: a module hands
  its own pre-generated output to the rebuild queue the sitemaps and the llms documents use.
  - The queue collapses repeated requests, skips a group no store view can build, and re-queues a
    rebuild refused as already running.
  - `mageos:seo:feeds:regenerate` and every setup run pick the groups up.
  - `Exception\RebuildInProgressException` is the common parent of the "already running" refusals.

  See `docs/extending.md#rebuilding-your-own-output-through-the-queue`.

### Removed

- `Setup\Patch\Data\RenameOrganisationTypes` and `ResourceModel\Organisation::renameOrgType()`,
  its only user. The patch renamed stored `EducationalOrg` / `GovernmentOrg` types to their
  schema.org names; the table it worked on is dropped by the Organization rename (see Changed), so
  it could never match a row again.
- **Structured Data → Price Valid Until (months)** (`mageos_seo_general/structured_data/price_valid_until_months`),
  with `Model\Config::getPriceValidUntilMonths()` and `Config::XML_SD_PRICE_VALID_UNTIL_MONTHS`: no
  synthetic `priceValidUntil` is made any more (see Changed). Its "0 omits the date" never worked —
  the value was read as at least 1 month. Values already saved stay in `core_config_data`, unread.
- `Model\Ucp\UcpConfig::getSupportEmail()` and `UcpConfig::XML_SUPPORT_EMAIL`. ai-plugin.json's
  contact comes from `Model\Organisation\ContactEmail` (see Fixed).
- **SEO Agentic Commerce (UCP) → Advertised Capabilities**, the five toggles
  (`mageos_seo_ucp/capabilities/{catalog,cart,checkout,identity_linking,order_management}`), and
  **UCP Profile → Merchant ID** (`mageos_seo_ucp/general/merchant_id`). With them go
  `UcpConfig::getEnabledCapabilities()`, `getMerchantId()`, `getDomainHost()` and
  `UcpConfig::XML_UCP_MERCHANT_ID`.
  - The toggles declared UCP capabilities that nothing in the store implements.
  - UCP has no merchant member.

  Values already saved stay in `core_config_data`, unread.
- `/.well-known/ai-plugin.json` and its settings. It now answers 404.
  - **Settings removed:** **SEO Agentic Commerce (UCP) → AI Plugin Manifest**
    (`mageos_seo_ucp/ai_plugin/{enabled,description,legal_url}`) and **Merchant Name**
    (`mageos_seo_ucp/general/merchant_name`), which only the manifest read.
  - **Code removed:** `Model\Ucp\AiPluginBuilder`, `Model\WellKnown\Endpoint\AiPluginEndpoint`,
    `UcpConfig::isAiPluginEnabled()`, `getMerchantName()`, `getBaseUrl()`,
    `getAiPluginDescription()`, `getAiPluginLegalUrl()` and their constants.
    `Ucp\UcpConfig`'s constructor no longer takes `StoreManagerInterface`.
  - **Why:** it made two false claims.
    - It was the manifest of OpenAI's ChatGPT plugins beta, which OpenAI ended in March–April 2024.
      OpenAI's plugins today are packages from its plugin directory, not a manifest a site hosts.
    - Its `auth: none` pointed agents at `catalogProductRepositoryV1`, whose methods need the admin
      ACL `Magento_Catalog::products`. Without core's Allow Anonymous Guest Access, an agent got a
      schema with no operations and a 401.
  - `/llms.txt`'s AI contact is unchanged. Saved values stay in `core_config_data`, unread.
  - Other entries in this release that mention ai-plugin.json describe it before its removal.

- The dedicated `/hreflang-sitemap.xml` and its chunk files. The alternates are in `sitemap.xml`
  now, beside each URL (see Added), which is where Google prefers them. The path answers 404,
  with no redirect; a merchant who submitted it in Search Console should remove it there. The
  upgrade deletes its files from feed storage, clears its pending rebuild and purges its cached
  responses (`Setup\Patch\Data\RemoveHreflangSitemap`). Gone with it: the `hreflang` feed group
  and `mageos:seo:feeds:regenerate -g hreflang`, the `MAGEOS_SEO_HREFLANG_SITEMAP` cache tag, and
  the setting's old label — **Enable /hreflang-sitemap.xml** is now **Add Hreflang Alternates to
  sitemap.xml**, on the same path. Entries below that mention the hreflang sitemap describe it
  before its retirement.
- The `variant_slug_data` request parameter and everything that read it. No module in this
  package or in Magento sets it, so it never changed the output. **Breaking for custom
  templates:** `Api\ProductSchemaBuilderInterface::build()` and `SchemaBuilderPool::build()` no
  longer take `$variantData`, and `AbstractBuilder::buildBase()` takes only the product. A builder
  written against 1.1.0 that still requires the fourth parameter no longer matches the interface,
  a fatal error when the class loads: drop it, or give it a default (`array $variantData = []`) to
  load against both versions. Extra arguments passed to the pool or to `buildBase()` are ignored.
  The product title, meta and JSON-LD providers no longer depend on the request.
- `AbstractBuilder`'s offer code, moved to `Model\Product\OfferBuilder` so variants use the same
  one. **Breaking for custom templates** that declare a constructor or call these: the constructor
  now takes `StoreManagerInterface`, `ImageHelper`, `Config`, `OfferBuilder`,
  `AggregateRatingResolver`, `GtinValidator` (the currency, availability, date and offer-enricher
  arguments are gone), and `resolvePrice()`, `resolveAvailability()`, `getPriceValidUntil()`,
  `resolvePriceRange()`, `buildAggregateOffer()` and the `AVAILABILITY_*` constants are removed. A
  template that extends AbstractBuilder without a constructor of its own is unaffected. To change
  offers, register an `OfferEnricherInterface` or plug in to `OfferBuilder::build()`.

### Fixed

- Saving or deleting a FAQ or the Organisation anywhere but the admin form — the REST API, an
  import, a data patch — left `/llms.txt` and `/llms-full.txt` stale until the nightly rebuild:
  only the admin controllers asked for a rebuild. The models now dispatch their own events
  (`mageos_seo_faq_save_after` / `_delete_after`, `mageos_seo_organisation_save_after` /
  `_delete_after`), and those queue it, however the save was made.
- `/.well-known/ucp` did not follow UCP.
  - **Before:**
    - it served `$schema` (`https://json-schema.org`, which is not a schema), `version`,
      `merchant`, `transports`, and a `capabilities` map of `enabled` flags, none of which the
      spec defines;
    - it declared `dev.ucp.shopping`, and one capability per admin toggle, that nothing in the
      store served.
  - **Now:**
    - it is a UCP 2026-08-25 business profile: `{"ucp": {"version", "services", "capabilities",
      "payment_handlers"}}`, plus `keys`;
    - it declares only what an installed module registers, so out of the box it declares nothing.
  - **Signing key:**
    - it moves from `signing_keys` to `keys`;
    - a stored key carrying any private member (`d`, `p`, `q`, `dp`, `dq`, `qi`, `oth` or `k`, not
      only `d`) refuses the profile;
    - a stored key the schema would reject is logged and left out.

  See `docs/ucp.md`.
- The FAQ widget's questions showed no open/close triangle on Luma and Blank: Magento's LESS reset
  sets `summary { display: block; }`, which hides a `<summary>`'s marker. A module
  `_module.less` restores `display: list-item` (and a pointer cursor) on
  `.mageos-seo-faq__question`. Hyvä is unaffected.
- On Luma, a product page's BreadcrumbList took its category from the category the visitor had last
  browsed (core's session fallback), so the page cache served the first visitor's trail to
  everyone, on a URL whose visible trail is Home › Product. The category is now used only when the
  URL carries it; otherwise the trail is Home › Product. `BreadcrumbListProvider`'s constructor
  takes the request.
- The X (Twitter) card was `summary_large_image` on every page, including those with no image (a
  category without one, most CMS pages) and pages with nothing to share (cart, checkout). The card
  is now decided after every provider's tags are collected: `summary_large_image` with an
  `og:image`, `summary` without, none without an `og:title`. `twitter:title`,
  `twitter:description` and `twitter:image` repeat the Open Graph values. A `twitter:*` tag a
  provider sets itself is kept. `docs/og-tags.md` said no `twitter:card` was output, and now
  describes this.
- A Field Value Override for one of a template's own fields undid what the template built from it:
  `AbstractBuilder::applyOverrides()` wrote every override onto the node as given, after the template
  had used it. So a `brand` override (the docs' own example) replaced the `Brand` node with a
  string, a Book's `author` and `publisher` became strings, and `gender`, ages, `warning`,
  `batteriesRequired`, `organizer` and the like appeared a second time as top-level properties
  `Product` does not have. Now an override for a template's own field turns that field on
  (`SchemaBuilderPool`) and the template alone applies it; `applyOverrides()` sets only keys the
  template does not list. **Breaking for custom templates** that relied on `applyOverrides()` to
  set their own fields: read `$overrides[$field]` for them, as the built-in templates do — see
  `docs/extending.md`.
- Apparel's **Weight** and Toy's **Number of Players** were offered as fields but never built. Weight
  is read as Generic reads it (override, else `weight` / `rs_weight`); the player count goes into
  `additionalProperty` (override, else `player_count`), as `Product` has no such property.
- ArtAndCraft's `creator` is a `Person` node, not text.
- A product with a `special_to_date` but no special price published that date as its offer's
  `priceValidUntil`. It now needs a special price with a value (see Changed).
- `/llms.txt` and `/llms-full.txt` printed an empty `> Locale:` line: the store view's locale was
  read from `Store::getLocaleCode()`, which does not exist and fell through to empty data. It is
  now **General → Locale Options → Locale** for the store view, and the line is left out when there
  is none.
- The AI contact in `/llms.txt`, `/llms-full.txt` and `/.well-known/ai-plugin.json` was the store's
  Customer Support email, which Magento ships as `support@example.com` — so an unconfigured store
  published a placeholder. It is now the Organisation's Contact Email; else the Customer Support
  email unless it is still the shipped value; else none (the section is left out, `contact_email`
  is `""`). One class, `Model\Organisation\ContactEmail`, decides it for all three.
- A configuration change to something `/llms.txt` and `/llms-full.txt` show — the locale, the
  Customer Support email, their own settings, the base URLs (`web/`), the category URL suffix
  (`catalog/seo/`) — reached them only with the nightly rebuild. It now queues one, when the value
  changed.
- The Organization node carries `geo` and `priceRange` only on a local business type. schema.org
  gives a plain Organization neither (they come from Place and LocalBusiness); `address`,
  `telephone` and `email` stay on every type. Local business types are `LocalBusiness` by default,
  and a module that stores a subtype adds it to `localBusinessTypes` on
  `Model\StructuredData\Provider\OrganisationProvider` in its di.xml. The admin notice that
  promised this now says it.
- The organisation types **Educational Organization** and **Government Organization** were emitted
  as `EducationalOrg` and `GovernmentOrg`, which are not schema.org types. They are now
  `EducationalOrganization` and `GovernmentOrganization`, and the data patch
  `RenameOrganisationTypes` renames stored records (revertible).
- Speakable is carried by the page's own node — the CMS page's WebPage, the category's
  CollectionPage, the blog post's BlogPosting, and on product pages a WebPage for the product whose
  `mainEntity` is the product node — instead of an anonymous WebPage node, with no `@id` or `url`,
  on every page (so CMS pages had two WebPage nodes). Pages with no page node of their own no
  longer carry it. Built once, by `Model\StructuredData\SpeakableSpecification`.
- The home page is described at the store base URL. Its `og:url`, WebPage `url` and `@id` were
  `/{identifier}` (`/home`) while its canonical was `/`, and the schema could type it `AboutPage`;
  it is now a `WebPage` at `{base}/`, `@id` `{base}/#webpage`, throughout. The canonical, `og:url`,
  the WebPage node and hreflang take the URL from one place, `CmsPageResolver::currentUrl()`.
- The home page is recognised as core's router recognises it: the request's path is empty. The
  `cms_index_index` handle used for it before also matches `/cms` and `/cms/index/index`, which
  claimed the canonical `/` — pointing at a different page when **Default Web URL** serves
  something other than the CMS home page there.
- A home page set by page ID (a numeric `web/default/cms_home_page`, e.g. through `config:set`) got
  no CMS markup on `/`, and the sitemap's NOINDEX check could not read its robots directive. The
  home page is now loaded as core loads it (`Model\Cms\HomePageLoader`): by identifier, or by page ID
  when the value is numeric.
- Configurable products get their `AggregateOffer` on the product page. The range was read from
  core's `FinalPrice::getMinimalPrice()`/`getMaximalPrice()`, which read `minimal_price` data only a
  collection load sets — 0 on a product page — so every configurable showed one `Offer` at its
  lowest price. It is now read from the sellable children's own final prices. Children sharing one
  price keep a single `Offer`.
- `has_variant_max` at 0 read as 50, and the admin refused 0. 0 now means every configurable gets
  the `AggregateOffer`.

- Hreflang URLs use the store view's configured scheme. They followed the current request, so
  anything built from cron or the command line — `/hreflang-sitemap.xml` included — listed an https
  store view's pages as `http://`.
- A page's head declares hreflang alternates only when the set includes the page itself, as Google
  requires. A store view excluded from hreflang used to list every other store view on its pages
  but not itself; and of two CMS translations assigned to one store view, the one its group does not
  name used to declare the other as its own-language version. Both now declare nothing. The head and
  the sitemap apply the rule through one class, `Model\Hreflang\SelfReference`.
- The Organisation and FAQ admin forms declare an ACL resource. Their controllers
  always did, but a UI component's data is also reachable through the generic
  `mui/index/render` endpoint, which checks the component's own `aclResource` and
  not the controller's — so an authenticated administrator with none of this
  module's permissions could read the form data.
- Organisation URLs, social profiles and the logo are validated before they are
  stored. They are published into JSON-LD and Open Graph tags, where they land in
  `href`, `src` and `@id` positions, so only `http`/`https` and genuine relative
  paths are accepted: `javascript:`, `data:` and friends are refused, as is
  `//host/path`, which adopts the page's scheme and points elsewhere. Parsing uses
  `Laminas\Uri`, with a control-character check first, because a URL with an
  embedded newline parses as valid. An invalid Organisation or logo URL refuses the
  save with a message; invalid social profiles are dropped and named.
- `/.well-known/security.txt` fields are cut at the first line break. The document
  is one directive per line (RFC 9116), so a newline in a configured value could
  forge further directives — an `Encryption:` or `Contact:` of someone else's
  choosing — from a field that looks like free text.
- `mageos_seo_general/feeds/storage_dir` is restricted and validated. It is an
  absolute path set from the admin panel, so as it stood an administrator could
  point feed writes at any directory PHP can reach. Inside the installation only
  `var/` is now accepted — the root and every other standard directory (`app/`,
  `bin/`, `dev/`, `generated/`, `lib/`, `pub/`, `setup/`, `update/`, `vendor/`) are
  refused — along with no hidden directories anywhere, no `..`, and the path
  resolved first so a symlink inside `var/` cannot stand for a target outside it.
  The shared mount a multi-server deployment needs is declared in `app/etc/env.php`
  under `mageos_seo/feed_storage_roots`, which the admin panel cannot edit. A declared
  root extends where feeds may go without opening up the codebase: the installation
  rule is applied first, so a root pointing at `pub/`, `app/` or `vendor/` is ignored
  rather than obeyed. The
  rules run on save, with the reason shown, and again when the value is read, since
  a configuration row can arrive from a data patch or the database without passing
  through the form; a refused value is logged and the feeds fall back to
  `var/mageos_seo`.
- The feeds and the `/.well-known/` documents no longer start a session. A session
  sets a cookie, which stops shared caches storing the response at all, and makes
  PHP emit `Pragma: no-cache` over the 24-hour policy the controller just set.
  None of these endpoints read session state. `/.well-known/` also gained the
  canonical-path redirect the feeds already had, so query-string and internal-URL
  variants collapse onto one cacheable URL.
- The hreflang sitemap and the `<link rel="alternate">` tags no longer advertise
  entities a visitor cannot reach. They were built from `url_rewrite` rows filtered
  only on the rewrite's own flags, and a rewrite outlives the state of the thing it
  points at — so disabling a product, deactivating a category or unpublishing a CMS
  page left it listed as an alternate, on every store view, until something else
  removed the row. The queries now join the entity's published state at each row's
  store view: product `status` and `visibility`, category `is_active`, CMS
  `is_active` plus its store assignment, with the store-view value overriding the
  global one as the storefront does. The queries moved to a new
  `Model\ResourceModel\UrlRewrite` — `UrlRewriteFetcher` keeps its two methods as
  accessors and its row-by-row streaming, so the memory profile is unchanged. The
  join to the entity table carries the link field from `MetadataPool`, so
  installations with content staging (`row_id`) work the same way.
- Admin product and category edit pages no longer fail with `ReflectionException:
  Class "…\Form\Modifier\Pool" does not exist`. The SEO form modifiers were
  registered with `<type>` on the virtual-type pool names, which replaced the pool
  definitions in the merged DI config (and dropped every other module's product
  form modifiers). The product modifier now extends Magento_Catalog's
  `<virtualType>`; the category pool is declared in full and wired into the
  category form data provider, so the SEO fieldset also appears on installations
  without Mage-OS's AutomaticTranslation module (which declares the same pool).
- The category "SEO (Structured Data)" and product "Advanced SEO" fieldsets are
  now saved from the admin forms. They were persisted by `afterSave` plugins on
  the catalog repositories, but core's admin save controllers call the models'
  `save()` directly, so the plugins never ran. Persistence now runs from
  admin-area observers: `controller_action_catalog_product_save_entity_after`
  for products (dispatched once, for the form's product only, so configurable
  variations, "Save & Duplicate" copies and "copy to store views" saves in the
  same request are left alone) and `catalog_category_save_commit_after` for
  categories (acting only on the category carrying the posted fieldset). The
  `SaveSeoConfigPlugin` and `SaveSeoOverridesPlugin` classes were removed.
- The product "Advanced SEO" fieldset binds to the form's `data` branch.
  `product_form.xml` declares no form-level `dataScope`, so the fieldset's empty
  scope bound its fields outside the loaded and submitted data: stored values
  never showed, edits were never posted, and a save posted the untouched loaded
  values instead.
- The category form now loads the stored SEO config. Core's category form data
  provider does not apply modifier pools to its data, so the fieldset always
  rendered empty, and once saving worked every category save would have posted
  those empty values over the stored config. A plugin on the provider's
  `getData()` now adds the values.
- A catalog, CMS page or store save no longer takes the SEO feeds offline. Every
  save used to delete the served feed files across all store views and purge the
  page cache before the rebuild was even queued, so `/llms.txt`, `/llms-full.txt`,
  `/llms.jsonl` and `/hreflang-sitemap.xml` answered `503` until the consumer ran,
  and a burst of saves repeated the file sweep and the purge for every save.
  Rebuilds also wrote files in place, so a request could read a partially written
  document.
- SEO-only save problems (invalid override JSON, an SEO-table failure) are
  reported as warnings rather than errors: core's category save controller
  treats any error message as a failed save and sent a newly created category
  back to the "add" page although it had been saved.
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
- `priceValidUntil` comes only from a special price's end date (see Changed).
  `itemCondition` is no longer hardcoded to NewCondition
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
- Catalog SEO fieldset persistence is registered for the admin area only, wraps
  SEO persistence in try/catch (an SEO-table failure no longer aborts an
  already-committed product/category save), and reports invalid override-JSON as
  an admin warning instead of silently wiping stored overrides.
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

- **Breaking: the FAQ, the llms documents and the agentic commerce profile use their own
  prefixes** — `mageos_faq`, `mageos_aeo` and `mageos_agentic` — ahead of their move to MageOS_Faq,
  MageOS_Aeo and MageOS_Agentic. Nothing is migrated: after upgrading, enter the moved settings
  again and grant admin roles the new resources. MageOS_Seo keeps `mageos_seo`, including the
  rebuild queue (`mageos.seo.feed.regenerate`).
  - **CLI commands, one colon each:**
    - `mageos:seo:feeds:regenerate` becomes **`seo:rebuild`** (MageOS_Seo's, like the rebuild layer
      it drives); its `-g` groups are unchanged;
    - `mageos:seo:ucp:keygen` becomes **`ucp:keygen`**;
    - with no feed group registered, a plain `seo:rebuild` says there is nothing to rebuild and
      how to rebuild the sitemaps, instead of printing "Done." having done nothing. Sitemaps are
      still rebuilt from the command only when asked for with `-g`.
  - **FAQ:**
    - the table `mageos_seo_faq` becomes `mageos_faq`; `setup:upgrade` creates it empty and drops
      the old one with its rows;
    - the ACL resource and menu item `MageOS_Seo::faq` become `MageOS_Faq::faq`, still under
      Marketing → SEO;
    - the admin moves to `mageos_faq/faq/*`, with the layout handles `mageos_faq_faq_index` /
      `_edit` and the UI components `mageos_faq_listing` / `mageos_faq_form`;
    - the cache tag is `mageos_faq` (and `mageos_faq_group_<identifier>`), and the model events
      are `mageos_faq_save_after` / `_delete_after`;
    - the widget id is `mageos_faq_list`;
    - the Page Builder content type is `mageos_faq`, in core's "Add Content" section; the
      "MageOS SEO" section is gone. Page Builder content saved with the old type no longer
      renders;
    - the list markup's CSS classes are `mageos-faq`, `mageos-faq__heading`, `__item`,
      `__question` and `__answer`. A theme that styled `.mageos-seo-faq*` must follow.
  - **The llms documents and AI crawler directives:**
    - their settings move from the SEO section to their own, **AI Information & Crawlers**:
      `mageos_seo_general/{llms_txt,feeds,ai_robots}/*` become `mageos_aeo/{llms_txt,feeds,ai_robots}/*`,
      under the ACL resource `MageOS_Aeo::config`;
    - the `env.php` key for storage outside `var/` is `mageos_aeo/feed_storage_roots`, and the
      default directory is `var/mageos_aeo/`. `var/mageos_seo/` is no longer read or cleaned, and can
      be deleted;
    - the cache tags are `MAGEOS_AEO_LLMS`, `MAGEOS_AEO_LLMS_FULL` and `MAGEOS_AEO_LLMS_JSONL`, the
      nightly cron job is `mageos_aeo_regenerate_feeds`, and the rebuild lock is
      `mageos_aeo_feed_rebuild`;
    - the internal URLs are `mageos-aeo/…` (they 301 to the documents), and the observer and plugin
      names in events.xml and di.xml carry `mageos_aeo_`.
  - **Agentic commerce:**
    - the section becomes **Agentic Commerce (UCP)**, `mageos_agentic/{general,signing,security_txt}/*`
      (was `mageos_seo_ucp`), under `MageOS_Agentic::config`. Keys made with the old command are
      not carried over: run `bin/magento ucp:keygen` again;
    - the internal URL is `mageos-agentic/wellknown/index/…` (it 301s to the document).
  - **The shared frontName `mageos-seo` is gone:** nothing in MageOS_Seo answers on it any more.
- **Breaking: Organisation is Organization in code**, matching the admin, whose labels are now US
  English ("Organization", "Organization Settings"). Storefront output is unchanged; it already
  said Organization.
  - **Classes** (`Organisation` → `Organization` in each name): `Api\Data\OrganizationInterface`,
    `Api\OrganizationRepositoryInterface`, `Model\Organization`, `Model\OrganizationRepository`,
    `Model\Organization\{ContactEmail,UrlValidator}`, `Model\ResourceModel\Organization` and its
    `Collection`, `Model\StructuredData\OrganizationId`,
    `Model\StructuredData\Provider\OrganizationProvider`,
    `Observer\RemoveOrganizationOnScopeDelete`, `Ui\DataProvider\OrganizationDataProvider`, and the
    admin `Controller\Adminhtml\Organization\*` and `Block\Adminhtml\Organization\SaveButton`.
    Constructor parameters follow (`$organizationRepository`, `$organizationId`).
  - **The table** `mageos_seo_organisation` becomes `mageos_seo_organization`, and **nothing is
    migrated**: `setup:upgrade` creates the new table empty and drops the old one with its rows.
    Enter the Organization settings again after upgrading.
  - **The ACL resource** `MageOS_Seo::organisation` becomes `MageOS_Seo::organization`. A role
    that granted the old one must grant the new one.
  - **Admin URLs** `mageos_seo/organisation/*` become `mageos_seo/organization/*`, with the layout
    handle `mageos_seo_organization_edit` and the UI component `mageos_seo_organization_form`.
  - **The cache tag** is `mageos_seo_organization`.
  - **Events** are `mageos_seo_organization_save_after` / `_delete_after`, and carry the model
    under `organization` as well as `data_object`. The observer names in events.xml follow.
  - **The structured-data provider** is registered in the compositor's pool as `organization`.
- The llms documents', feed storage's and AI crawler directives' settings are read by a class of
  their own, `Model\Aeo\Config`, instead of `Model\Config`.
  - **Moved with the same names:** `isLlmsTxtEnabled()`, `isLlmsFullTxtEnabled()`,
    `isLlmsJsonlEnabled()`, `getLlmsFaqGroups()`, `getFeedStorageDir()`, `isAiRobotsEnabled()`,
    `getAiDisallowedBots()`, and the constants `XML_LLMS_ENABLED`, `XML_LLMS_FULL_ENABLED`,
    `XML_LLMS_JSONL_ENABLED`, `XML_LLMS_FAQ_GROUPS`, `XML_FEEDS_STORAGE_DIR`,
    `XML_AI_ROBOTS_ENABLED` and `XML_AI_ROBOTS_DISALLOWED`.
  - **Constructors changed:** these now take `Model\Aeo\Config` instead of `Model\Config`:
    - the `llms.txt`, `llms-full.txt` and `llms.jsonl` controllers;
    - `Feed\FeedRegenerator`, `Feed\FeedStorage` and `Feed\LlmsInvalidationPolicy`;
    - `LlmsTxt\FaqLlmsSectionProvider`;
    - `Plugin\Robots\AppendAiDirectivesPlugin`.

  The configuration paths, their scopes and what each getter returns are unchanged.
- The paths served as public documents are registered rather than hard-coded.
  `Model\Router\PublicPaths` takes them as `paths` and `prefixes` arguments in di.xml, and its
  `LLMS_ROUTES`, `WELL_KNOWN_PREFIX` and `MODULE_FRONT_NAME` constants are gone.
  - The llms route map is now `Router\LlmsTxtRouter`'s own constant, and the `.well-known/` prefix
    is `Router\WellKnownRouter::PREFIX`.
  - `Model\Feed\CanonicalPathRedirect` moves to `Model\Router\CanonicalPathRedirect`.

  The same paths get no session and the same redirects as before. A module serving its own public
  documents registers its paths in `PublicPaths` to keep sessions off them too.
- **Breaking for custom FAQ sources:** `Api\FaqSourceProviderInterface` gains
  `getIdentifiers(): string[]`, the group identifiers the source has FAQs for. A source that cannot
  list them returns `[]`.
  - `Model\Faq\SourcePool::getIdentifiers()` merges every source's.
  - The llms FAQ Groups setting (`Config\Source\FaqGroups`, whose constructor now takes the pool
    instead of `Faq\Repository`) offers them. So another module's FAQ groups can be chosen too,
    where before only the FAQ table's were listed.
- The rebuild queue is generic, and no longer names the llms documents.
  - **Moved to `Model\Rebuild`:**
    - `Model\Feed\RegenerationRequester` and `Model\Feed\RegenerateConsumer` move there; the
      consumer's handler in `queue_consumer.xml` follows;
    - `Model\Feed\FeedInvalidator` becomes `Model\Rebuild\Invalidator`. Its
      `invalidateLlms()` / `invalidateJsonl()` are replaced by `invalidate(string $group)` for any
      registered group.
  - **Split:**
    - `Model\Feed\InvalidationPolicy` becomes `Model\Sitemap\InvalidationPolicy`,
      `Model\Feed\LlmsInvalidationPolicy` and `Model\Rebuild\ChangeInspector`;
    - `Plugin\Catalog\Product\Action\InvalidateFeedsOnMassAttributeUpdate` becomes
      `InvalidateSitemapOnMassAttributeUpdate` and `InvalidateJsonlOnMassAttributeUpdate`.
  - **Now registered, not hard-coded:** the llms documents register with the queue as
    `Model\Feed\LlmsRebuildHandler`, and the regenerate command and `Setup\RecurringData` read
    their groups from the pool.
  - **Constructors changed:**
    - `RegenerateConsumer` takes the `HandlerPool`;
    - `RegenerateFeedsCommand` takes the `HandlerPool` instead of `FeedRegenerator`;
    - `RecurringData` takes the `Invalidator` and the `HandlerPool`;
    - the FAQ Save and Delete and the Organisation Save admin controllers no longer take
      `FeedInvalidator` (see Fixed).

  The groups, the queue topic, what counts as a relevant change, and what the command does are
  unchanged. Its help now lists the registered groups (`llms, jsonl`) instead of the llms file
  names.
- `Api\UcpCapabilityProviderInterface::getCapabilityData()` returns **one** capability entry
  (`version`, `schema`, …). It is listed under `getCapabilityKey()` along with any other provider's
  entries for that name, where before it was merged into the manifest as given. Constructors
  changed with it:
  - `Ucp\CapabilityPool` takes `EntryValidator` and `LoggerInterface` before `$providers`;
  - `Ucp\ProfileBuilder` takes `ServicePool` and `LoggerInterface`.
- An offer's `priceValidUntil` is a special price's end date and nothing else: published when the
  product has a special price and its `special_to_date` is today or later in store time
  (inclusive). The synthetic date, "today plus N months", is gone — it promised a validity nothing
  in the store backed — so an offer without a special price, or one without an end date, has no
  `priceValidUntil`. A `special_to_date` without a special price no longer publishes a date either.
  **`Model\Product\OfferBuilder`'s constructor** no longer takes `Config`. Another source of dates
  plugs in as an `Api\OfferEnricherInterface`. See `docs/structured-data.md#an-offers-pricevaliduntil`.
- A store view's locale is read in one place, `Model\Config::getLocaleCode()`, by hreflang,
  `og:locale` and the llms documents. Constructors changed with it: `Hreflang\StoreLocaleMap` and
  `MetaTag\Provider\SiteMetaProvider` no longer take `ScopeConfigInterface`; `LlmsTxtBuilder` takes
  `Config` and `Organisation\ContactEmail` before `$sectionProviders`; `Ucp\AiPluginBuilder` takes
  `ContactEmail`; `LlmsTxt\FaqLlmsSectionProvider` takes `Config`. Code that builds them through
  the object manager or di.xml by name is unaffected.
- **hasVariant Max Entries** is now **Most Variants per Configurable Product** (same path,
  `mageos_seo_general/structured_data/has_variant_max`): a configurable with more sellable
  children than this gets one `AggregateOffer` over their whole price range instead of a variant
  list cut short.
- The module requires `magento/module-configurable-product`, which every distribution ships.
- With the MageOS SEO sitemap generator selected — the default — `sitemap.xml` is always a sitemap
  index, listing `{name}-{store}-pages-1.xml`, `-categories-`, `-products-` and so on. Search
  engines need only the one URL they already have. Files core's generator wrote for the same
  sitemap are removed on the first generation.
- The robots dropdowns on the product, category and CMS page forms offer one empty option each,
  saying where the page falls back to. The product form's "Use Category / Global Default" was
  wrong: a product's directive never comes from its category.
- Hreflang codes are deduplicated per code rather than per store view, so two store views on the
  same locale both keep their alternates once either is given its own code.
- `magento/module-sitemap` is now a dependency.
- **Breaking:** the support floor moves to **Magento 2.4.7 and PHP 8.3**
  (`magento/framework ^103.0.7`, `php ~8.3.0 || ~8.4.0 || ~8.5.0`), matching the
  Mage-OS monorepo. With it, the version polyfills go: the bundled
  `Compat/ResetAfterRequestInterface` is deleted, along with the conditional
  `require` in `registration.php` and the `exclude-from-classmap` entry it needed.
  That polyfill declared a *framework-namespace* symbol from a module, and
  `exclude-from-classmap` only applies to a composer package — installed under
  `app/code`, the psr-0 fallback classmapped it and shadowed the real interface on
  `composer dump-autoload -o`. 2.4.7 is the first release that ships the interface,
  so nothing needs supplying. `Model\Cache\CleaningMode` likewise no longer names
  `Magento\Framework\Cache\CacheConstants` (2.4.9-only); the cleaning-mode
  identifier is the same string on every supported version and is written out once,
  with a note on where it came from.
- `magento/module-url-rewrite` and `magento/module-review` are now declared
  dependencies. Both were already required in practice — the hreflang sitemap reads
  `url_rewrite` and the rating provider reads `review_entity_summary` — but neither
  appeared in `composer.json`, because a dependency reached through a table name is
  invisible to a class-based scan. `magento/module-page-builder` is declared as a
  `suggest`: it is needed only for the Page Builder FAQ content type, and
  `etc/module.xml` already sequenced it.
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
  built-in providers only act when an explicit title exists (such as a product's
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
  503 Retry-After only while a feed has never been generated. The nightly cron
  remains as a full-rebuild safety net. The feed storage directory is configurable
  (`mageos_seo_general/feeds/storage_dir`) for multi-server deployments.
- Feed invalidation no longer deletes the served files or purges the page cache.
  Invalidation only queues a rebuild; the consumer and the nightly cron replace each
  file atomically (temporary file + rename) and purge the rebuilt group's cache tags
  once, after all store views are written. The hreflang sitemap writes its chunk
  files before the index and removes surplus chunks afterwards. Files of a feed
  disabled for a store view are removed on the next rebuild. A queued rebuild not
  picked up within an hour is queued again with a logged warning, so a lost message
  or a stopped consumer no longer disables event-driven rebuilds permanently.
  `FeedStorage::deleteForAllStores()` and the `FeedInvalidator::FILES_*` constants
  were removed; cache tags and the cache policy now live in `Model\Feed\FeedCache`.
  Feed files are written with mode `0640` and feed directories with `0750`
  regardless of the process umask (previously `0666`/`0777` minus the umask, i.e.
  world-writable under a `000` umask); cron/consumer and the web PHP user must share
  an owner or group.
- Feed rebuilds are only queued for changes that can affect a feed
  (`Model\Feed\InvalidationPolicy`): a feed that no store view can build is never
  queued (`/llms.jsonl` is disabled by default; the hreflang sitemap needs two
  active store views), and the hreflang sitemap is only rebuilt for URL-relevant
  product, category and CMS page changes. Product saves now also rebuild
  `/llms.txt` and `/llms-full.txt` when they change category product counts (new
  product, changed category assignments or changed website assignments), which
  were previously left stale.
- Deletions now invalidate the feeds they remove content from: a deleted product
  rebuilds all three feeds, a deleted category `/llms.txt`, `/llms-full.txt` and
  the sitemap, a deleted CMS page the sitemap. Previously a deleted entity stayed
  in the served feeds until the nightly rebuild.
- Mass catalogue actions invalidate the feeds too. They write straight to the
  catalogue tables without saving the products, so no save event reports them: a
  mass attribute update (admin "Update attributes", mass enable/disable, and the
  same service elsewhere) rebuilds `/llms.jsonl`, plus the hreflang sitemap when
  `url_key`, `status` or `visibility` is among the updated attributes
  (`Plugin\Catalog\Product\Action\InvalidateFeedsOnMassAttributeUpdate`); a mass
  website assignment change rebuilds all three
  (`catalog_product_to_website_change`).
- The per-category and per-product SEO repositories are built on Magento's model
  layer instead of hand-written SQL. `Model\Category\ConfigRepository` and
  `Model\Category\ProductOverrideRepository` assembled `Select`s and called
  `insertOnDuplicate()` straight on a `ResourceConnection` adapter; they now read
  through collections and write through models and resource models
  (`Model\CategoryConfig`, `Model\ProductOverride` and their resource models and
  collections are new). Public method signatures are unchanged, so nothing that
  uses them changes. Reading a category's configuration is also one query rather
  than one per ancestor level: the whole category path is fetched at once.
- The SEO tables now have foreign keys. `mageos_seo_category_config`,
  `mageos_seo_product_override` and `mageos_seo_faq` reference
  `catalog_category_entity`, `catalog_product_entity` and `store` with
  `ON DELETE CASCADE`, so a deleted category, product or store view takes its SEO
  records with it instead of leaving rows that a later entity with the same ID —
  after a restore or an import that writes explicit IDs — would silently inherit.
  `mageos_seo_organisation` cannot have one: its `scope_id` is a website ID or a
  store view ID depending on the `scope` column, the same shape as
  `core_config_data`. `Observer\RemoveOrganisationOnScopeDelete` clears it as the
  scope is deleted, mirroring core's own `clearScopeData()` calls in
  `Website::beforeDelete()` / `Group::beforeDelete()`, and
  `OrganisationRepositoryInterface` gains `deleteForScope()`.
- Deleting a store view queues the sitemap rebuild from `store_delete_before`
  rather than `store_delete`. The rebuild is also what removes a sitemap that can
  no longer be built, and `store_delete` is dispatched once the store view is
  already gone: deleting the second-to-last store view left one, the sitemap
  counted as unbuildable, nothing was queued, and the surviving store view carried
  on serving a sitemap listing the store view that had just been deleted until the
  nightly rebuild. The store's own feed directory is still removed after the delete
  commits, so a delete that rolls back keeps its files.
- Deleting a store group or a website now invalidates the hreflang sitemap, and its
  store views' feed files are cleaned up. Core removes those store views with a
  database-level cascade that dispatches no `store_delete` event, so nothing had
  noticed them going: the sitemap kept listing them, and their feed directories
  stayed on disk for good. A full rebuild now also sweeps feed directories whose
  store view no longer exists.
- Feed file listings no longer come back stale. `Magento\Framework\Filesystem\Glob`
  memoises every glob result for the life of the process, so a rebuild that listed
  its own output — surplus sitemap chunks, store directories — could act on the
  listing from before it wrote anything. It matters wherever one process rebuilds
  more than once: the queue consumer with `--max-messages`, or the CLI command.
- Moving a category rebuilds `/llms.txt`, `/llms-full.txt` and the hreflang sitemap.
  A move re-parents the category (core regenerates its URL rewrites) and changes the
  shape of the category tree, but dispatches no save event, so nothing invalidated
  the feeds. `/llms.jsonl` carries product URLs without category paths and is
  unaffected.
- Deleting a store view rebuilds the hreflang sitemap — its alternates change —
  and removes that store view's feed directory, which nothing would otherwise
  clean up (`Observer\RemoveFeedFilesOnStoreDelete`,
  `FeedStorage::deleteStoreDirectory()`).
- Feed builds stream to their file instead of assembling the whole document in memory:
  `/llms.jsonl` is written one line at a time from a paged collection, and the hreflang
  sitemap streams its URL rewrites row by row (`UrlRewriteFetcher::fetchAllForType()`
  became `streamAllForType()`, `SitemapGenerator::generate()` became `streamBlocks()`
  plus the document framing, and the new `Model\Hreflang\SitemapFileWriter` writes the
  file set). Peak memory is one page of products rather than the whole feed.
- The hreflang sitemap is built once per alternate set. The document lists every store
  view of the set, so store views sharing one produce byte-identical chunk files: the
  first store view of a set builds them, the rest copy them and write only their own
  index (which carries their base URL). A rebuild costs one catalogue pass per alternate
  set instead of one per store view.
- All feed responses (`/llms.txt`, `/llms-full.txt`, `/llms.jsonl`,
  `/hreflang-sitemap*.xml`) are cacheable for 24 hours
  (`Cache-Control: public, max-age=86400, s-maxage=86400`); the llms feeds
  previously sent `max-age=3600`, and the documentation said one hour.
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
