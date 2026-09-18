# AI Discoverability (llms.txt)

The module serves two plain-text documents at well-known URLs so LLM crawlers and AI agents can understand the site without full crawl cycles. This follows the emerging `llms.txt` convention.

---

## The two documents

| URL | Content | Config toggle |
|---|---|---|
| `/llms.txt` | Concise: org name, description, base URL, locale, available schema types, AI contact email | Stores → Configuration → MageOS → SEO → Enable /llms.txt |
| `/llms-full.txt` | Extended: everything in the concise version plus social profiles, full category tree with product counts, full template list | Stores → Configuration → MageOS → SEO → Enable /llms-full.txt |

Both return `404` when their respective config toggle is off.

Both config toggles are per-store-view settings.

---

## Content of /llms.txt

```
# Organisation Name
> Description tagline
> Base URL: https://example.com
> Locale: en_GB

## Key URLs
- Home: https://example.com
- Sitemap: https://example.com/sitemap.xml
- Search: https://example.com/catalogsearch/result?q={query}

## Schema types available on this site
GenericProduct, Food, Apparel, ...

## AI Contact
support@example.com
```

---

## Content of /llms-full.txt

Everything in `/llms.txt`, plus:

- Social profile URLs (from Organisation → Social profiles)
- A full schema type list (Organization, WebSite, CollectionPage, BreadcrumbList, ItemList, Product, FoodProduct, Apparel, ...)
- A full template-to-label list
- The complete category tree with product counts and URLs, indented by depth:

```
## Category Tree
- Clothing (245 products): https://example.com/clothing
  - Women's (148 products): https://example.com/clothing/womens
    - Dresses (62 products): https://example.com/clothing/womens/dresses
  - Men's (97 products): https://example.com/clothing/mens
```

- Any sections contributed by bridge modules

---

## Clean URLs

No setup is required: a custom router serves `/llms.txt`, `/llms-full.txt` and
`/llms.jsonl` directly. Do **not** add manual URL rewrites for these paths — the
internal controller URLs (`/mageos-seo/...`) 301-redirect to the canonical paths,
so a rewrite would fight the router.

---

## Generation & cache

The documents are **pre-generated to files** (default `var/mageos_seo/store_<id>/`;
configurable via `mageos_seo_general/feeds/storage_dir` for multi-server deployments
with a shared mount), mirroring core `Magento_Sitemap`. The same pipeline serves
`/llms.jsonl` and `/hreflang-sitemap.xml`. Two background processes write the files:

- the `mageosSeoFeedRegenerate` **queue consumer** rebuilds a feed group whenever it
  is invalidated (started by the default `consumers_runner` cron, or your process
  manager e.g. supervisor). Duplicate invalidations are collapsed: at most one build
  per feed group is queued at a time, and changes arriving during a build queue
  exactly one follow-up rebuild;
- the `mageos_seo_regenerate_feeds` **cron job** (nightly) does a full rebuild as a
  safety net for changes that carry no invalidation event.

Web requests **never** build the documents. An invalidation only queues a rebuild: the
current files keep being served until the consumer has written their replacements.
Each file is written to a temporary file and renamed into place, so a request never
sees a partially written document. When a file does not exist yet (fresh install, new
store view), the controller queues a rebuild and answers `503` with `Retry-After`
until the consumer has written it. Requests with query strings — and the internal
`/mageos-seo/...` controller URLs — are 301-redirected to the canonical path so they
cannot be used to force cache misses.

A queued rebuild that the consumer has not picked up within one hour is queued again,
and a warning is logged (`the "<group>" feed rebuild … was never picked up`). If you
see that warning, the consumer is not running: check your cron or process manager.

