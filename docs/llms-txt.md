# AI Discoverability (llms.txt)

The module serves two plain-text documents at well-known URLs so LLM crawlers and AI agents can understand the site without full crawl cycles. This follows the emerging `llms.txt` convention.

---

## The two documents

| URL | Content | Config toggle |
|---|---|---|
| `/llms.txt` | Concise: org name, description, base URL, locale, available schema types, the first 5 FAQs, AI contact email | Stores → Configuration → MageOS SEO → AI Information & Crawlers → Enable /llms.txt |
| `/llms-full.txt` | Extended: everything in the concise version plus social profiles, full category tree with product counts, full template list, every FAQ | Stores → Configuration → MageOS SEO → AI Information & Crawlers → Enable /llms-full.txt |

Both return `404` when their respective config toggle is off.

Both config toggles are per-store-view settings.

A third document, `/llms.jsonl`, carries one compact JSON-LD `Product` node per line for the
store's catalogue. It is **off by default** — turn it on per store view with
`mageos_aeo/llms_txt/jsonl_enabled`.

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

## Frequently Asked Questions

**Do you ship worldwide?**
Yes, to every country in the EU and the UK.

## AI Contact
ai@example.com
```

The `> Locale:` line is left out when the store view has no locale configured, the FAQ section when
the selected groups have no questions, and the AI contact when there is none (see
[Data sources](#data-sources)).

---

## Content of /llms-full.txt

Everything in `/llms.txt`, plus:

- Social profile URLs (from Organisation → Social profiles)
- Every FAQ of the selected groups, not just the first 5
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

## Clean URLs

No setup is required: a custom router serves `/llms.txt`, `/llms-full.txt` and
`/llms.jsonl` directly. Do **not** add manual URL rewrites for these paths — the
internal controller URLs (`/mageos-aeo/...`) 301-redirect to the canonical paths,
so a rewrite would fight the router.

---

## Generation & cache

These documents are pre-generated to files and served from there; a web request never builds
one. That machinery is documented once, in
**[feeds.md](feeds.md)**: the queue consumer and the nightly cron, what triggers a rebuild, the
24-hour cache policy, where the files are stored (including multi-server deployments and their
`app/etc/env.php` entry), file permissions, and the `seo:rebuild` command.

Worth knowing here: a rebuild of `/llms.txt` and `/llms-full.txt` is queued when the Organisation
settings or a FAQ change, when a category changes, and when a product is created, deleted or has
its category or website assignments changed — because the category tree carries product counts.
Product edits that change nothing in these documents do not queue one. A configuration change
queues one when it changes something the documents show: the locale, the Customer Support email,
these documents' own settings (including FAQ Groups), the base URLs (`web/`) or the category URL
suffix (`catalog/seo/`).

---

## Data sources

Both documents draw data from:

| Data | Source |
|---|---|
| Organisation name, description, URL, social profiles | Organisation record (store-scoped, same fallback as JSON-LD) |
| Locale | The store view's **General → Locale Options → Locale** (`general/locale/code`) |
| Schema template list | `SchemaBuilderPool::getAvailableTemplates()` |
| Category tree | Live `catalog_category_entity` collection, active categories only, level > 1 |
| FAQs | The groups selected under **FAQ Groups** (see [FAQ section](#faq-section)) |
| AI contact email | See [AI contact](#ai-contact) |

### AI contact

The address published for automated queries is the first of:

1. the Organisation's **Contact Email** (Marketing → SEO → Organisation), the same address the
   `Organization` JSON-LD publishes as its `contactPoint`;
2. the store's **Customer Support** email (Stores → Configuration → General → Store Email
   Addresses), **unless it is still the value Magento ships**. Every installation starts with
   `support@example.com` there, and publishing that placeholder would tell agents to write to an
   address nobody reads. The shipped value is read from the installed modules' `config.xml`
   defaults, so a distribution that ships a different placeholder is recognised too;
3. none: the `## AI Contact` section is left out.

`MageOS\Seo\Model\Organization\ContactEmail` makes this choice for both documents. To publish a
different address, set the Organisation's Contact Email.

### FAQ section

The FAQs come from the groups selected under **Stores → Configuration → MageOS SEO → AI
Information & Crawlers → AI Discoverability (llms.txt) → FAQ Groups**, per store view:

- the default is `global`, so a group of that name is included without configuring anything;
- the list offers every group identifier in use, plus `global`. That is every group any FAQ source
  has: the FAQs under Marketing → SEO → FAQ Manager, and those of any other module that registers a
  `FaqSourceProviderInterface` (its `getIdentifiers()`);
- groups are read in the order the setting stores them — the list's alphabetical order when saved
  from the admin, the given order with `bin/magento config:set` — and each group's questions in their
  own sort order;
- `/llms.txt` carries the first 5 questions across all selected groups, `/llms-full.txt` all of them;
- select none to leave FAQs out; a group with no active questions for the store view adds nothing.

Why not every group: a group is usually placed on one page through the FAQ widget or Page Builder —
a product's sizing questions, a returns page — and llms.txt is a summary of the whole site. Pick the
groups that answer site-wide questions.

To build the section some other way, replace the `faq` section provider in your module's `di.xml`
with your own `LlmsTxtSectionProviderInterface` implementation (see below):

```xml
<type name="MageOS\Seo\Model\LlmsTxt\LlmsTxtBuilder">
    <arguments>
        <argument name="sectionProviders" xsi:type="array">
            <item name="faq" xsi:type="object">MyModule\Model\LlmsTxt\FaqSectionProvider</item>
        </argument>
    </arguments>
</type>
```

---

## Adding content from a bridge module

Register a `LlmsTxtSectionProviderInterface` implementation in your bridge module's `di.xml`:

```php
// MyModule/Model/LlmsTxt/MySectionProvider.php
class MySectionProvider implements \MageOS\Seo\Api\LlmsTxtSectionProviderInterface
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
