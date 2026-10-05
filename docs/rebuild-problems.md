# When SEO files are out of date

The XML sitemaps, and the llms documents when [MageOS_Aeo](https://github.com/mage-os-lab/module-aeo)
is installed, are written in the background: by the `mageosSeoFeedRegenerate` queue consumer after a
change, and by the cron on a schedule. A failure there reaches only the system log, so a file could
stay out of date for weeks with nobody noticing. The admin shows it as well.

---

## What the admin shows

- **The System Messages bar** at the top of every admin page: **Some SEO files are out of date.**,
  with one line per problem. Up to five are listed and the rest are counted. Only admins whose
  role includes **Marketing → SEO** (`MageOS_Seo::seo`) see it.
- **The inbox** (the bell): one entry when a problem first appears, titled **Some SEO files are out
  of date:** and the files it is about. A problem that continues does not add another entry.

The message goes away by itself once the files are rebuilt. Nobody has to dismiss it.

## What a line says

Each line names the files, which store or Site Map they are for, and since when. Times are in the
admin's language and the timezone under **Stores → Configuration → General → Locale Options**.

| The line says | What happened | What is served meanwhile |
|---|---|---|
| … **could not be rebuilt** (since …) | The rebuild threw. The reason is the error as it was thrown. | The previous version of the file |
| … **is incomplete** (since …) | The file was written, with something missing. The reason says what. | The incomplete file |
| … **changes since … are waiting** | A rebuild was asked for over an hour ago, and the queue has not picked it up | The files as they were |

The incomplete files MageOS_Aeo reports:

| Reason | What is missing |
|---|---|
| The stock lookup failed, so some products are listed as out of stock. | `llms.jsonl` lists the products of the failed lookup as out of stock |
| The configured storage directory is not allowed, so the files are kept in var/mageos_aeo instead. | On a multi-server install, the web servers may not see the files. See MageOS_Aeo's [feeds.md](https://github.com/mage-os-lab/module-aeo/blob/main/docs/feeds.md#storing-the-feeds-outside-var-multi-server) |

## When it is retried

Each line says when its files are next rebuilt without anyone acting:

| Files | Retried by |
|---|---|
| Site Map … | Magento's sitemap cron, while **Stores → Configuration → Catalog → XML Sitemap → Generation Settings → Enabled** is Yes, at its **Start Time** and **Frequency**. With Rebuild on Change, also the next change to what the sitemap lists |
| llms.txt and llms-full.txt, llms.jsonl | MageOS_Aeo's nightly cron (`mageos_aeo_regenerate_feeds`, 02:30), and the next change to what they list |
| Another module's files | Its own cron job, if it names one (see [extending.md](extending.md#telling-the-admin-about-your-files)). Otherwise the next change to what they list |

The time is when the cron is due to run the job, worked out from the job's schedule as Magento's
own cron works it out, a schedule changed in the configuration included. It assumes the server's
cron is running: nothing in the admin can tell whether it is.

## Retrying sooner

**In the admin**, a Site Map can be generated again under **Marketing → Site Map**: select it and
click **Generate**. That writes the whole sitemap and clears its problems.

**On the server**, a developer can rebuild the files a line names. The message lists the command
for each one:

| Files | Command |
|---|---|
| Site Map CMS pages, categories, products, other links | `bin/magento seo:rebuild -g sitemap-pages` (`-categories`, `-products`, `-other`) |
| Site Map (all files) | `bin/magento seo:rebuild -g 'sitemap-*'` |
| Site Map (first build) | `bin/magento seo:rebuild -g sitemaps-missing` |
| llms.txt and llms-full.txt | `bin/magento seo:rebuild -g llms` |
| llms.jsonl | `bin/magento seo:rebuild -g jsonl` |

A run of one group clears that group's problems when it succeeds, and shows the new ones when it
does not. A run with no `-g` rebuilds every feed, and MageOS_Aeo records each feed's result itself.

**When the queue is not running**, nothing that is queued gets rebuilt until it runs again, and the
message says so. Magento's `consumers_runner` cron starts the consumer, or your process manager
(supervisor, for example). To process what is waiting by hand:

```bash
bin/magento queue:consumers:start mageosSeoFeedRegenerate --max-messages=10
```

The stalled line goes as soon as the consumer takes a message.

## The inbox entry's language

An inbox entry is stored text, written by the process that found the problem. The cron loads the
default admin language and writes in it; the queue consumer loads no language and writes English.
The System Messages bar is worded when it is shown, so it follows each admin's own language.

## For developers

- The problems are kept in one flag, `mageos_seo_rebuild_problems`, by
  `MageOS\Seo\Model\Rebuild\ProblemLog`. `MageOS\Seo\Model\System\Message\RebuildProblems` shows
  them, registered in `Magento\Framework\Notification\MessageList` in `etc/adminhtml/di.xml`.
- What a group's rebuild returns becomes the group's problems: a rebuild covers the whole group,
  so a clean one clears them. The queue consumer and `seo:rebuild -g` record it for every group.
- Core's sitemap cron and the **Generate** button write a whole sitemap through `generateXml()`:
  its result settles that sitemap's problems in every sitemap group.
- A module reports its own files, labels them and names their cron job as
  [extending.md](extending.md#telling-the-admin-about-your-files) describes.
