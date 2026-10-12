# Robots Meta

The module controls the `<meta name="robots">` tag on product, category and CMS pages. It supports a global default per page type, category-level overrides, product-level overrides and CMS-page-level overrides.

---

## Global defaults

**Stores → Configuration → MageOS SEO → SEO → Robots Meta Defaults**

| Setting | Default | Description |
|---|---|---|
| Product pages | *(empty — use Magento default)* | Applies to all product pages without a specific override |
| Category pages | *(empty — use Magento default)* | Applies to all category pages without a specific override |
| CMS pages | *(empty — use Magento default)* | Applies to all CMS pages, the home page included, without a specific override |
| Search results pages | `NOINDEX,FOLLOW` | Applies to quick search and advanced search results |

The page defaults ship empty ("Use Magento Default"): until you configure a value, Magento core's
**Design → Search Engine Robots** setting stays in charge, so installing the module never
re-opens a NOINDEXed environment. These are per-store-view settings — you can set a stricter
default (e.g. `NOINDEX,FOLLOW`) on a specific store view while keeping another value globally.

Search results pages are the exception: they ship as `NOINDEX,FOLLOW`, Google's advice for
internal search. Like any value here it replaces core's setting on those pages, so a staging site
set to `NOINDEX,NOFOLLOW` gets `NOINDEX,FOLLOW` on its search pages: still out of the index. Set it
to "Use Magento Default" to let core's setting decide them too.

**Search results pages: index or crawl?** The two ways of handling internal search results do
different jobs, and don't combine:

- **`NOINDEX,FOLLOW`** here keeps result pages out of the index, while their links still lead
  crawlers to your products. Google still fetches each result page to read the tag.
