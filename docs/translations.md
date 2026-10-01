# Translations

The module ships its text in three locales:

| File | What it is |
|---|---|
| `i18n/en_US.csv` | The source: every phrase the module uses, in US English, mapped to itself. |
| `i18n/en_GB.csv` | British spelling, only for the phrases where it differs (Organisation, Colour, catalogue, …). Magento uses the source for the rest. |
| `i18n/nl_NL.csv` | Dutch, every phrase. Informal *je*; *scope* is left as it is. |

---

## What is translated

- **The admin:** configuration labels and comments, the category, product and CMS page SEO fields, the
  FAQ and Organization screens, menu items, ACL resources and messages. The admin user's interface
  locale decides the language.
- **The storefront:** the breadcrumb's "Home".
- **The llms documents** (`/llms.txt`, `/llms-full.txt`). Their headings and labels are written in
  the store view's language (**General → Locale Options → Locale**), the same language as the product
  names, descriptions and categories they list. The `> Locale:` line states which language that is.
  The rebuild switches to each store view's environment before writing, so the language follows the
  store view, not the admin or CLI user.

## What is not

- **Structured data values** (JSON-LD): they are schema.org vocabulary.
- **Field codes and template codes** (`gtin13`, `Apparel`): they are identifiers that configuration
  and overrides refer to. Their labels are translated.
- **Plain robots directives** ("INDEX, FOLLOW"), as in core's own list. Labels that add words to a
  directive are translated.
- **CLI output and log messages.**

---

## Overriding a translation

Magento loads translations in this order, each overriding the one before:

1. the module's `i18n/<locale>.csv`;
2. a language pack for the locale;
3. the theme's `i18n/<locale>.csv`;
4. inline translations stored in the database.

To change a phrase for one store, add it to your theme's `i18n/<locale>.csv` rather than editing the
module's file:

```csv
"Enabled Optional Fields","Optional fields"
```

The first column must be the source phrase exactly, including line breaks and HTML.

---

## Adding a locale

1. Copy `i18n/en_US.csv` to `i18n/<locale>.csv` (for example `de_DE.csv`).
2. Translate the second column. Keep `%1`-style placeholders and HTML tags (`<strong>`,
   `<br/>`, `<code>`) exactly as they are.
3. Where a phrase names another setting or option ("Choose Applies Worldwide …"), use that setting's
   translated label, so the screens agree with each other.

A locale can also live outside the module, in a language pack or a theme, as described above.

---

## Adding a phrase (contributors)

- **Wrap user-facing text in `__()` with literal strings.** The phrase collector cannot read a phrase
  built from variables, and it cannot join single- and double-quoted parts: write a long phrase as
  single-quoted pieces joined with `.`, or as one double-quoted string.
- **Labels that are returned rather than rendered** (option arrays, builder labels) use
  `(string) __('…')`, so they are translated when the options are built, in the admin user's locale.
- **Source text is US English.** Add the British form to `en_GB.csv` when it differs.
- **Keep the files in step.** Find the phrases with Magento's collector, run over the module's
  production code only (phrases used only by tests are not the module's):

  ```bash
  cp -r app/code/MageOS/Seo /tmp/seo-prod && rm -rf /tmp/seo-prod/Test /tmp/seo-prod/i18n
  bin/magento i18n:collect-phrases /tmp/seo-prod
  ```

  Every phrase it prints belongs in `en_US.csv` and `nl_NL.csv`, and nothing else does.
