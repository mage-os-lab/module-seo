# Organisation Settings

The Organisation record provides the site identity data used in the `Organization` and `WebSite` JSON-LD nodes and, when [MageOS_Aeo](https://github.com/mage-os-lab/module-aeo) is installed, in the `/llms.txt` document. It must be configured before the site goes live — without it, those nodes will output empty values.

---

## Admin location

**Marketing → SEO → Organization**

The form supports standard Magento store-scope switching: select a website or store view from the scope selector at the top of the admin page to configure a scope-specific override, exactly as you would with system configuration fields.

---

## Fields

| Field | Purpose | Notes |
|---|---|---|
| Name | Organisation display name | Used in JSON-LD `name`, WebSite `name`, and `/llms.txt` header. Falls back to store name in `/llms.txt` if blank. |
| URL | Canonical site URL | Used as the `@id` anchor for all linked schema nodes (e.g. `https://example.com/#organization`). Include `https://`, no trailing slash. |
| Description | Short tagline | Shown in JSON-LD `description` and at the top of `/llms.txt`. |
| Organisation type | Schema.org `@type` | `Organization`, `Corporation`, `LocalBusiness`, `NGO`, `EducationalOrganization`, `GovernmentOrganization`. Most stores should use `Organization`; a business customers visit in person should use `LocalBusiness`. |
| Logo | Image for the Organization `logo` node | Each store view's design logo, or a custom upload. See [Logo options](#logo-options). |
| Logo width / height | Pixel dimensions of the logo | Both required for a valid Organization schema. They describe whichever logo is published, in either mode: where store views publish design logos of different sizes, they cannot match every one. |
| Social profiles | Social profile URLs | Added as the `sameAs` array. One URL per row. |
| Contact type | `contactType` for the ContactPoint node | e.g. `customer support`, `sales` |
| Contact email | `email` for the ContactPoint node | Also the AI contact in `/llms.txt` and `/llms-full.txt`. Left blank, those fall back to the store's Customer Support email unless it is still Magento's `support@example.com` — see [AI contact](https://github.com/mage-os-lab/module-aeo/blob/main/docs/llms-txt.md#ai-contact). |
| Available language | `availableLanguage` for the ContactPoint node | e.g. `English` |
| Local presence | Address, telephone, email, latitude/longitude, price range | See [Local presence](#local-presence). |

---

## Local presence

The **Local Presence** fields go on the `Organization` node only where schema.org allows them on
its type:

| Field | Organization, Corporation, NGO, … | LocalBusiness |
|---|---|---|
| Address (`address`, a PostalAddress) | ✓ | ✓ |
| Telephone, email | ✓ | ✓ |
| Latitude / longitude (`geo`) | — | ✓ |
| Price range (`priceRange`) | — | ✓ |

schema.org gives a plain Organization no `geo` (a Place property) and no `priceRange` (a
LocalBusiness property), and Google's guidance sends physical businesses to LocalBusiness. So
coordinates and a price range filled in for a non-local type are kept in the record but not
published.

**A LocalBusiness subtype set in code.** The admin offers `LocalBusiness`. A module that stores a
more specific subtype (`Store`, `Restaurant`, …) through `OrganizationInterface::setOrgType()`
adds it to the list of local business types from its own `di.xml`, so `geo` and `priceRange` apply
to it too:

```xml
<type name="MageOS\Seo\Model\StructuredData\Provider\OrganizationProvider">
    <arguments>
        <argument name="localBusinessTypes" xsi:type="array">
            <item name="Store" xsi:type="string">Store</item>
        </argument>
    </arguments>
</type>
```

---

## Multi-store scoping

Organisation settings are stored per scope in the `mageos_seo_organization` table, using the same `scope` / `scope_id` pattern as Magento system config:

| Scope | scope column | scope_id column |
|---|---|---|
| Global default | `default` | `0` |
| Website override | `websites` | website ID |
| Store-view override | `stores` | store view ID |

**Fallback chain (frontend):** When building JSON-LD or `/llms.txt`, the system looks for a store-view specific row first, then a website row, then the global default. The most specific scope wins.

**Deleting a scope:** because `scope_id` means a website ID in one row and a store view ID in
the next, the table carries no foreign key to clean up after itself — `core_config_data` has the
same problem. `MageOS\Seo\Observer\RemoveOrganizationOnScopeDelete` removes the records of a
store view, store group or website as it is deleted, inside the same transaction, which is also
the last moment a website still knows which store views were its own: core removes them with a
database-level cascade that dispatches no `store_delete` event.

**Admin behaviour:** Opening the Organisation form with a `?store=3` or `?website=2` URL parameter loads and saves the record for that scope. Without a scope parameter, the global default is loaded. The scope selector in the admin header generates these parameters automatically.

### Practical example

A multi-store setup with two stores (UK and US):

1. Configure the global default with common values (name, URL, org type).
2. Switch to the US store view and save a different URL and social profiles.
3. The US store uses its own record for JSON-LD; the UK store falls back to the global record.

---

## Logo options

**Logo Source** has two modes. The choice is stored, and the logo's URL is worked out for each
store view when a page is rendered (`Model\Organization\LogoUrl`):

- **Use Design Logo:** each store view publishes its own design logo, the **Logo Image** set under
  **Content → Design → Configuration**, in the store view's row, under **Header**.
  - It is read as the storefront header reads it: at website scope in single-store mode, and only
    when the file is in the media directory.
  - It is published under the store view's own media URL.
  - With no design logo set, no logo is published: the theme's placeholder logo is never
    published as the brand's.
  - A record at website or default scope serves every store view below it, each with its own
    logo.
  - A changed design logo is published without saving the Organisation again, once the page
    cache that the change invalidated is refreshed, as for the header logo itself.
- **Upload Custom SEO Logo:** an image uploaded here, stored under `media/mage-os/seo/logo/`.
  - Use this when the Organisation's logo should differ from the header's: a square icon for
    search results, say, against a wide banner in the header.
  - It is published under each store view's media URL, never the admin's, which may not be
    public.
  - The last upload is kept while the design logo is used, so switching back finds it.

Under **Logo Source**, the form says what the scope you are editing publishes with the design logo:
the store's own logo for a store, or that each store publishes its own for a website or the
default.

A record saved before the source was stored, whose logo is an absolute URL, keeps publishing that
URL until the record is saved with a new upload or with **Use Design Logo**.

### Which images are accepted

JPEG, PNG, GIF and WebP. An upload must be the type its name says, read from the file's bytes. It
must be complete: its structure is walked to the end its format defines (a JPEG's end-of-image
marker, a PNG's `IEND`, a GIF's trailer, a WebP's declared length), because decoders draw a JPEG,
or under GD a GIF, that is cut short. And it must decode:

- **JPEG, PNG and GIF** are decoded by Magento's image adapter, GD2 or ImageMagick
  (`dev/image/default_adapter`).
- **WebP** is decoded by ImageMagick when that is the adapter and it reads WebP, and otherwise by
  PHP's GD. Magento's GD2 adapter cannot open WebP. Where neither ImageMagick nor GD can, a WebP
  upload is refused with a message saying so.

The type is never taken from an adapter's answer, because ImageMagick decodes whatever a file's
content says it is, SVG and PDF included. So a Flash movie, a PDF, an SVG or any other file named as
one of the four is refused. SVG is not accepted under its own name either: it can carry scripts.

---

## Social profiles

Enter one URL per row. These appear as the `sameAs` array in the Organization JSON-LD node, which tells search engines and knowledge panels which external profiles belong to this organisation.

Common profiles to include:
- Facebook page
- Instagram profile
- Twitter / X profile
- LinkedIn company page
- YouTube channel
- Pinterest profile

### Which URLs are accepted

The Organisation URL and the social profiles are published into JSON-LD and Open Graph tags, where
they end up in `href`, `src` and `@id` positions. Only addresses a browser can safely follow are
stored (the logo is always an upload: see [Logo options](#logo-options)):

- `http://` and `https://`;
- a relative path;
- **not** `javascript:`, `data:`, `vbscript:`, `file:` or any other scheme;
- **not** `//host/path`, which adopts the page's scheme and points at another host;
- **not** anything containing a control character, which would break out of the attribute it is
  written into.

An Organisation URL that fails this is refused with a message, so nothing is silently changed. Invalid social profiles are dropped and named in the message, because the rest of the
record is still worth saving.

---

## What happens if Organisation is left blank

- The `OrganizationProvider` returns an empty schema array — no `Organization` or `WebSite` nodes are output in JSON-LD.
- `/llms.txt` uses the store name as the heading but omits the description and social profiles.
- Google Search Console and schema validators will not flag errors, but rich results that depend on the `Organization` node will not be eligible.

Configure Organisation before going live.
