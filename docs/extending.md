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
| Your own pre-generated output, rebuilt on change | `Api\Rebuild\GroupHandlerInterface` | `Model\Rebuild\HandlerPool` → `handlers` array |
| Its label and retry time in the admin's rebuild message | `Api\Rebuild\GroupDescriptionInterface` | Implemented by the same handler |

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

You don't need to add X card tags yourself: after collecting every provider's tags, the compositor adds `twitter:card` (by whether the page has an `og:image`) and repeats `og:title`, `og:description` and `og:image` as `twitter:*`. A `twitter:*` tag your provider returns, like the card above, is kept rather than replaced. See [og-tags.md](og-tags.md#twitter--x-cards).

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
    public function getLabel(): string { return (string) __('Vehicle'); }

    public function getAvailableFields(): array
    {
        return [
            'vehicleModelDate' => (string) __('Model Year'),
            'driveWheelConfiguration' => (string) __('Drive Configuration'),
            'fuelType' => (string) __('Fuel Type'),
        ];
    }

    public function build(ProductInterface $product, array $enabledFields, array $overrides): array
    {
        $schema = $this->buildBase($product);

        if (\in_array('vehicleModelDate', $enabledFields)) {
            // An override for one of your own fields is yours to apply, in your field's shape.
            $year = $overrides['vehicleModelDate'] ?? $this->attr($product, 'model_year');
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
- Always call `$this->buildBase()` first — it provides the base product node with offers, images, and description. The offer comes from `Model\Product\OfferBuilder`, the one place offers are built; to change every offer, register an `OfferEnricherInterface` or plug in to `OfferBuilder::build()` rather than editing the node here.
- **The node may have no `offers`.** When the product's price is not known, `OfferBuilder::build()` returns an empty array and `buildBase()` leaves `offers` out (see [structured-data.md](structured-data.md#a-product-whose-price-is-not-known-has-no-offer)). Check `isset($schema['offers'])` before writing into it, as `LocalExperienceBuilder` does. A plugin on `build()` receives the empty array too. `SchemaRegistry::mergeNested('offers', …)` would create an offer with no price on such a product, so check that the stored schema has one first.
- Don't handle configurable products yourself: after your builder runs, `Model\Product\Variant\ProductGroupBuilder` turns a configurable's node into a ProductGroup of its variants, for every template (see [structured-data.md](structured-data.md#configurable-products)).
- If your builder declares its own constructor, pass AbstractBuilder's arguments through: `StoreManagerInterface`, `ImageHelper`, `Config`, `OfferBuilder`, `AggregateRatingResolver`, `GtinValidator`.
- Check `\in_array($fieldCode, $enabledFields)` before reading optional attributes.
- **Read `$overrides[$field]` before the attribute for each of your own fields** (the keys of `getAvailableFields()`), and build the field in its proper shape — a `Brand` node, an `additionalProperty` entry, whatever your field is. An override for one of your fields turns that field on (`SchemaBuilderPool` adds it to `$enabledFields`), and `applyOverrides()` leaves your fields to you: setting the raw value there would replace the node you built with a string.
- Always call `$this->applyOverrides($schema, $overrides)` as the last step — it sets the override keys your template does **not** list, as given, so a merchant can still set a schema.org property you don't know.
- Use `$this->attr($product, 'attribute_code')` to read product attributes — it handles select/dropdown label resolution automatically.
- Return `getLabel()` and the labels in `getAvailableFields()` through `(string) __('…')`, and add the phrases to your module's `i18n/` files. They're shown in the admin's language in the category form, and the template label in the store view's language in MageOS_Aeo's `/llms-full.txt` (see [Translations](translations.md)).

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

`/llms.txt` and its section providers are MageOS_Aeo's. See its
[llms-txt.md](https://github.com/mage-os-lab/module-aeo/blob/main/docs/llms-txt.md#adding-content-from-a-bridge-module)
for the full example with code and di.xml registration.

---

## Rebuilding your own output through the queue

Output that is expensive to build, such as a feed file, should never be built during a web request
or on every save. This module's rebuild queue (topic `mageos.seo.feed.regenerate`, consumer
`mageosSeoFeedRegenerate`) already does this for the XML sitemaps and MageOS_Aeo's llms documents. A module
can hand it groups of its own. What a group gets:

- **Collapsing.** However many times a group is invalidated before the consumer runs, it is built
  once.
- **Nothing queued for nothing.** A group is queued only while your handler says some store view
  can build it.
- **No lost change.** A rebuild refused with `RebuildInProgressException`, because another process
  holds your lock, is queued again.
- **`bin/magento seo:rebuild`** lists your groups, rebuilds them with no `-g`, and
  rebuilds one with `-g <group>`.
- **Every `setup:upgrade`** queues your groups, so a fresh install builds them.

Implement the handler:

```php
use MageOS\Seo\Api\Rebuild\GroupHandlerInterface;

class BlogFeedHandler implements GroupHandlerInterface
{
    public function __construct(
        private readonly BlogFeedBuilder $builder,  // inject as a proxy in di.xml
        private readonly BlogConfig $config
    ) {
    }

    public function getGroups(): array
    {
        return ['blog-feed'];                       // unique; never `sitemap-…`
    }

    public function isEnabled(string $group): bool
    {
        return $this->config->isFeedEnabledInAnyStore();
    }

    public function rebuild(?string $group): array
    {
        return $this->builder->buildForEveryStore(); // store ID => error, for the failures
    }
}
```

Register it, with its builder behind a proxy:

```xml
<type name="MageOS\Seo\Model\Rebuild\HandlerPool">
    <arguments>
        <argument name="handlers" xsi:type="array">
            <item name="blog" xsi:type="object">Vendor\Blog\Model\BlogFeedHandler</item>
        </argument>
    </arguments>
</type>
<type name="Vendor\Blog\Model\BlogFeedHandler">
    <arguments>
        <argument name="builder" xsi:type="object">Vendor\Blog\Model\BlogFeedBuilder\Proxy</argument>
    </arguments>
</type>
```

The pool is built whenever a save is inspected, so a handler must be cheap to construct. The proxy
keeps your builder from loading until a rebuild actually runs.

Queue a rebuild from your own observer when your data changes:

```php
$this->invalidator->invalidate('blog-feed');  // MageOS\Seo\Model\Rebuild\Invalidator
```

A group no handler owns throws `\InvalidArgumentException`, which catches a misspelt name. So does
registering a group twice, or one named like a sitemap group: the pool throws `\LogicException`
the first time it is asked.

For a new **type of page in the XML sitemaps**, use `Api\Sitemap\RebuildRequesterInterface`
instead. See [sitemap.md](sitemap.md).

### Telling the admin about your files

When a rebuild fails, the admin shows it until a rebuild gets through (see
[rebuild-problems.md](rebuild-problems.md)). What your handler's `rebuild()` returns is recorded for
you, store ID => error, whenever the queue or `seo:rebuild -g` runs it. A clean result clears the
group's problems.

**Label your files and name the cron job that rebuilds them** by also implementing
`Api\Rebuild\GroupDescriptionInterface`. Without it, the admin is shown your group name and told
the files are retried when their content next changes.

```php
use Magento\Framework\Phrase;
use MageOS\Seo\Api\Rebuild\GroupDescriptionInterface;

class BlogFeedHandler implements GroupHandlerInterface, GroupDescriptionInterface
{
    // ...

    public function getLabel(string $group): Phrase
    {
        return __('blog-feed.xml');                  // what the admin calls the files
    }

    public function getScheduledJob(string $group): ?string
    {
        return 'vendor_blog_rebuild_feed';          // the job's name in your crontab.xml, or null
    }
}
```

The job's next run is shown as when a failure is retried, so the job must rebuild the group in a
way that is recorded too. A job that calls something other than your handler brackets the rebuild
itself, with `MageOS\Seo\Model\Rebuild\ProblemLog`:

```php
$this->problemLog->rebuilding('blog-feed');
try {
    $failures = $this->builder->buildForEveryStore();
} catch (\Throwable $e) {
    $this->problemLog->rebuilt('blog-feed', [ProblemLog::ALL => $e->getMessage()]);
    throw $e;
}
$this->problemLog->rebuilt('blog-feed', $failures);
```

Brackets nest: when the queue runs your handler and your handler brackets the group as well, only
the outer bracket records, with what both found.

**Report a file written with something missing** while the rebuild runs. The reason is a phrase,
so the admin reads it in their language:

```php
$this->problemLog->degraded('blog-feed', $storeId, __('The author lookup failed, so posts have no author.'));
```

Outside a rebuild, from a storefront request say, a report is ignored: log it as well.

---

## Repository API

Everything under `Api/` is marked `@api`: it is the module's contract, and Magento's
backward-compatibility promise covers it and nothing else. Code outside `Api/` can change in any
release.

The FAQ repository is MageOS_Faq's: its README covers listing FAQ entries, adding fields to an FAQ
and the exceptions it throws.

### Adding fields to the Organization

`OrganizationInterface` is extensible. Declare your field in your module's
`etc/extension_attributes.xml`, and it appears on every model through `getExtensionAttributes()`:

```xml
<config xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
        xsi:noNamespaceSchemaLocation="urn:magento:framework:Api/etc/extension_attributes.xsd">
    <extension_attributes for="MageOS\Seo\Api\Data\OrganizationInterface">
        <attribute code="founding_date" type="string"/>
    </extension_attributes>
</config>
```

```php
$organization->getExtensionAttributes()->getFoundingDate();
```

`getExtensionAttributes()` never returns null: the object is created on first read. Filling and
storing your field is your module's job, as with any extension attribute: a plugin on the
repository, for one.

### Errors

The repository throws Magento's standard exceptions, so a caller can tell a missing record from a
failed write:

| Method | Throws |
|---|---|
| `OrganizationRepositoryInterface::save()` | `CouldNotSaveException` |
| `OrganizationRepositoryInterface::deleteForScope()` | `CouldNotDeleteException` |

The database's own error is kept as the exception's `getPrevious()`.

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

Bridge modules use their own handles (e.g. a marketplace module's vendor profile page) both for
matching in `getHandles()` and for excluding pages from built-in providers such as
`BreadcrumbListProvider` (`excludedHandles` DI argument).

You can return multiple handles from `getHandles()` — the provider runs if any of them match.

---

## Cache considerations

Providers registered via `di.xml` are part of the same fully-cacheable request as the built-in providers. Your provider must not introduce any output that varies per-customer or session — use customer data sections for personalised content, never inside a provider. The `cacheable="false"` attribute must never be added to the `Block\JsonLd` or `Block\MetaTags` blocks, even indirectly.
