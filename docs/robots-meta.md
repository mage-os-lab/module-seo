# Robots Meta

The module controls the `<meta name="robots">` tag on product, category and CMS pages. It supports a global default per page type, category-level overrides, product-level overrides and CMS-page-level overrides.

---

## Global defaults

**Stores → Configuration → MageOS → SEO → Robots Meta Defaults**

| Setting | Default | Description |
|---|---|---|
| Product pages | *(empty — use Magento default)* | Applies to all product pages without a specific override |
| Category pages | *(empty — use Magento default)* | Applies to all category pages without a specific override |
| CMS pages | *(empty — use Magento default)* | Applies to all CMS pages, the home page included, without a specific override |

The defaults ship empty ("Use Magento Default"): until you configure a value, Magento core's
**Design → Search Engine Robots** setting stays in charge, so installing the module never
re-opens a NOINDEXed environment. These are per-store-view settings — you can set a stricter
default (e.g. `NOINDEX,FOLLOW`) on a specific store view while keeping another value globally.

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

In the category edit form, the **SEO (Structured Data)** tab includes a **Robots Meta** dropdown. Setting a value here overrides the global default for all pages in that category.

This is a per-store-view setting — open the category in the context of a specific store view to set a store-specific override.

Common use cases:
- Set `NOINDEX,FOLLOW` on internal/sorting categories you don't want indexed.
- Set `NOINDEX,NOFOLLOW` on a staging or preview category.

Leave the dropdown at **Use Global Default** to inherit the store's global setting.

---

## Product-level override

In the product edit form, the **Advanced SEO** tab includes a **Robots Meta** dropdown. This overrides the global and category defaults for that specific product and store view.

The product override is stored per store view (store_id), with `store_id = 0` acting as an all-stores default. A store-specific row takes precedence over the all-stores row.

Common use cases:
- `NOINDEX,FOLLOW` on discontinued products you keep live for existing links.
- `NOINDEX,NOFOLLOW` on draft products visible in the store but not ready for indexing.

---

## CMS-page-level override

In the CMS page edit form, the core **Search Engine Optimization** section includes a **Robots
Meta** dropdown. It overrides the store's **CMS Pages** default for that one page. Leave it on
**Use Magento Default** to follow the store setting.

The value is stored in `mageos_seo_cms_page_config`, not on `cms_page`, and applies wherever the
page is served. There is no per-store-view value to set from the admin: the CMS page form has no
store switcher, and a page's store views are chosen by its **Store View** assignment — to give two
store views different directives, give them different pages.

The table does carry a `store_id`, and a store view's row wins over the global (`store_id = 0`)
row when both exist. The admin form only ever reads and writes the global row; store-view rows are
for integrations writing through `MageOS\Seo\Model\Cms\ConfigRepository` directly.

Common use cases:
- `NOINDEX,FOLLOW` on thin utility pages (a no-results page, a campaign landing page after the
  campaign).
- `INDEX,FOLLOW,noarchive` on pages whose content changes often enough that a cached copy
  misleads.

Coming from `MageOS_MetaRobotsTag`: its per-page `no_index` / `no_follow` / `no_archive` flags are
converted into this override on `setup:upgrade`, resolved against the global **Design → Search
Engine Robots** value that module was modifying. Its columns on `cms_page` are left in place.

---

## Resolution order

The most specific setting wins:

```
Global default (system config)
    ↑ overridden by
Category override (mageos_seo_category_config.robots_meta)
    ↑ overridden by
Product override (mageos_seo_product_override.robots_meta, for that store view)
```

CMS pages form no tree, so they have one level:

```
CMS Pages default (system config)
    ↑ overridden by
CMS page override (mageos_seo_cms_page_config.robots_meta)
```

If no override is set at any level, the global default is used. If the global default is empty, no robots meta tag is output and the browser defaults to `index,follow`.
