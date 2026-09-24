# Pre-generated feeds

Four documents are generated in the background and served from files:

| URL | What it is | Documented in |
|---|---|---|
| `/llms.txt` | Concise site summary for LLM crawlers | [llms-txt.md](llms-txt.md) |
| `/llms-full.txt` | The extended version | [llms-txt.md](llms-txt.md) |
| `/llms.jsonl` | One JSON-LD `Product` node per line | [llms-txt.md](llms-txt.md) |
| `/hreflang-sitemap.xml` | Alternate URLs per store view, plus its chunk files | [hreflang.md](hreflang.md) |

This page covers what they have in common: how they are built, when they are rebuilt, where
they are stored and what they cost. What each document *contains* is in the pages above.

---

## Generation & cache

The documents are **pre-generated to files** (default `var/mageos_seo/store_<id>/`),
mirroring core `Magento_Sitemap`. Two background processes write them:

- the `mageosSeoFeedRegenerate` **queue consumer** rebuilds a feed group whenever it
  is invalidated (started by the default `consumers_runner` cron, or your process
  manager e.g. supervisor). Duplicate invalidations are collapsed: at most one build
  per feed group is queued at a time, and changes arriving during a build queue
  exactly one follow-up rebuild;
- the `mageos_seo_regenerate_feeds` **cron job** (nightly) does a full rebuild as a
  safety net for changes that carry no invalidation event.

Web requests **never** build the documents. An invalidation only queues a rebuild: the
current files keep being served until the consumer has written their replacements.
Each file is written to a temporary file and renamed into place, so a request never
sees a partially written document. When a file does not exist yet (fresh install, new
store view), the controller queues a rebuild and answers `503` with `Retry-After`
until the consumer has written it. Requests with query strings — and the internal
`/mageos-seo/...` controller URLs — are 301-redirected to the canonical path so they
cannot be used to force cache misses.

A queued rebuild that the consumer has not picked up within one hour is queued again,
and a warning is logged (`the "<group>" feed rebuild … was never picked up`). If you
see that warning, the consumer is not running: check your cron or process manager.

### Only one rebuild runs at a time

Three things write the same files — the consumer, the nightly cron and
`bin/magento mageos:seo:feeds:regenerate` — and none of them is guaranteed to be alone:
`consumers_runner` can be configured to run several processes of a consumer, and on a
multi-server install the cron runs on every node. A shared lock
(`Magento\Framework\Lock\LockManagerInterface`, so it spans processes and hosts) lets one
rebuild through at a time:

- the **consumer** puts its message back on the queue, so the invalidation is not lost;
- the **cron** skips and logs at info level — the process holding the lock is doing the same
  work, and the cron comes round again;
- the **CLI** reports that a rebuild is already running and exits non-zero, so a deployment
  script cannot mistake it for a completed build.

Nothing needs configuring for this. The lock uses whichever lock provider the installation
already has (database by default; Zookeeper, Redis or the filesystem if configured).

### Queue transport

`etc/queue_consumer.xml`, `etc/queue_publisher.xml` and `etc/queue_topology.xml` name **no
connection and no `maxMessages`**, exactly as core's own queue configuration does. The topic
therefore travels over whatever transport the installation runs — the database queue by
default, AMQP where that is configured — and honours the install's
`queue/consumers_max_messages`. There is nothing to override in `env.php` to move this module
onto RabbitMQ.

No session is started for these requests. A session cookie stops shared caches storing a
response at all, and makes PHP emit `Pragma: no-cache` over the policy below; none of these
endpoints read session state.

Responses are served with `Cache-Control: public, max-age=86400, s-maxage=86400`, so
browsers, Varnish and the built-in full page cache keep them for **24 hours**. They
are tagged `MAGEOS_SEO_LLMS` (`/llms.txt`), `MAGEOS_SEO_LLMS_FULL` (`/llms-full.txt`),
`MAGEOS_SEO_LLMS_JSONL` (`/llms.jsonl`) and `MAGEOS_SEO_HREFLANG_SITEMAP`
(`/hreflang-sitemap.xml` and its chunks). After rebuilding a feed group, the consumer
and the cron purge that group's tags, so cached copies are replaced as soon as the new
files exist.

