# Per-Product SEO Configuration

Individual products can have their own SEO overrides on top of the category and global settings. These are set directly in the product edit form.

---

## Where to find it

**Catalog → Products → (open a product) → Advanced SEO tab**

The tab is injected at the bottom of the product edit form (sortOrder 500, after the standard Magento tabs).

---

## Settings

### Field Value Overrides (JSON)

A JSON object of schema property values that override what the template builder reads from product attributes and category settings.

Format: keys are schema.org property names, values are the hard-coded output values.

```json
{
    "color": "Midnight Blue",
    "material": "100% Organic Cotton",
    "brand": "Makers Workshop"
}
```

This is the innermost layer — product overrides win over category overrides, which win over template defaults. Use this to correct individual products where the attribute data does not match what you want in the schema.

The available properties depend on the template assigned to the product's category. Any key present in the override JSON is applied regardless of whether the field is listed in the category's enabled fields.

### Robots Meta

Override the robots meta directive for this specific product. Leave blank to inherit from the category override or global default.

Accepted values: `INDEX,FOLLOW` · `NOINDEX,FOLLOW` · `NOINDEX,NOFOLLOW` · `INDEX,NOFOLLOW`

See [robots-meta.md](robots-meta.md) for the full resolution order.

---

## Store-view scoping

Product overrides are stored per store view in `mage-os_seo_product_override`. The `store_id` column works the same way as elsewhere in the module:

- `store_id = 0` → applies to all store views (all-stores default)
- `store_id = N` → applies to that specific store view only, overriding the all-stores value

When the product is saved, the store-specific row is written for whichever store view the admin is currently editing. Save the product at global scope (`store_id = 0`) to set values that apply everywhere, then switch to a specific store view to add a store-specific override.

---

## How overrides are merged

The full merge order for a product schema field:

1. Product override for the specific store view (wins if present)
2. Product override for all stores (`store_id = 0`)
3. Category override JSON
4. Template builder output from product attributes
5. Field omitted

---

## Robots override vs. category robots override

The product-level robots override is the most specific setting. If you set `NOINDEX,FOLLOW` at the category level (to noindex a whole category) but want one specific product to be indexed, you can override it back to `INDEX,FOLLOW` on that product.

---

## Bulk management

There is no bulk edit grid for product overrides in this version. Overrides are managed individually through the product edit form. If you need to apply the same override to many products, use the category override (JSON field) instead, which applies to all products in the category.