- **A robots.txt `Disallow`** (Content → Design → Configuration → Search Engine Robots — core's
  suggested instructions include `Disallow: /*?`) stops result pages being crawled at all, which is
  [Google's advice for crawl budget](https://developers.google.com/search/docs/crawling-indexing/large-site-managing-crawl-budget):
  "Don't use `noindex`, as Google will still request, but then drop the page".
- Do one or the other for a URL: a page blocked in robots.txt is never fetched, so its robots meta
  is never read.

Paginated search results (`?p=2`) follow the same default; **Override Robots on Paginated Pages**
applies to category pages only (see [Paginated category pages](#paginated-category-pages)).

**Accepted values:** the dropdown offers each combination of `INDEX`/`NOINDEX` with
`FOLLOW`/`NOFOLLOW`, each of those with `noarchive`, `INDEX,FOLLOW` with rich-preview limits
(`max-image-preview:large,max-snippet:-1`), and `NOINDEX,NOFOLLOW,noai,noimageai`. The same list
is used by every override below.

---

## How the value is applied

The robots meta value is set on the `PageConfig` object rather than output directly by a block. This means it participates in Magento's standard `<head>` rendering, and only one robots meta tag ever appears on the page regardless of how many places try to set it.

`MageOS\Seo\Observer\ApplyRobotsMeta` runs once per frontend page on
`layout_generate_blocks_after`, after the controller has put the product, category or CMS page in
place and before the head renders. It asks the provider pool
(`MageOS\Seo\Model\RobotsMeta\Resolver`) for a directive; each provider checks its own entity-level
override and falls back to the configured default. When no provider has an opinion, nothing is
written and Magento's own **Design → Search Engine Robots** value stands.

### Other modules' restrictions are kept

The resolved directive is **composed with** the page's current robots value, not written over it.
Otherwise it discards what other modules set — including `MageOS_MetaRobotsTag`, which ships in
the Mage-OS distribution and turns `INDEX` into `NOINDEX` from per-product, per-category and
per-CMS-page flags.

The rule, in `MageOS\Seo\Model\RobotsMeta\DirectiveComposer`: a **restriction** on the page that
is **not part of core's default** was added by another module, and survives. A restriction is any
directive spelled `no…` — `noindex`, `nofollow`, `noarchive`, `nosnippet`, `noimageindex`, `noai`,
`noimageai` — so a third party's is recognised without being listed. Everything that came from
core's Design setting is this module's to override, as documented above.

| Core default | On the page before this module | This module resolves | Written |
|---|---|---|---|
| `INDEX,FOLLOW` | `NOINDEX,FOLLOW` — another module's per-page flag | `INDEX,FOLLOW` | `NOINDEX,FOLLOW` |
| `NOINDEX,NOFOLLOW` — a staging store | `NOINDEX,NOFOLLOW` | `INDEX,FOLLOW` | `INDEX,FOLLOW` |
| `NOINDEX,NOFOLLOW` | `NOINDEX,NOFOLLOW,NOARCHIVE` | `INDEX,FOLLOW` | `INDEX,FOLLOW,NOARCHIVE` |

The outcome does not depend on which module's observer runs first — on CMS pages another module
typically runs earlier (`cms_page_render` precedes layout generation), on catalog pages the order
is not pinned. If it runs earlier its restriction is carried through here; if it runs later it
patches this module's value itself.

---

## Category-level override

In the category edit form, the **SEO (Structured Data)** tab includes a **Robots Meta** dropdown.
Setting a value here overrides the store's **Category Pages** default for the category's own page,
and for any subcategory that does not set one of its own — see
[Inheritance](category-seo.md#inheritance).

It does **not** reach the products in the category: a product page follows its own override, then
the store's **Product Pages** default. To keep a category's products out of the index, set the
override on the products, or the Product Pages default for the store view.

This is a per-store-view setting — open the category in the context of a specific store view to set a store-specific override.

Common use cases:
- Set `NOINDEX,FOLLOW` on internal/sorting categories you don't want indexed.
- Set `NOINDEX,NOFOLLOW` on a staging or preview category.

Leave it on **Inherit (parent category, then the store's Category Pages default)** to take the
value of the nearest parent category that sets one, and failing that the store's default.

### Paginated category pages

**Override Robots on Paginated Pages** (off by default) gives a category's page 2 and later
(`?p=2` onward) the **Paginated Pages Robots** value, commonly `NOINDEX,FOLLOW`, so the later
pages of a listing stay out of the index while their links are still followed. Page 1 always
follows the category.

It can only add restrictions, never relax one. Every restriction of the category's own directive,
set on the category, inherited from a parent or the Category Pages default, is carried onto the
paginated value:

| Category | Paginated Pages Robots | Page 2 and later |
|---|---|---|
| `NOINDEX,FOLLOW` | `INDEX,FOLLOW` | `NOINDEX,FOLLOW` |
| `INDEX,NOFOLLOW` | `NOINDEX,FOLLOW` | `NOINDEX,NOFOLLOW` |
| `INDEX,FOLLOW,noarchive` | `NOINDEX,FOLLOW` | `NOINDEX,FOLLOW,noarchive` |
| *(none)* | `INDEX,FOLLOW` | `INDEX,FOLLOW` |

There is no setting to relax a category's restriction on its later pages: a category that should
be indexed there is set to `INDEX` itself. As for every directive of this module, core's
**Design → Search Engine Robots** default is not one of the protected restrictions (see
[Other modules' restrictions are kept](#other-modules-restrictions-are-kept)).

---

## Product-level override

In the product edit form, the **Advanced SEO** tab includes a **Robots Meta** dropdown. This overrides the store's **Product Pages** default for that specific product and store view. Category overrides play no part in a product page's directive.

The product override is stored per store view (store_id), with `store_id = 0` acting as an all-stores default. A store-specific row takes precedence over the all-stores row.

Common use cases:
- `NOINDEX,FOLLOW` on discontinued products you keep live for existing links.
- `NOINDEX,NOFOLLOW` on draft products visible in the store but not ready for indexing.

---

## CMS-page-level override

In the CMS page edit form, the core **Search Engine Optimization** section includes a **Robots
Meta** dropdown. It overrides the store's **CMS Pages** default for that one page. Leave it on
**Use the store's CMS Pages default** to follow the store setting.

The value is stored in `mageos_seo_cms_page_config`, not on `cms_page`, and applies wherever the
page is served. There is no per-store-view value to set from the admin: the CMS page form has no
store switcher, and a page's store views are chosen by its **Store View** assignment — to give two
store views different directives, give them different pages.

The table does carry a `store_id`, and a store view's row wins over the global (`store_id = 0`)
row when both exist. The admin form only ever reads and writes the global row. Store-view rows come
from integrations writing through `MageOS\Seo\Model\Cms\ConfigRepository` directly, and from the
[import from MageOS_MetaRobotsTag](#moving-from-mageos_metarobotstag), which reports each page it
gives one.

Common use cases:
- `NOINDEX,FOLLOW` on thin utility pages (a no-results page, a campaign landing page after the
  campaign).
- `INDEX,FOLLOW,noarchive` on pages whose content changes often enough that a cached copy
  misleads.

Coming from `MageOS_MetaRobotsTag`: its per-page flags are carried over into this override, as
its product and category flags are into theirs. See
[Moving from MageOS_MetaRobotsTag](#moving-from-mageos_metarobotstag).

---

## Resolution order

Each page type resolves on its own, and the most specific setting wins. They do not feed into one
another — in particular, a category's override never reaches its products.

Product pages:

```
Product Pages default (system config)
    ↑ overridden by
Product override (mageos_seo_product_override.robots_meta — the store view's row, else all stores)
```

Category pages:

```
Category Pages default (system config)
    ↑ overridden by
Nearest parent category's override (see category-seo.md, Inheritance)
    ↑ overridden by
The category's own override (mageos_seo_category_config.robots_meta)
    + on page 2 and later, with Override Robots on Paginated Pages:
      the Paginated Pages Robots value, keeping every restriction of the above
```

CMS pages, which form no tree:

```
CMS Pages default (system config)
    ↑ overridden by
CMS page override (mageos_seo_cms_page_config.robots_meta)
```

Search result pages, which have no per-page override:

```
Search Results Pages default (system config)
```

Where nothing along the chain has a value — the defaults ship empty — this module writes nothing,
and Magento's own **Design → Search Engine Robots** setting stays in charge, as described under
[Global defaults](#global-defaults).

The XML sitemap resolves each page the same way, through the same classes, and leaves out the pages
that come out NOINDEX — see [sitemap.md](sitemap.md#pages-left-out-noindex).

---

## Moving from MageOS_MetaRobotsTag

`MageOS_MetaRobotsTag`, which ships in the Mage-OS distribution, stores three flags: **No Index**,
**No Follow** and **No Archive**. Products and categories have them per store view; a CMS page has
one set for all its store views. That module applies them by turning tokens of the store view's
**Design → Search Engine Robots** value into their restrictive form. This module stores a whole
directive instead, so the import turns each entity's flags into the directive its visitors were
served:

- **Per store view.** An entity's flags are read on each store view it is on (a product's
  websites, a category's root category, a CMS page's store views), as that store view reads them:
  its own value, or the default one. They are resolved against that store view's own **Search
  Engine Robots** value, so a staging store view whose default is `NOINDEX,NOFOLLOW` gets its own
  directive.
- **One setting where possible.** When every one of those store views was served the same
  directive, the entity gets one setting for all store views. Otherwise it gets one for each store
  view that had a flag; a store view without one keeps following the store setting, as it did.
- **Never over a value set here.** A setting the entity already has in this module, for the store
  view or for all store views, is kept, and reported with both directives. A category's ancestors
  do not count: the flags were never inherited.
- **Only flagged entities.** An entity with no flag is left following the store setting.

A CMS page's edit form shows only its setting for all store views
([above](#cms-page-level-override)). The import gives a page settings per store view only when its
store views were served different directives, and reports each page it does that for.

### When it runs

`setup:upgrade` tries the import once, and never fails because of it. If the import fails, the
failure is logged, and the admin shows a System Message until the settings have been carried over.
Run the import, or run it again, with:

```bash
bin/magento seo:import-legacy --dry-run   # what would be written, and the values that would be kept
bin/magento seo:import-legacy
```

The command also runs the import from `MageOS_Hreflang`
([hreflang.md](hreflang.md#moving-from-mageos_hreflang)). Running it again is safe: it fills only
what is still empty. So it also carries over flags set in `MageOS_MetaRobotsTag` since, and, while
the flags are still in the database, re-imports one wherever this module's setting has since been
cleared.

Whether an import finished is recorded in the `mageos_seo_legacy_import` flag, not in the patch
history: core records a data patch as applied even when the import inside it failed. What the
import wrote, and every value it kept, is written to `var/log/system.log`, prefixed
`MageOS_Seo: MageOS_MetaRobotsTag import`.

### Switching it off

`MageOS_MetaRobotsTag` has no setting that switches it off. While it is enabled, it keeps applying
its flags on top of this module's settings, since
[other modules' restrictions are kept](#other-modules-restrictions-are-kept): a **No Index** left
ticked there keeps a page `NOINDEX` whatever is set here. Once the import has finished, disable it,
and deploy as for any module change:

```bash
bin/magento module:disable MageOS_MetaRobotsTag
bin/magento setup:upgrade
```

Until then, the admin shows a System Message advising it. Its attributes and `cms_page` columns stay
in the database, with the flags in them.
