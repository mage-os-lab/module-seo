# Product Schema Templates

Product structured data is built by template — each template maps to a schema.org type and exposes a set of optional fields tailored to that product category. Templates are assigned per category in the admin.

---

## The 16 built-in templates

| Template code | Admin label | schema.org @type | Typical use |
|---|---|---|---|
| `GenericProduct` | Generic Product | Product | Default fallback for unconfigured categories |
| `Food` | Food & Grocery | FoodProduct | Food, drinks, condiments |
| `Apparel` | Clothing & Apparel | Apparel | Clothing, shoes, accessories |
| `Jewelry` | Jewelry | Product | Rings, necklaces, bracelets |
| `HomeDecor` | Home Decor & Furniture | Product | Prints, cushions, ceramics, furniture |
| `Book` | Books | Book | Physical and digital books |
| `Software` | Software & Apps | SoftwareApplication | Digital products, apps |
| `Toy` | Toys & Games | Product | Children's toys, games, puzzles |
| `HealthProduct` | Health & Wellness | Product | Supplements, wellness products |
| `Cosmetics` | Beauty & Cosmetics | Product | Skincare, makeup, perfume |
| `Pet` | Pet Supplies | Product | Food, accessories, toys for pets |
| `ArtAndCraft` | Art & Craft | VisualArtwork | Original art, prints, craft supplies |
| `ElectronicsSimple` | Electronics | Product | Simple electronic products |
| `Tool` | Tools & Hardware | Product | Hand tools, hardware, workshop supplies |
| `Stationery` | Stationery & Office | Product | Notebooks, pens, office supplies |
| `LocalExperience` | Local Experience | Product | Workshops, events, experiences |

---

## What every template includes (the base node)

Every template builds on `AbstractBuilder::buildBase()`, which always outputs:

- `@context`, `@type`, `@id` (product URL + `#product`)
- `name`, `url`, `sku`
- `description` (from short description, falling back to full description; HTML stripped)
- `image` (up to 5 images from the media gallery)
- `offers` containing:
  - `price`, `priceCurrency`
  - `availability` (InStock / OutOfStock from the stock registry)
  - `itemCondition` (NewCondition)
  - `priceValidUntil` (N months from today, configured at Stores → Configuration → SEO)
  - `url` (variant-specific URL when a product variant URL is active)

Template-specific fields are layered on top of this base.

---

## Optional fields

Each template exposes a set of optional fields that can be enabled per category. When a field is enabled, the builder reads its value from the corresponding Magento product attribute (or variant data). When a field is not enabled, it is omitted from the schema entirely — Google penalises poorly-populated fields, so it is better to omit than to output empty values.

Examples by template:

| Template | Optional fields available |
|---|---|
| `Apparel` | color, size, material, gender, pattern |
| `Food` | nutritionInformation, containsAllergen, isAlcoholicBeverage, countryOfOrigin |
| `Book` | isbn, author, publisher, bookEdition, bookFormat, numberOfPages, inLanguage |
| `ArtAndCraft` | artMedium, artworkSurface, creator, dimensions, isBasedOn |

To enable optional fields for a category, open the category in admin, go to the **SEO (Structured Data)** tab, and select the fields you want to output in the **Enabled Optional Fields** multiselect.

---

## Field value resolution

When a builder reads a field, it follows this priority order:

1. **Override value** — a hard-coded value set in the category or product override JSON (wins over everything)
2. **Variant data** — attribute values for the active product variant (`color`, `size`)
3. **Product attribute** — the product's actual Magento attribute value
4. **Omit** — if none of the above yield a non-empty value, the field is not output

For `select` / `dropdown` attributes, the builder resolves the label text rather than the option ID, which is correct for schema.org text fields.

---

## Setting the default template

When a product's category has no template configured, the **Default Product Schema Template** setting is used. Default: `GenericProduct`.

Change it at: **Stores → Configuration → MageOS → SEO → Structured Data → Default Product Schema Template**

This is a per-store-view setting.

---

## Template inheritance

Template assignment is category-level and supports inheritance. If a category has no template configured, the builder walks up the category path and inherits the template from the nearest ancestor that has one configured.

Example: if "Clothing" has `Apparel` set and "Women's T-shirts" (a child) has nothing set, products in "Women's T-shirts" will use `Apparel`.

Enabled fields and override values are also inherited using the same ancestor-walk logic.

---

## Adding a new template

See [extending.md](extending.md) for the step-by-step guide to registering a new product schema builder.