Responses are served with `Cache-Control: public, max-age=86400, s-maxage=86400`, so
browsers, Varnish and the built-in full page cache keep them for **24 hours**. They
are tagged `MAGEOS_SEO_LLMS` (`/llms.txt`), `MAGEOS_SEO_LLMS_FULL` (`/llms-full.txt`),
`MAGEOS_SEO_LLMS_JSONL` (`/llms.jsonl`) and `MAGEOS_SEO_HREFLANG_SITEMAP`
(`/hreflang-sitemap.xml` and its chunks). After rebuilding a feed group, the consumer
and the cron purge that group's tags, so cached copies are replaced as soon as the new
files exist. (The built-in full page cache always sends clients a `no-cache` header and
serves its own copy; the feed's own policy is visible in `X-Magento-Cache-Control`.)

A rebuild is queued automatically when:

| Change | `/llms.txt`, `/llms-full.txt` | `/llms.jsonl` | Hreflang sitemap |
|---|---|---|---|
| Product saved | new product, or its categories or websites changed (product counts) | always | new product, or `url_key`, `status`, `visibility` or websites changed |
| Category saved | always | — | new category, or `url_key` or `is_active` changed |
| CMS page saved | — | — | new page, or identifier, `is_active` or store views changed |
| Store view saved | — | — | always |
| Category moved | always | — | always |
| Product deleted | always | always | always |
| Category deleted | always | — | always |
| CMS page deleted | — | — | always |
| Store view deleted | — | — | always |
| Store group or website deleted | — | — | always |
| Mass attribute update | — | always | `url_key`, `status` or `visibility` among the updated attributes |
| Mass website assignment change | always | always | always |
| Organisation settings saved | always | — | — |
| FAQ saved or deleted | always | — | — |

Mass actions — the admin grid's "Update attributes", mass enable/disable and mass website
assignment, and anything else going through `Magento\Catalog\Model\Product\Action` — write
straight to the catalogue tables without saving the products, so no save event reports them.
They are covered separately (a plugin for attributes, the `catalog_product_to_website_change`
event for websites).

Deleting a store view also removes that store's feed directory, and queues the sitemap rebuild
that corrects the store views left behind. That includes the deletion that leaves only one store
view: a single store view has no alternates, so the sitemap can no longer be built — and the
rebuild is what removes the one the survivor is still serving, rather than leaving it listing a
store view that no longer exists.

Deleting a **store group or a website** takes its store views with it in the database, without
dispatching a `store_delete` event for any of them, so the rebuild is queued from the website's
or group's own deletion instead. Their feed directories are removed by the next full rebuild
(the nightly cron, or `mageos:seo:feeds:regenerate` with no `-g`), which sweeps directories
whose store view no longer exists. Until then nothing serves them: a request resolves feeds for
the current store view, and theirs is gone.

A feed that no store view can build is never queued: `/llms.jsonl` while it is disabled in
every store view (the default), `/llms.txt` + `/llms-full.txt` when both are disabled
everywhere, and the hreflang sitemap when it is disabled, hreflang is disabled in every
store view, or fewer than two store views are active. The logic lives in
`MageOS\Seo\Model\Feed\InvalidationPolicy`.

Changes that no event reports — native CSV imports, direct database writes, configuration
changes — are picked up by the nightly rebuild.

A feed that is disabled for a store view is removed from that store's directory on the
next rebuild, so re-enabling it later produces a fresh build rather than an outdated file.

### Build cost

The large feeds are **streamed to their file** rather than assembled in memory:
`/llms.jsonl` is built one product per line from a paged collection, and the hreflang
sitemap reads its URL rewrites row by row, writing each `<url>` block as it goes. Peak
memory is that of one page of products, not of the whole document — at 100k SKUs the
jsonl document alone runs to tens of megabytes.

An hreflang sitemap over 50,000 URLs is split into `hreflang-sitemap-<n>.xml` chunk files
with a sitemap index at `/hreflang-sitemap.xml`; below that cap it is a single `<urlset>`
served at that same URL. Which of the two it is only becomes clear once the stream ends,
so each file is named as it is committed. Chunks are written before the index, and chunks
the new set no longer contains are removed after it — the served index never points at a
file that is not there.

The sitemap lists every store view of an alternate set, so store views sharing a set
produce byte-identical chunk files. Only the **first store view of a set builds them**;
the rest copy those files and write only their own index, which carries their base URL.
A rebuild that would otherwise cost "whole catalogue × store views" therefore costs one
catalogue pass per alternate set — one per website when hreflang is limited to the
current website, one for the whole install when it is not.

Feed files are written with mode `0640` and the feed directories (`var/mageos_seo/` and
each `store_<id>/`) with `0750`, whatever the process umask. The user running cron and
the consumer and the web server's PHP user must therefore be the same user or share a
group — Magento's standard file-ownership model. With a custom `storage_dir`, the root
directory you configure keeps its own permissions; only the `store_<id>/` directories
below it are set to `0750`.

Every `setup:install` / `setup:upgrade` queues a rebuild of the feeds the store views can
build, so a fresh install or a deployment that cleared `var/` does not wait for the nightly
cron.

To rebuild immediately — in a deployment script, or after changing feed configuration —
run:

```bash
bin/magento mageos:seo:feeds:regenerate            # every feed, every active store view
bin/magento mageos:seo:feeds:regenerate -g llms    # one group: llms | jsonl | hreflang
```

It builds in the running process (no queue consumer needed), replaces each file in place,
purges the rebuilt groups' cache tags, and exits non-zero if any store view failed. To
process rebuilds that are already queued instead, run `bin/magento queue:consumers:start
mageosSeoFeedRegenerate --max-messages=10`; otherwise wait for the consumer or the nightly
cron.

---

## Data sources

Both documents draw data from:

| Data | Source |
|---|---|
| Organisation name, description, URL, social profiles | Organisation record (store-scoped, same fallback as JSON-LD) |
| Locale | `StoreManagerInterface::getStore()->getLocaleCode()` |
| Schema template list | `SchemaBuilderPool::getAvailableTemplates()` |
| Category tree | Live `catalog_category_entity` collection, active categories only, level > 1 |
| AI contact email | `trans_email/ident_support/email` system config |

---

## Adding content from a bridge module

Register a `SectionProviderInterface` implementation in your bridge module's `di.xml`:

```php
// MyModule/Model/LlmsTxt/MySectionProvider.php
class MySectionProvider implements \MageOS\Seo\Model\LlmsTxt\SectionProviderInterface
{
    public function getConciseSection(): string
    {
        return "## Vendors\n- 42 active makers on this platform";
    }

    public function getFullSection(): string
    {
        // Return a fuller list, or '' to contribute nothing to the full document
        return "## Vendors\n" . $this->buildVendorList();
    }
}
```

```xml
<!-- MyModule/etc/di.xml -->
<type name="MageOS\Seo\Model\LlmsTxt\LlmsTxtBuilder">
    <arguments>
        <argument name="sectionProviders" xsi:type="array">
            <item name="mySection" xsi:type="object">
                MyModule\Model\LlmsTxt\MySectionProvider
            </item>
        </argument>
    </arguments>
</type>
```

Return an empty string from either method to contribute nothing to that document. Sections are appended in the order they are registered in `di.xml`.
