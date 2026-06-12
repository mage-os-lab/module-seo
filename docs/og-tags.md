# Open Graph Tags

Open Graph (OG) meta tags tell social networks and link-preview services how to display your pages when shared. The module outputs OG tags on product pages, category pages, and CMS pages.

---

## Enable / disable

**Stores → Configuration → MageOS → SEO → Open Graph Tags → Enable OG Tags**

Default: enabled. This is a per-store-view setting.

When disabled, no `og:` or `product:` meta tags are output anywhere on the site.

---

## What gets output

### Product pages

| Tag | Value |
|---|---|
| `og:type` | `product` |
| `og:title` | Product name |
| `og:url` | Product canonical URL |
| `og:description` | Short description (HTML stripped, truncated to 300 characters) |
| `og:image` | First product image URL |
| `product:price:amount` | Final price formatted to 2 decimal places |
| `product:price:currency` | ISO currency code (e.g. `GBP`) |
| `product:availability` | `instock` or `out of stock` |

### Category pages

| Tag | Value |
|---|---|
| `og:type` | `website` |
| `og:title` | Category name |
| `og:url` | Category URL |
| `og:description` | Category description (HTML stripped, truncated to 300 characters) |
| `og:image` | Category image URL (omitted if no image is set) |

### CMS pages

| Tag | Value |
|---|---|
| `og:type` | `website` |
| `og:title` | CMS page title |
| `og:url` | CMS page canonical URL |
| `og:description` | CMS page meta description |

---

## How OG tags are output

OG tags are rendered by the `Block\MetaTags` block, which is injected into the `<head>` via `default.xml`. The block calls the **MetaTag Compositor**, which collects tags from all registered `MetaTagProviderInterface` providers and outputs them.

Each provider declares which layout handles it applies to — the compositor only calls providers whose handles match the current page.

---

## Caching

Meta tag output is fully FPC-cacheable. The `Block\MetaTags` block does not use `cacheable="false"`. Each unique URL gets its own cache entry, so different products and categories have independent cached responses.

---

## Twitter / X cards

The module does not output `twitter:card` tags. If Twitter card markup is needed, it can be added by registering a new `MetaTagProviderInterface` provider in a bridge module. The provider would return tags like:

```php
return [
    ['name' => 'twitter:card', 'content' => 'summary_large_image'],
    ['name' => 'twitter:title', 'content' => $product->getName()],
];
```
