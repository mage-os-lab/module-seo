# Extending the Module

All major composition points are exposed as injectable arrays in `di.xml`. Bridge modules add their own providers by registering them in their own `di.xml` — the Seo module's files are never modified.

---

## Extension points summary

| What you want to add | Interface / pool | di.xml target |
|---|---|---|
| New JSON-LD schema on any page | `StructuredDataProviderInterface` | `Model\StructuredData\Compositor` → `providers` array |
| New OG / meta tags on any page | `MetaTagProviderInterface` | `Model\MetaTag\Compositor` → `providers` array |
| Custom page `<title>` provider | `PageTitleProviderInterface` | `Model\PageTitle\Compositor` → `providers` array |
| New product schema template | `ProductSchemaBuilderInterface` | `Model\Product\SchemaBuilderPool` → `builders` array |
| Extra content in `/llms.txt` | `SectionProviderInterface` | `Model\LlmsTxt\LlmsTxtBuilder` → `sectionProviders` array |

---

## Adding a structured data provider

Use this when you need to output a new JSON-LD node on specific pages — for example, a vendor `LocalBusiness` schema on the vendor profile page.

**1. Implement the interface**

```php
// MyModule/Model/StructuredData/MyProvider.php
namespace MyModule\Model\StructuredData;

use MageOS\Seo\Api\StructuredDataProviderInterface;

class MyProvider implements StructuredDataProviderInterface
{
    public function getHandles(): array
    {
        return ['my_layout_handle'];  // or ['*'] for every page
    }

    public function getSchemas(): array
    {
        return [
            [
                '@context' => 'https://schema.org',
                '@type'    => 'LocalBusiness',
                'name'     => 'Example',
            ],
        ];
    }
}
```

**2. Register in di.xml**

```xml
<!-- MyModule/etc/di.xml -->
<type name="MageOS\Seo\Model\StructuredData\Compositor">
    <arguments>
        <argument name="providers" xsi:type="array">
            <item name="myProvider" xsi:type="object">
                MyModule\Model\StructuredData\MyProvider
            </item>
        </argument>
    </arguments>
</type>
```

Return `[]` from `getSchemas()` to contribute nothing (e.g. when required data is missing).

---

## Adding a meta tag provider

Same pattern, different interface and pool:

```php
class MyMetaProvider implements \MageOS\Seo\Api\MetaTagProviderInterface
{
    public function getHandles(): array { return ['my_layout_handle']; }

    public function getMetaTags(): array
    {
        return [
            ['name' => 'twitter:card', 'content' => 'summary_large_image'],
            ['property' => 'og:site_name', 'content' => 'My Store'],
        ];
    }
}
```

Use `'property'` key for `og:` and `'name'` key for `name=` tags. Both are output as `<meta property="..." content="...">` or `<meta name="..." content="...">` accordingly.

Register against `MageOS\Seo\Model\MetaTag\Compositor` → `providers`.

---

## Adding a page title provider

```php
class MyTitleProvider implements \MageOS\Seo\Api\PageTitleProviderInterface
{
    public function getHandles(): array { return ['my_layout_handle']; }
    public function getSortOrder(): int { return 200; }  // higher wins; built-ins use 100
    public function getTitle(): string { return 'My Custom Title'; }
}
```

The compositor sorts all matching providers by `getSortOrder()` descending and uses the first non-empty string. Use `getSortOrder() > 100` only if you need to override the built-in product/category title.

Register against `MageOS\Seo\Model\PageTitle\Compositor` → `providers`.

---

## Adding a product schema template

Use this when you need a new schema type not covered by the 16 built-ins (e.g. a `Vehicle` template for a car-parts store).

**1. Extend AbstractBuilder**

```php
// MageOS/Seo/Model/Product/Builder/VehicleBuilder.php
namespace MageOS\Seo\Model\Product\Builder;

use Magento\Catalog\Api\Data\ProductInterface;

class VehicleBuilder extends AbstractBuilder
{
    public function getTemplateCode(): string { return 'Vehicle'; }
    public function getLabel(): string { return 'Vehicle'; }

    public function getAvailableFields(): array
    {
        return [
            'vehicleModelDate' => 'Model Year',
            'driveWheelConfiguration' => 'Drive Configuration',
            'fuelType' => 'Fuel Type',
        ];
    }

    public function build(
        ProductInterface $product,
        array $enabledFields,
        array $overrides,
        array $variantData
    ): array {
        $schema = $this->buildBase($product, $variantData);

        if (\in_array('vehicleModelDate', $enabledFields)) {
            $year = $this->attr($product, 'model_year');
            if ($year !== '') {
                $schema['vehicleModelDate'] = $year;
            }
        }

        // ... add other fields

        return $this->applyOverrides($schema, $overrides);
    }

    protected function getSchemaType(): string { return 'Vehicle'; }
}
```

Rules to follow:
- Always call `$this->buildBase()` first — it provides the base product node with offers, images, and description.
- Check `\in_array($fieldCode, $enabledFields)` before reading optional attributes.
- Always call `$this->applyOverrides($schema, $overrides)` as the last step — it ensures category and product overrides win over template defaults.
- Use `$this->attr($product, 'attribute_code')` to read product attributes — it handles select/dropdown label resolution automatically.

**2. Register in di.xml**

```xml
<!-- etc/di.xml (or bridge module's di.xml) -->
<type name="MageOS\Seo\Model\Product\SchemaBuilderPool">
    <arguments>
        <argument name="builders" xsi:type="array">
            <item name="Vehicle" xsi:type="object">
                MageOS\Seo\Model\Product\Builder\VehicleBuilder
            </item>
        </argument>
    </arguments>
</type>
```

The key (`Vehicle`) must match the string returned by `getTemplateCode()`. The template will appear automatically in the category SEO tab dropdown.

---

## Adding llms.txt content

See [llms-txt.md](llms-txt.md#adding-content-from-a-bridge-module) for the full example with code and di.xml registration.

---

## Handle matching

All providers declare handles via `getHandles()`. The compositor checks these against the current page's active layout handles using `in_array()`. Some commonly used handles:

| Handle | Page |
|---|---|
| `*` | Every page |
| `catalog_product_view` | Product detail page |
| `catalog_category_view` | Category page |
| `cms_page_view` | CMS pages |
| `cms_index_index` | Home page |
| `makers_profile_view` | Vendor profile page (Sellers module) |
| `makers_index_index` | Vendor directory page (Sellers module) |

You can return multiple handles from `getHandles()` — the provider runs if any of them match.

---

## Cache considerations

Providers registered via `di.xml` are part of the same fully-cacheable request as the built-in providers. Your provider must not introduce any output that varies per-customer or session — use customer data sections for personalised content, never inside a provider. The `cacheable="false"` attribute must never be added to the `Block\JsonLd` or `Block\MetaTags` blocks, even indirectly.
