# Hreflang alternates & sitemap

For a store serving the same catalogue through several store views, this module publishes the
alternates two ways:

| Where | What |
|---|---|
| Page head | `<link rel="alternate" hreflang="…">` for the current entity, one per store view it exists in |
| `sitemap.xml` | The same alternates inline beside each URL, when the MageOS SEO sitemap generator is selected — see [sitemap.md](sitemap.md) |

Both are built from the same data, so a page and the sitemap never disagree.

How the sitemap is generated, rebuilt and stored is in [sitemap.md](sitemap.md) — this page is
about what goes in it. The dedicated `/hreflang-sitemap.xml` earlier versions served is retired:
see [The retired /hreflang-sitemap.xml](#the-retired-hreflang-sitemapxml).

---

## What appears as an alternate

Only entities a visitor can actually reach: an **enabled** product that is visible in the
catalogue, an **active** category, and an **active** CMS page in the store views it is
assigned to.

A `url_rewrite` row outlives the state of the thing it points at — disabling a product leaves
its rewrite exactly where it was — so the alternates are read with the entity's own published
state joined at each row's store view, rather than from the rewrite alone. This applies equally
to the sitemap and to the `<link rel="alternate">` tags in the page head.

Products and categories keep that state per store view with a global fallback, and the
store-view value wins, so a product disabled in one store view drops out of that store view's
alternates while remaining in the others.

Rewrites carrying category context (the category-nested product URLs) are skipped: an alternate
must point at the canonical URL of the entity, not at one path through the catalogue to it.

---

## Which store views are alternates of each other

The set of store views an entity is advertised in — its *alternate set* — is either every
active store view, or only those of the current website, depending on whether hreflang is
limited to the current website. A store view contributes only when hreflang is enabled for it
and it has a resolvable locale.

Alternates need **at least two** store views in the set: one store view has nothing to be an
alternate of, so its pages' heads and its sitemap URLs carry none.

---

## The code each store view announces

**Stores → Configuration → MageOS → SEO → Hreflang (Multistore)**, at store view scope.

By default a store view announces its **locale**: `en_GB` becomes `en-GB`. That is right until the
locale cannot say where the store is for. Magento has no `en_IE` locale, so an Irish store view
runs on `en_GB`, and a Latin-American store on `es_MX` or `es_ES` serves more countries than its
locale names.

**Hreflang Codes** replaces the locale with codes you choose, comma-separated:

| Store view | Locale | Hreflang Codes | Announces |
|---|---|---|---|
| UK | `en_GB` | *(empty)* | `en-GB` |
| Ireland | `en_GB` | `en-IE` | `en-IE` |
| Latin America | `es_MX` | `es-MX, es-AR, es-CL, es-CO` | all four, at the same URL |

A code is a two-letter language, optionally a four-letter script and a two-letter region: `en`,
`en-GB`, `zh-Hant`, `zh-Hant-TW`. The value is checked when you save it, because Google can
discard a whole alternate set over one bad annotation:

- malformed codes are refused — including `es-419`, a UN M.49 region Google does not accept;
- a region must be an ISO 3166-1 country known to Magento: the United Kingdom is **`GB`**, and
  `en-UK` is refused;
- a code listed twice is refused, whatever its case; everything is stored normalised (`en_gb`
  is saved as `en-GB`).

**One code, one store view.** Two alternates with the same code are invalid, so when store views
claim the same code — typically two store views on the same locale — the **lowest store view ID
keeps it** and the other loses only that code. A store view whose every code is taken drops out
of the alternates altogether. So a UK store and an Irish store both on `en_GB` used to leave the
Irish store invisible; give the Irish store `en-IE` and both are listed.

### Language-only tags

With **Add Language-only Tags** on, a language served by **exactly one store view** also gets a
bare-language alternate — `de` beside `de-DE` — so German speakers outside Germany are pointed at
it. It is counted by store view, not by code: a store view announcing `es-MX` and `es-AR` is still
the only Spanish one, and gets `es`. A language two store views share gets no bare tag, since it
could not say which of them is meant; nor does a language a store view already announces bare.

### x-default

**x-default Store View** is the page for visitors none of the codes match. It is set **per
website**: a `.com` website can send unmatched visitors to its US store while a `.co.uk` website
sends them to its UK store. The website default applies to websites that do not set their own.

The chosen store view must itself be in the alternate set — enabled, not excluded, and on the
same website when alternates are limited to the current website — or no x-default is written.

---

## CMS pages in other languages

Products and categories are translated in place: one entity, one value per store view, so its
alternates are simply the same entity in the other store views. CMS pages are not. Each language
is usually a page of its own — *About us* for the UK store, *Über uns* for the German one — and
nothing in core says they belong together.

**Hreflang Translation Group**, in the page's **Search Engine Optimization** section, says so:
give every translation the same value, for example `about-us`. Pages sharing a group are
alternates of one another, in the head and beside each of their URLs in the sitemap. A page
without a group is linked only to itself in the other store views it is assigned to.

In each store view, the group is represented by:

1. the page of the group **assigned to that store view**, over one assigned to all store views —
   so a shared English page can stand in wherever there is no translation yet;
2. between two equally assigned pages, the **lowest page ID**. Two translations for one store view
   is a mistake to fix, but the answer never depends on the order the database returns rows in.

Only published pages take part: an inactive translation is skipped, and the store view falls back
to the next candidate.

A group is an identifier: letters, digits, dots, hyphens and underscores, stored lower-case
(`About-Us` is saved as `about-us`). Anything else is refused with a warning — the page itself is
still saved. The restriction exists because the group is matched in SQL, where the default
collations treat `About` and `about`, or `café` and `cafe`, as equal, and grouped in PHP, which
does not; storing one canonical form is what keeps the two in agreement.

Changing a page's group in the admin queues a sitemap rebuild and purges the cached copies of
every page in the group it left and the group it joined, since each of them lists the others in
its head; deleting a page in a group — from the admin, by REST or an import — purges the rest of
its group the same way. This goes through `clean_cache_by_tags`, so it reaches Varnish as well
as the built-in full page cache.

Coming from `MageOS_Hreflang`: its `cms_page.meta_identifier` is the same idea, and is migrated
into this field — see [Moving from MageOS_Hreflang](#moving-from-mageos_hreflang).

---

## Adding, removing or rewriting alternates

Every alternate set — for the head and for the sitemap alike — passes through
`MageOS\Seo\Model\Hreflang\AlternateBuilder`, which dispatches
**`mageos_seo_hreflang_alternates_after`** before it is used. The event carries `transport`, a
`DataObject` holding:

| Key | Contents |
|---|---|
| `alternates` | The list about to be rendered, each entry `['hreflang' => …, 'url' => …]`, x-default and language-only tags included |
| `region_links` | For context: the per-store-view links it was built from, each with its `store_id` |

Replace `alternates` to add, remove or rewrite entries — pointing a code at a partner site, for
example:

```php
public function execute(\Magento\Framework\Event\Observer $observer): void
{
    $transport  = $observer->getEvent()->getData('transport');
    $alternates = $transport->getData('alternates');

    $alternates[] = ['hreflang' => 'fr-FR', 'url' => 'https://partner.example.fr/...'];

    $transport->setData('alternates', $alternates);
}
```

A value that is not an array is ignored and the module's own set is used, so a broken observer
cannot take the page head or the sitemap down with it.

**Depend only on the transport.** The sitemap is built in the background — by cron or the queue,
emulating each store view — with no request to go by; take what you need from `region_links`,
not from the request.

Coming from `MageOS_Hreflang`, whose `mageos_hreflang_alternative_urls_after` event did the same
job: its transport held a `code => url` map under `urls`; this one holds a list under
`alternates`. Observers need porting, not just re-registering.

---

## Moving from MageOS_Hreflang

`setup:upgrade` carries `MageOS_Hreflang`'s settings and CMS page links over and then switches its
head output off, so the store keeps the alternates it was publishing — published once, not twice.
The rule throughout is that **the store keeps publishing hreflang wherever it did**:

| MageOS_Hreflang | Becomes | When |
|---|---|---|
| **Use alternative languages meta tags** (`web/seo/use_hreflangs`) | **Enable Hreflang Tags**, per store view | Where it was on and this module's setting would be off. A store view it had off is never switched off here. |
| **Alternative languages meta tags sitemap specification** (`web/seo/use_sitemap_hreflangs`) | **Add Hreflang Alternates to sitemap.xml** | Where it was on anywhere and this module's is off. |
| **Hreflang value** (`web/seo/hreflang`), per store view | **Hreflang Codes** | Where its tags were on, and the store view has no codes here yet. Codes are validated as the admin does: `en-uk`, for one, is dropped. |
| **x-default store** (`web/seo/hreflang_xdefault_store`), per website | **x-default Store View**, per website | Where its output was on in that website and the store view still exists. |
| **Hreflang association identifier** (`cms_page.meta_identifier`) | **Hreflang Translation Group** | Every page with a value and no group here yet. Values are turned into valid groups: `About Us` becomes `about-us`. |

Its alternates were always limited to the current website, which is this module's default too.

Then, only if it was on somewhere, its head setting is set to **No** globally and its website
and store view values are removed, so nothing below the global value switches it back on.

Its sitemap setting is left as it is, because the two modules cannot both write a sitemap's
alternates: where this module's generator writes the sitemap, `MageOS_Hreflang`'s rows are never
written; where **Magento** is selected as the generator, its alternates are the only ones the
sitemap has, and switching them off would lose them.

Nothing else of that module is touched — its codes, x-default and `meta_identifier` column stay
where they are. Once the sitemaps are generated by this module, it can be disabled or removed at
leisure.

Everything the migration did, every value it could not carry, and every value it removed to
switch the module off is written to `var/log/system.log`, prefixed `MageOS_Seo: MageOS_Hreflang
import`. To switch `MageOS_Hreflang` back on, restore the removed values from that log entry.

The migration runs once, on the `setup:upgrade` that installs this version. It has no revert step:
settings made in this module afterwards are indistinguishable from imported ones.

---

## The retired /hreflang-sitemap.xml

Earlier versions served the alternates in a sitemap of their own, `/hreflang-sitemap.xml`. Google
prefers them inline in the sitemap it already has, so they are now written into `sitemap.xml` and
the dedicated file is gone: the path answers 404, with no redirect.

- If you submitted `/hreflang-sitemap.xml` in Search Console, remove it there; `sitemap.xml`
  carries the same alternates.
- The upgrade deletes its files (`hreflang-sitemap*.xml`) from every store directory in feed
  storage, clears its pending rebuild, and purges its cached responses
  (`Setup\Patch\Data\RemoveHreflangSitemap`). With feed storage in a host-local `var/`, only the
  host that runs `setup:upgrade` is cleaned; the files on the others can no longer be reached and
  can be deleted by hand.
- `mageos:seo:feeds:regenerate -g hreflang` is gone; `-g sitemap-…` rebuilds the XML sitemaps
  (see [sitemap.md](sitemap.md#changes-that-are-not-seen)).
