# Per-Category SEO Configuration

Each category can have its own SEO settings that override the global defaults. These settings primarily control what structured data is output for products in that category.

---

## Where to find it

**Catalog → Categories → (open a category) → SEO (Structured Data) tab**

The tab is injected into the standard category edit form at the bottom of the tab list.

---

## Settings

### Schema Template

Which of the 16 product schema templates to use for products in this category. For example, assign `Apparel` to a clothing category so that Google can read size, color, and material from your products.

Leave blank to inherit from the nearest ancestor category that has a template set, or fall back to the global default template configured at **Stores → Configuration → MageOS → SEO → Default Product Schema Template**.

See [product-schema-templates.md](product-schema-templates.md) for the full template list and what each one outputs.

### Enabled Optional Fields

A multiselect of the optional schema fields available for the selected template. Only enabled fields are read from product attributes and included in the schema output. Fields left out are omitted entirely — Google marks thinly-populated fields as invalid, so it is better to enable fewer fields that are consistently populated than to enable many with gaps.

The available options change based on which template is selected. After changing the template, save the category and reopen it to see the updated field list.

### ItemList Schema on Category Pages

Controls the `ItemList` JSON-LD node that lists the products visible on the category page.

| Option | Behaviour |
|---|---|
| Use Global Setting | Inherits from **Stores → Configuration → MageOS → SEO → Enable Category ItemList Schema** |
| Yes — output ItemList schema | Forces ItemList on, even if the global setting is off |
| No — disable ItemList schema | Suppresses ItemList for this category only |

The ItemList is paginated — it reflects the products actually visible on the current page, not the full catalogue. See the note on pagination below.

### Robots Meta

Override the global robots meta default for all pages in this category. Leave at **Use Global Default** to inherit the store's setting.

See [robots-meta.md](robots-meta.md) for accepted values and full resolution order.

### Field Value Overrides (JSON)

A JSON object of hard-coded values that replace what would normally be read from product attributes. These overrides apply to all products in this category, on top of whatever the template builder reads from the product.

Example — force all products in a handmade jewelry category to use a specific material and condition:

```json
{
    "material": "Sterling Silver",
    "itemCondition": "https://schema.org/NewCondition"
}
```

Keys are schema property names. Values override the corresponding property in the final schema node. Useful when a product attribute is absent or inconsistently populated across your catalogue.

---

## Inheritance

Category SEO settings inherit from ancestor categories. If a category has no schema template set, the module walks up the category path and uses the nearest ancestor's template. The same walk applies to enabled fields and override values.

Example:
- "Makers" (level 2) → template: `GenericProduct`
- "Clothing" (level 3, child of Makers) → template: `Apparel`
- "Women's Dresses" (level 4, child of Clothing) → no template set

Products in "Women's Dresses" use the `Apparel` template inherited from "Clothing".

If no ancestor has a template configured, the global default template is used.

---

## Store-view scoping

Category SEO settings are stored per store view. Open the category in the context of a specific store view (using the scope selector in the admin header, or the `?store={id}` URL parameter) to save store-specific overrides.

Store-specific settings override the global (`store_id = 0`) settings for that store view. All other store views continue to use the global category settings.

---

## ItemList pagination

The ItemList schema reflects the current page of products, not the full category. Position numbers are offset correctly:

- Page 1, positions 1–36
- Page 2, positions 37–72
- etc.

This matches what Google actually crawls when it follows pagination links, so the schema is consistent with the visible content.

The maximum number of items per page is controlled by **Stores → Configuration → MageOS → SEO → Category ItemList Max Items** (default: 36). If the category has fewer products than the max, fewer items are output.

---

## Performance

Category config is loaded from the database on demand and cached in memory for the duration of the request. There is one DB query per unique `(category_id, store_id)` combination per request, at most. The ancestor walk may load additional category rows, but each row is cached after the first load.
