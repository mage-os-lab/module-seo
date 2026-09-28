# Organisation Settings

The Organisation record provides the site identity data used in the `Organization` and `WebSite` JSON-LD nodes and in the `/llms.txt` document. It must be configured before the site goes live — without it, those nodes will output empty values.

---

## Admin location

**Marketing → SEO → Organisation**

The form supports standard Magento store-scope switching: select a website or store view from the scope selector at the top of the admin page to configure a scope-specific override, exactly as you would with system configuration fields.

---

## Fields

| Field | Purpose | Notes |
|---|---|---|
| Name | Organisation display name | Used in JSON-LD `name`, WebSite `name`, and `/llms.txt` header. Falls back to store name in `/llms.txt` if blank. |
| URL | Canonical site URL | Used as the `@id` anchor for all linked schema nodes (e.g. `https://example.com/#organization`). Include `https://`, no trailing slash. |
| Description | Short tagline | Shown in JSON-LD `description` and at the top of `/llms.txt`. |
| Organisation type | Schema.org `@type` | `Organization`, `Corporation`, `LocalBusiness`, `NGO`, `EducationalOrganization`, `GovernmentOrganization`. Most stores should use `Organization`; a business customers visit in person should use `LocalBusiness`. |
| Logo | Image for the Organization `logo` node | Can use the current theme logo (from Design config) or a custom upload. |
| Logo width / height | Pixel dimensions of the logo | Both required for a valid Organization schema. |
| Social profiles | Social profile URLs | Added as the `sameAs` array. One URL per row. |
| Contact type | `contactType` for the ContactPoint node | e.g. `customer support`, `sales` |
| Contact email | `email` for the ContactPoint node | Also the AI contact in `/llms.txt` and `/llms-full.txt`. Left blank, those fall back to the store's Customer Support email unless it is still Magento's `support@example.com` — see [AI contact](llms-txt.md#ai-contact). |
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
more specific subtype (`Store`, `Restaurant`, …) through `OrganisationInterface::setOrgType()`
adds it to the list of local business types from its own `di.xml`, so `geo` and `priceRange` apply
to it too:

```xml
<type name="MageOS\Seo\Model\StructuredData\Provider\OrganisationProvider">
    <arguments>
        <argument name="localBusinessTypes" xsi:type="array">
            <item name="Store" xsi:type="string">Store</item>
        </argument>
    </arguments>
</type>
```

**Renamed types.** Earlier versions offered `EducationalOrg` and `GovernmentOrg`, which are not
schema.org types. The data patch `RenameOrganisationTypes` renames stored records to
`EducationalOrganization` and `GovernmentOrganization` on `setup:upgrade`, and reverting it
renames them back.

---

## Multi-store scoping

Organisation settings are stored per scope in the `mageos_seo_organisation` table, using the same `scope` / `scope_id` pattern as Magento system config:

| Scope | scope column | scope_id column |
|---|---|---|
| Global default | `default` | `0` |
| Website override | `websites` | website ID |
| Store-view override | `stores` | store view ID |

**Fallback chain (frontend):** When building JSON-LD or `/llms.txt`, the system looks for a store-view specific row first, then a website row, then the global default. The most specific scope wins.

**Deleting a scope:** because `scope_id` means a website ID in one row and a store view ID in
the next, the table carries no foreign key to clean up after itself — `core_config_data` has the
same problem. `MageOS\Seo\Observer\RemoveOrganisationOnScopeDelete` removes the records of a
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

The logo field has two modes:

- **Use design logo** — automatically reads the logo configured under **Content → Design → Themes → Header Logo**. The path and URL are resolved from the current store's media URL. If the theme logo changes, the Organisation record stays in sync automatically.
- **Custom logo** — upload a specific image file. Use this if the Organisation logo differs from the storefront header logo (e.g. a square icon for schema.org vs. a wide banner logo for the header).

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

The Organisation URL, the social profiles and the logo are published into JSON-LD and Open Graph
tags, where they end up in `href`, `src` and `@id` positions. Only addresses a browser can safely
follow are stored:

- `http://` and `https://`;
- a relative path, such as the media path a logo upload produces;
- **not** `javascript:`, `data:`, `vbscript:`, `file:` or any other scheme;
- **not** `//host/path`, which adopts the page's scheme and points at another host;
- **not** anything containing a control character, which would break out of the attribute it is
  written into.

An Organisation URL or logo that fails this is refused with a message, so nothing is silently
changed. Invalid social profiles are dropped and named in the message, because the rest of the
record is still worth saving.

---

## What happens if Organisation is left blank

- The `OrganisationProvider` returns an empty schema array — no `Organization` or `WebSite` nodes are output in JSON-LD.
- `/llms.txt` uses the store name as the heading but omits the description and social profiles.
- Google Search Console and schema validators will not flag errors, but rich results that depend on the `Organization` node will not be eligible.

Configure Organisation before going live.
