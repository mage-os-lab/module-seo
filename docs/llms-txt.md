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

## Setting up the clean URLs

The module's Magento routes respond at:
- `/llms/llms_txt/index`
- `/llms-full/llms_full/index`

To serve them at the expected `/llms.txt` and `/llms-full.txt` paths, add two URL rewrites in **Marketing → URL Rewrites**:

| Request path | Target path | Redirect type |
|---|---|---|
| `llms.txt` | `llms/llms_txt/index` | No (custom) |
| `llms-full.txt` | `llms-full/llms_full/index` | No (custom) |

Set **Redirect Type** to **No** (not a 301/302) so the content is served, not redirected.

---

## Cache

Both documents are served with `Cache-Control: public, max-age=3600`. Varnish and the FPC cache them for one hour.

Cache is invalidated automatically when:
- A category is saved (`catalog_category_save_after` event → `InvalidateLlmsTxtCache` observer)
The cache tag `MAGEOS_SEO_LLMS` is used for `/llms.txt` and `MAGEOS_SEO_LLMS_FULL` for `/llms-full.txt`.

To manually flush: flush the full page cache (`bin/magento cache:flush full_page`), or purge the specific URLs via your CDN or Varnish admin.

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
