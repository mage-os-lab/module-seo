# Robots Meta

The module controls the `<meta name="robots">` tag on product and category pages. It supports a global default per page type, category-level overrides, and product-level overrides.

---

## Global defaults

**Stores → Configuration → MageOS → SEO → Robots Meta Defaults**

| Setting | Default | Description |
|---|---|---|
| Product pages | `INDEX,FOLLOW` | Applies to all product pages without a specific override |
| Category pages | `INDEX,FOLLOW` | Applies to all category pages without a specific override |

These are per-store-view settings. You can set a stricter default (e.g. `NOINDEX,FOLLOW`) on a specific store view while keeping `INDEX,FOLLOW` as the global default.

**Accepted values:** Any combination of `INDEX`, `NOINDEX`, `FOLLOW`, `NOFOLLOW` separated by a comma. Examples: `INDEX,FOLLOW` · `NOINDEX,FOLLOW` · `NOINDEX,NOFOLLOW`

---

## How the value is applied

The robots meta value is set on the `PageConfig` object rather than output directly by a block. This means it participates in Magento's standard `<head>` rendering, and only one robots meta tag ever appears on the page regardless of how many places try to set it.

Two plugins handle this:

- `CategoryRobotsMetaPlugin` — runs after `Magento\Catalog\Controller\Category\View::execute()`
- `ProductRobotsMetaPlugin` — runs after `Magento\Catalog\Controller\Product\View::execute()`

Each plugin checks for a category- or product-level override first, then falls back to the global default.

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
Category override (mage-os_seo_category_config.robots_meta)
    ↑ overridden by
Product override (mage-os_seo_product_override.robots_meta, for that store view)
```

If no override is set at any level, the global default is used. If the global default is empty, no robots meta tag is output and the browser defaults to `index,follow`.