> **With the built-in full page cache**, Magento replaces the client-facing headers on every
> cacheable page with `Pragma: no-cache` and `Cache-Control: max-age=0, must-revalidate`,
> keeping the real policy in `X-Magento-Cache-Control`. That is core behaviour, not a setting
> of this module: browsers will re-request the feeds on each visit. Varnish and CDNs receive
> the 24-hour policy as written.

---

## When a rebuild is queued

| Change | `/llms.txt`, `/llms-full.txt` | `/llms.jsonl` | Hreflang sitemap |
|---|---|---|---|
| Product saved | new product, or its categories or websites changed (product counts) | always | new product, or `url_key`, `status`, `visibility` or websites changed |
| Category saved | always | — | new category, or `url_key` or `is_active` changed |
| CMS page saved | — | — | new page, or identifier, `is_active` or store views changed |
| Store view saved | — | — | always |
| Category moved | always | — | always |
| Product deleted | always | always | always |
| Category deleted | always | — | always |
| CMS page deleted | — | — | always |
| Store view deleted | — | — | always |
| Store group or website deleted | — | — | always |
| Mass attribute update | — | always | `url_key`, `status` or `visibility` among the updated attributes |
| Mass website assignment change | always | always | always |
| Organisation settings saved | always | — | — |
| FAQ saved or deleted | always | — | — |

Mass actions — the admin grid's "Update attributes", mass enable/disable and mass website
assignment, and anything else going through `Magento\Catalog\Model\Product\Action` — write
straight to the catalogue tables without saving the products, so no save event reports them.
They are covered separately (a plugin for attributes, the `catalog_product_to_website_change`
event for websites).

Deleting a store view also removes that store's feed directory, and queues the sitemap rebuild
that corrects the store views left behind. That includes the deletion that leaves only one store
view: a single store view has no alternates, so the sitemap can no longer be built — and the
rebuild is what removes the one the survivor is still serving, rather than leaving it listing a
store view that no longer exists.

Deleting a **store group or a website** takes its store views with it in the database, without
dispatching a `store_delete` event for any of them, so the rebuild is queued from the website's
or group's own deletion instead. Their feed directories are removed by the next full rebuild
(the nightly cron, or `mageos:seo:feeds:regenerate` with no `-g`), which sweeps directories
whose store view no longer exists. Until then nothing serves them: a request resolves feeds for
the current store view, and theirs is gone.

A feed that no store view can build is never queued: `/llms.jsonl` while it is disabled in
every store view (the default), `/llms.txt` + `/llms-full.txt` when both are disabled
everywhere, and the hreflang sitemap when it is disabled, hreflang is disabled in every
store view, or fewer than two store views are active. The logic lives in
`MageOS\Seo\Model\Feed\InvalidationPolicy`.

Changes that no event reports — native CSV imports, direct database writes, configuration
changes — are picked up by the nightly rebuild.

