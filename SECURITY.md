# Security policy

## Supported versions

Security fixes are made on the latest release.

## Reporting a vulnerability

Please don't open a public GitHub issue for a security problem. Use GitHub's private advisory flow instead:

1. Go to https://github.com/mage-os-lab/module-seo/security/advisories/new
2. Fill in a concise title and a clear description with reproduction steps.
3. Submit. The maintainers get notified privately.

## What to expect

- Initial acknowledgement within five working days.
- Triage + severity assessment within ten working days.
- A fix plan or a published advisory within thirty days of the report, depending on severity.
- Coordinated disclosure. We'll credit the reporter in the release notes unless you prefer anonymity.

## Scope

In scope:
- SQL injection, XSS, CSRF, privilege escalation, or path traversal in any module code under this repository.
- XSS or markup injection reaching the storefront output — JSON-LD, meta and Open Graph tags, canonical and hreflang links, robots meta — through stored SEO data: the Organization record, and the category, product and CMS page SEO fields.
- A URL scheme other than http/https (`javascript:`, `data:`) getting past the validation of the Organization's URL, social profiles or logo.
- A file other than a raster image getting through the Organization logo upload.
- Authorization bypass in the Organization admin controllers or its form.
- A sitemap written outside the path its Site Map entry configures, or listing pages the storefront does not show, such as a disabled product or an inactive category or CMS page.

Out of scope (not a MageOS_Seo vulnerability):
- Issues in Magento / Mage-OS core or unrelated third-party modules. MageOS_Faq, MageOS_Aeo and MageOS_Agentic have security policies of their own.
- Social engineering, physical attacks, denial-of-service by volume.
- Findings that require admin-role access already granted by the merchant.

## Hardening

- Keep Magento / Mage-OS on a supported security patch level.
- Run `composer audit` regularly and apply dependency updates.
- Enable GitHub Dependabot alerts for your own fork (it's on by default for public repos).
