# Hreflang alternates & sitemap

For a store serving the same catalogue through several store views, this module publishes the
alternates two ways:

| Where | What |
|---|---|
| Page head | `<link rel="alternate" hreflang="…">` for the current entity, one per store view it exists in |
| `/hreflang-sitemap.xml` | The same relationships for the whole catalogue, plus `hreflang-sitemap-<n>.xml` chunk files when it is large |

Both are built from the same data, so a page and the sitemap never disagree.

How the sitemap is generated, rebuilt and stored is in [feeds.md](feeds.md) — this page is about
what goes in it.

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

The sitemap can only be built where there are **at least two** active store views: one store
view has nothing to be an alternate of. Where that is not the case the sitemap is not built,
and any file already written for it is removed on the next rebuild.

---

## File layout

Below 50,000 URLs the sitemap is a single `<urlset>` served at `/hreflang-sitemap.xml`. Above
it, the URLs are split into `hreflang-sitemap-<n>.xml` chunk files with a sitemap index at that
same URL. Which of the two it is only becomes clear once the stream ends, so each file is named
as it is committed. Chunks are written before the index, and chunks the new set no longer
contains are removed after it — the served index never points at a file that is not there.

The sitemap lists every store view of an alternate set, so store views sharing a set produce
byte-identical chunk files. Only the **first store view of a set builds them**; the rest copy
those files and write only their own index, which carries their base URL. A rebuild that would
otherwise cost "whole catalogue × store views" therefore costs one catalogue pass per alternate
set — one per website when hreflang is limited to the current website, one for the whole install
when it is not.

---

## No URL rewrites needed

A custom router serves `/hreflang-sitemap.xml` and its chunk files directly. Do **not** add
manual URL rewrites for these paths — the internal controller URLs 301-redirect to the canonical
paths, so a rewrite would fight the router.