The XML sitemaps configured under Marketing → Site Map are rebuilt on change through the same
queue, one kind of page at a time; what queues them is in
[sitemap.md](sitemap.md#keeping-sitemaps-current).

A feed that is disabled for a store view is removed from that store's directory on the
next rebuild, so re-enabling it later produces a fresh build rather than an outdated file.

---

## Build cost

The large feeds are **streamed to their file** rather than assembled in memory:
`/llms.jsonl` is built one product per line from a paged collection, and the hreflang
sitemap reads its URL rewrites row by row, writing each `<url>` block as it goes. Peak
memory is that of one page of products, not of the whole document — at 100k SKUs the
jsonl document alone runs to tens of megabytes.

The sitemap's own economies — chunking above 50,000 URLs, and building each alternate set
once — are described in [hreflang.md](hreflang.md).

---

## Where the files are stored

`mageos_seo_general/feeds/storage_dir` is empty by default, which means `var/mageos_seo`.
Because it is an absolute path set from the admin panel, what it may point at is restricted:

- **inside the installation, `var/` only.** The root itself and every other standard
  directory — `app/`, `bin/`, `dev/`, `generated/`, `lib/`, `pub/`, `setup/`, `update/`,
  `vendor/` — are refused, so no configuration value can reach the codebase;
- **no hidden directories** anywhere, so `.git`, `.ssh` and friends are unreachable even
  under `var/`;
- no `..`, and the path is resolved before it is judged, so a symlink inside `var/`
  cannot stand for a target outside it;
- the directory must already exist and be writable.

The rules are applied twice: when the value is saved, with the reason shown in the admin, and
again when it is read — a row can reach `core_config_data` from a data patch, a deployment tool
or straight from the database, and a directory these rules refuse is never written to however it
arrived. A refused value is logged and the feeds fall back to `var/mageos_seo` rather than
failing.

### Permissions

Feed files are written with mode `0640` and the feed directories (`var/mageos_seo/` and
each `store_<id>/`) with `0750`, whatever the process umask. The user running cron and
the consumer and the web server's PHP user must therefore be the same user or share a
group — Magento's standard file-ownership model. With a custom `storage_dir`, the root
directory you configure keeps its own permissions; only the `store_<id>/` directories
below it are set to `0750`.

### Storing the feeds outside var/ (multi-server)

`var/` is host-local, so a deployment with more than one web server needs a mount all of them
share with the host running cron and the queue consumer — which is, by definition, outside
`var/`. Permit that root in `app/etc/env.php`, which is deployment configuration the admin
panel cannot edit:

```php
<?php
return [
    'db' => [ /* ... */ ],

    // Roots the SEO feeds may be stored under, in addition to var/.
    'mageos_seo' => [
        'feed_storage_roots' => [
            '/mnt/shared/mageos-seo',
        ],
    ],

    'MAGE_MODE' => 'production',
];
```

Then set **Stores → Configuration → MageOS SEO → Feeds → Storage Directory** to that path, or
anything below it — `/mnt/shared/mageos-seo/site-a` is accepted by the same entry.

Setting it up:

1. **Every node needs the `env.php` entry** — each web server, the cron host, and whatever runs
   the queue consumer. `env.php` is per-machine, and the check runs wherever the code runs.
2. **The root must exist on the node doing the checking.** It is resolved before it is compared,
   and a root that cannot be resolved is dropped from the permitted list. If the mount is missing
   on the admin node, saving the field is refused there even though cron would have been happy.
3. **The mount must be writable by the feed writers and readable by PHP-FPM**, per the
   permissions note above.
4. **`bin/magento setup:config:set` will not write this key** — it only handles the options it
   knows about. Add it by editing `env.php`, or through whatever templating your deployment uses.
5. A single string is accepted as well as a list (`'feed_storage_roots' => '/mnt/shared/feeds'`),
   though the list form is the one to prefer.

Without an entry, nothing outside `var/` is accepted: the key is absent, the permitted list is
empty, and the admin field explains what is wrong when you save it.

**A declared root extends where feeds may go; it does not open up the codebase.** The
installation rule is applied first, so a root pointing at `pub/`, `app/`, `vendor/` or any other
part of the installation is ignored rather than obeyed — an entry copied between environments,
or left behind by a template, cannot make the web root writable by this module. Declaring a
directory under `var/` is harmless but pointless: `var/` is allowed anyway.

---

## Rebuilding by hand

Every `setup:install` / `setup:upgrade` queues a rebuild of the feeds the store views can
build, so a fresh install or a deployment that cleared `var/` does not wait for the nightly
cron.

To rebuild immediately — in a deployment script, or after changing feed configuration —
run:

```bash
bin/magento mageos:seo:feeds:regenerate            # every feed, every active store view
bin/magento mageos:seo:feeds:regenerate -g llms    # one group: llms | jsonl | hreflang
```

The same command rebuilds a kind of page in the XML sitemaps, `-g sitemap-products` and so on;
see [sitemap.md](sitemap.md#changes-that-are-not-seen). With no `-g` it rebuilds the feeds only.

It builds in the running process (no queue consumer needed), replaces each file in place,
purges the rebuilt groups' cache tags, and exits non-zero if any store view failed. To
process rebuilds that are already queued instead, run `bin/magento queue:consumers:start
mageosSeoFeedRegenerate --max-messages=10`; otherwise wait for the consumer or the nightly
cron.
