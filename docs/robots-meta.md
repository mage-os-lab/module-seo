# Robots Meta

The module controls the `<meta name="robots">` tag on product and category pages. It supports a global default per page type, category-level overrides, and product-level overrides.

---

## Global defaults

**Stores → Configuration → MageOS → SEO → Robots Meta Defaults**

| Setting | Default | Description |
|---|---|---|
| Product pages | *(empty — use Magento default)* | Applies to all product pages without a specific override |
| Category pages | *(empty — use Magento default)* | Applies to all category pages without a specific override |

The defaults ship empty ("Use Magento Default"): until you configure a value, Magento core's
**Design → Search Engine Robots** setting stays in charge, so installing the module never
re-opens a NOINDEXed environment. These are per-store-view settings — you can set a stricter
default (e.g. `NOINDEX,FOLLOW`) on a specific store view while keeping another value globally.

**Accepted values:** Any combination of `INDEX`, `NOINDEX`, `FOLLOW`, `NOFOLLOW` separated by a comma. Examples: `INDEX,FOLLOW` · `NOINDEX,FOLLOW` · `NOINDEX,NOFOLLOW`

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

## Resolution order

The most specific setting wins:

```
Global default (system config)
    ↑ overridden by
Category override (mageos_seo_category_config.robots_meta)
    ↑ overridden by
Product override (mageos_seo_product_override.robots_meta, for that store view)
```

If no override is set at any level, the global default is used. If the global default is empty, no robots meta tag is output and the browser defaults to `index,follow`.
