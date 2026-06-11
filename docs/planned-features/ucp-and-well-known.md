# Planned Feature: UCP Profile and Well-Known Endpoints

**Status:** Planned — not yet implemented  
**Module:** `MageOS_Seo`  
**Complexity:** Medium (profile endpoint + key management) to Very High (live capability endpoints)  
**Priority driver:** Google's AI Mode and agentic commerce agents (Gemini, Perplexity, custom MCP clients) use `/.well-known/ucp` to discover whether a store is "agent-ready." Without this profile, the store is invisible to AI shopping agents that can navigate checkout natively.

---

## What UCP is

The **Universal Commerce Protocol (UCP)** is an open standard (Apache 2.0, driven by Google) that lets AI shopping agents negotiate directly with your commerce backend — browsing products, managing a cart, running checkout, and tracking orders — without scraping HTML. The `/.well-known/ucp` manifest is the discovery entry point: it declares what the store supports, which API endpoints handle each capability, and provides the public keys needed to verify signed requests.

UCP is published at [ucp.dev](https://ucp.dev/) and the Google implementation guide is at [developers.google.com/merchant/ucp/guides/ucp-profile](https://developers.google.com/merchant/ucp/guides/ucp-profile).

Think of `/.well-known/ucp` as the agentic-commerce equivalent of `llms.txt`: a machine-readable, publicly accessible manifest that tells AI systems what the store can do and how to talk to it.

---

## Scope of this plan

### Phase 1 — Manifest and well-known endpoints (build now)
- `/.well-known/ucp` — spec-compliant UCP profile JSON
- `/.well-known/ai-plugin.json` — OpenAI/GPT plugin manifest (signals 9 and 14)
- `/.well-known/security.txt` — RFC 9116 security contact disclosure (signal 14)
- ECDSA P-256 signing key generation (CLI command)
- Per-store admin config UI
- Custom router to intercept all `/.well-known/` paths without URL rewrite rules

### Phase 2 — UCP capability endpoints (future — not designed here)
- `dev.ucp.shopping.catalog` → product/category browse endpoint
- `dev.ucp.shopping.cart` → cart create/update/read
- `dev.ucp.shopping.checkout` → checkout session with Google Pay / Stripe
- `dev.ucp.shopping.identity_linking` → OAuth 2.0 customer account linking
- `dev.ucp.shopping.order_management` → order status webhooks

Magento's existing REST API (`/V1/products`, `/V1/carts`, `/V1/orders`, etc.) already implements most of Phase 2 functionally — what Phase 2 requires is UCP-specific authentication wiring and a dedicated bridge module, not new business logic.

---

## UCP profile structure

Current spec version: `2026-04-08`

The profile is a JSON document with four top-level sections:

```json
{
  "$schema": "https://json-schema.org",
  "version": "2026-04-08",
  "merchant": {
    "id": "com.example.uk",
    "name": "Completely Shropshire",
    "domain": "https://uk.example.com"
  },
  "transports": {
    "rest": {
      "baseUrl": "https://uk.example.com"
    }
  },
  "capabilities": {
    "dev.ucp.shopping": {
      "enabled": true
    }
  },
  "signing_keys": [
    {
      "kty": "EC",
      "crv": "P-256",
      "use": "sig",
      "kid": "ucp-key-2026-06",
      "x": "<base64url-encoded-x>",
      "y": "<base64url-encoded-y>"
    }
  ]
}
```

**Critical:** `signing_keys` contains ONLY the public key. The private key must never appear here. The audit check "leaked-private-key detection" specifically looks for the presence of `d` in the JWK object — if `d` is present, the private key is exposed.

---

## Multistore: one manifest per domain

`/.well-known/` paths are resolved at the domain root, not per-path. Multistore implications:

- **Different domains** (`uk.example.com`, `us.example.com`): Each domain resolves its own `/.well-known/ucp`. Magento's HTTP host header determines which store is active at the time of the request, so the controller reads the correct store's config automatically — no special handling needed.
- **Same domain, path segments** (`example.com/uk/`, `example.com/us/`): Only one `/.well-known/ucp` exists for the domain. The manifest must describe the default store's capabilities; there is no per-path UCP profile in the spec.

Config scope: most UCP settings are **website scope** (domain-level), not store-view scope. The signing keypair is per-domain, not per-store-view.

---

## Signing keys

UCP uses ECDSA P-256 (ES256) for request signature verification. When Google sends an agentic commerce request, it signs the payload with its own private key; the store verifies using Google's public key. Conversely, the store's public key in the manifest lets Google verify responses.

### Key generation CLI command

```
bin/magento mageos:seo:ucp:keygen --website=1
```

This command:
1. Generates an ECDSA P-256 keypair using PHP's `openssl_pkey_new()` with `['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]`
2. Extracts the public key coordinates (x, y) in base64url encoding
3. Constructs a JWK `kid` from the current date (`ucp-key-{YYYY-MM}`)
4. Stores the private key PEM in an encrypted config value: `mageos_seo_ucp/signing/private_key` (using `backend_model="Magento\Config\Model\Config\Backend\Encrypted"`)
5. Stores the public JWK JSON in: `mageos_seo_ucp/signing/public_key_jwk`
6. Outputs the public JWK to stdout for verification
7. **Does not** print the private key — it is write-only after generation

### Key rotation

Run `mageos:seo:ucp:keygen` again. The new key gets a new `kid`. The manifest can include multiple keys during a rotation window; remove the old key once Google has acknowledged the new one.

---

## Admin config UI

New section: **Stores → Configuration → MageOS → SEO → UCP**

### General group

| Field | Path | Type | Scope | Default | Description |
| --- | --- | --- | --- | --- | --- |
| Enable UCP Profile | `mageos_seo_ucp/general/enabled` | Yes/No | Website | 0 | Serve `/.well-known/ucp`. Returns 404 when disabled. |
| Merchant ID | `mageos_seo_ucp/general/merchant_id` | Text | Website | (auto-generated from domain) | Reverse-domain merchant identifier (`com.example.uk`) |
| Merchant Name | `mageos_seo_ucp/general/merchant_name` | Text | Website | (store name) | Display name in the manifest |

### Capabilities group

| Field | Path | Type | Scope | Default | Description |
| --- | --- | --- | --- | --- | --- |
| Advertise Catalog API | `mageos_seo_ucp/capabilities/catalog` | Yes/No | Website | 0 | Declares catalog browse capability. Only enable when a UCP catalog endpoint exists. |
| Advertise Cart API | `mageos_seo_ucp/capabilities/cart` | Yes/No | Website | 0 | Declares cart management capability. |
| Advertise Checkout API | `mageos_seo_ucp/capabilities/checkout` | Yes/No | Website | 0 | Declares native checkout capability. |
| Advertise Identity Linking | `mageos_seo_ucp/capabilities/identity_linking` | Yes/No | Website | 0 | Declares OAuth 2.0 customer account linking. |
| Advertise Order Management | `mageos_seo_ucp/capabilities/order_management` | Yes/No | Website | 0 | Declares order status and webhook capability. |

**Default all capability toggles to OFF.** Declaring a capability that has no working endpoint is worse than not declaring it — agents that attempt to use the endpoint will fail, and Google may downgrade the store's agent-readiness score. Enable each toggle only when the corresponding Phase 2 endpoint is live.

### Signing keys group

| Field | Path | Type | Scope |
| --- | --- | --- | --- |
| Private Key (encrypted) | `mageos_seo_ucp/signing/private_key` | Obscure | Website |
| Public Key JWK | `mageos_seo_ucp/signing/public_key_jwk` | Textarea (read-only) | Website |

These fields are populated by the CLI command and are read-only in the UI (displayed for verification only).

---

## UCP manifest builder: ProfileBuilder

`Model/ProfileBuilder.php`:

1. Reads merchant config (id, name, domain from store base URL).
2. Constructs `transports.rest.baseUrl` from store base URL.
3. Iterates capability toggles. Only includes capabilities where the toggle is `true` AND the capability has a registered `CapabilityProviderInterface` implementation (future-proofed for Phase 2 providers).
4. Reads public key JWK from config. If none exists, omits `signing_keys` array (manifest is still valid; signing is optional until Phase 2).
5. Returns the manifest as a PHP array (serialised to JSON by the controller).

---

## Custom router: WellKnownRouter

`Model/Router.php` implements `Magento\Framework\App\RouterInterface`.

Registered in `etc/frontend/di.xml` at lower `sortOrder` than the standard frontend router (runs before any Magento route is matched):

```xml
<type name="Magento\Framework\App\RouterList">
    <arguments>
        <argument name="routerList" xsi:type="array">
            <item name="mageos_seo_wellknown" xsi:type="array">
                <item name="class" xsi:type="string">MageOS\Seo\Model\Ucp\Router</item>
                <item name="disable" xsi:type="boolean">false</item>
                <item name="sortOrder" xsi:type="string">19</item>
            </item>
        </argument>
    </arguments>
</type>
```

The router examines the request path. If it starts with `/.well-known/`, it dispatches to the appropriate controller. If the path is not a recognised well-known endpoint, it returns null (passes to the next router).

This approach mirrors how `Magento\Robots\Controller\Router` intercepts `robots.txt` — no URL rewrite rules needed, works across all environments.

Route map:

| Path | Controller |
| --- | --- |
| `/.well-known/ucp` | `Controller/WellKnown/Ucp` |
| `/.well-known/ai-plugin.json` | `Controller/WellKnown/AiPlugin` |
| `/.well-known/security.txt` | `Controller/WellKnown/SecurityTxt` |

---

## Controller/WellKnown/Ucp

- Returns `404` with body `{"error":"ucp_not_advertised"}` if UCP is disabled.
- Calls `ProfileBuilder::build(int $websiteId): array`.
- Returns JSON with headers:
  - `Content-Type: application/json`
  - `Cache-Control: public, max-age=300` (5 minutes — spec recommendation)
- No FPC caching — this document changes only on config change; 300s browser/CDN cache is sufficient.

---

## /.well-known/ai-plugin.json

The OpenAI plugin manifest format. Used by ChatGPT's web browsing plugin and other GPT-based agents to discover store capabilities.

```json
{
  "schema_version": "v1",
  "name_for_model": "completely_shropshire",
  "name_for_human": "Completely Shropshire",
  "description_for_model": "Shop for handmade and locally sourced goods from Shropshire makers. Browse products, search by category.",
  "description_for_human": "Artisan and handmade goods from Shropshire makers.",
  "auth": {
    "type": "none"
  },
  "api": {
    "type": "openapi",
    "url": "https://uk.example.com/rest/default/schema?services=catalogProductRepositoryV1",
    "is_user_authenticated": false
  },
  "logo_url": "https://uk.example.com/pub/media/logo/logo.png",
  "contact_email": "support@example.com",
  "legal_info_url": "https://uk.example.com/privacy-policy-cookie-restriction-mode"
}
```

Data sources:
- `name_for_human` / `name_for_model` — Organisation name (sanitised to alphanumeric + underscores for model name)
- `description_for_model` — Organisation description (truncated to 200 chars for agent legibility)
- `logo_url` — Organisation logo URL
- `contact_email` — `trans_email/ident_support/email` system config
- `legal_info_url` — configurable: `mageos_seo_ucp/ai_plugin/legal_url`
- `api.url` — Magento's built-in OpenAPI schema endpoint (no new API needed)

Config: `mageos_seo_ucp/ai_plugin/enabled` (Yes/No, website scope, default 0).

---

## /.well-known/security.txt

RFC 9116 — standard machine-readable security contact disclosure. Required by security scanners and increasingly checked by AI systems assessing site trustworthiness.

```
Contact: mailto:security@example.com
Expires: 2027-01-01T00:00:00.000Z
Preferred-Languages: en
Policy: https://uk.example.com/security-policy
```

Admin config:

| Field | Path | Scope |
| --- | --- | --- |
| Enable security.txt | `mageos_seo_ucp/security_txt/enabled` | Website |
| Security Contact Email | `mageos_seo_ucp/security_txt/contact_email` | Website |
| Expires (date) | `mageos_seo_ucp/security_txt/expires` | Global |
| Policy URL | `mageos_seo_ucp/security_txt/policy_url` | Website |

`Expires` is a required field in RFC 9116 — stale security.txt (past its expiry date) is treated as non-existent. The admin UI should show a warning when the expiry is within 60 days.

---

## robots.txt AI bot directives

This belongs in `MageOS_Seo` since it's part of the same "AI agent discoverability" concern.

The existing `Magento_Robots` module serves `robots.txt` via `Magento\Robots\Controller\Index` and allows custom directives via a `robots_custom_instructions` system config field. **Do not replace this controller** — extend it via a plugin or observer.

**Approach:** Plugin on `Magento\Robots\Model\Robots::getData()` that appends AI bot directives based on per-store config.

Known AI crawlers to manage (as of 2026-06-11):

| Bot | User-agent |
| --- | --- |
| Google Gemini | `Googlebot` (same agent, additional signals via UCP) |
| GPTBot | `GPTBot` |
| ChatGPT-User | `ChatGPT-User` |
| Claude | `ClaudeBot` |
| Anthropic AI | `anthropic-ai` |
| Perplexity | `PerplexityBot` |
| Cohere | `cohere-ai` |
| Meta AI | `Meta-ExternalAgent` |
| Apple | `Applebot-Extended` |
| Common Crawl | `CCBot` |
| Diffbot | `Diffbot` |
| Bytedance | `Bytespider` |

Admin config (`mageos_seo_ucp/robots_ai` group):

| Field | Default |
| --- | --- |
| GPTBot | Allow |
| ChatGPT-User | Allow |
| ClaudeBot | Allow |
| anthropic-ai | Allow |
| PerplexityBot | Allow |
| cohere-ai | Allow |
| Meta-ExternalAgent | Allow |
| Applebot-Extended | Allow |
| CCBot | Disallow |
| Diffbot | Allow |
| Bytespider | Disallow |

Defaults: Allow commercial AI search bots that drive traffic; Disallow bulk scrapers (CCBot, Bytespider) by default.

Generated output appended to robots.txt:

```
# AI crawlers
User-agent: GPTBot
Allow: /

User-agent: ClaudeBot
Allow: /

User-agent: CCBot
Disallow: /
```

---

## New files to create in MageOS_Seo

### Module skeleton

| File | Purpose |
| --- | --- |
| `registration.php` | Module registration |
| `composer.json` | Package definition |
| `etc/module.xml` | Module declaration, sequence after `Magento_Robots` |

### Core

| File | Purpose |
| --- | --- |
| `Model/Router.php` | Custom router intercepting `/.well-known/` paths |
| `Model/ProfileBuilder.php` | Builds UCP manifest from config + capabilities |
| `Model/AiPluginBuilder.php` | Builds `ai-plugin.json` from config + Organisation |
| `Model/SecurityTxtBuilder.php` | Builds `security.txt` from config |
| `Model/Config.php` | All config getters for the module |

### Capability extensibility

| File | Purpose |
| --- | --- |
| `Api/CapabilityProviderInterface.php` | Contract: `getCapabilityKey(): string`, `getCapabilityData(int $websiteId): array` |
| `Model/CapabilityPool.php` | Injectable array of providers; used by `ProfileBuilder` |

### Controllers

| File | Purpose |
| --- | --- |
| `Controller/WellKnown/Ucp.php` | Serves `/.well-known/ucp` |
| `Controller/WellKnown/AiPlugin.php` | Serves `/.well-known/ai-plugin.json` |
| `Controller/WellKnown/SecurityTxt.php` | Serves `/.well-known/security.txt` |

### CLI

| File | Purpose |
| --- | --- |
| `Console/Command/KeygenCommand.php` | `mageos:seo:ucp:keygen` — generates ECDSA P-256 keypair |

### Configuration

| File | Purpose |
| --- | --- |
| `etc/module.xml` | Module declaration |
| `etc/di.xml` | Router registration, capability pool wiring |
| `etc/config.xml` | Config defaults |
| `etc/adminhtml/system.xml` | Full admin config UI |
| `etc/adminhtml/di.xml` | Console command registration |

### Plugin

| File | Purpose |
| --- | --- |
| `Plugin/Robots/AppendAiDirectivesPlugin.php` | Appends AI bot rules to `Magento\Robots\Model\Robots::getData()` |

---

## Security considerations

**Private key storage:** The CLI command stores the private key PEM in `core_config_data` using Magento's `Magento\Config\Model\Config\Backend\Encrypted` backend model. This encrypts the value at rest using the deployment key in `env.php`. The decrypted value is never logged and is read only by the key generation command and future request-signing utilities.

**Leaked private key detection:** The audit checks for the `d` field in any JWK object in the manifest. `ProfileBuilder` must:

1. Parse the stored public key JWK.
2. Assert that no `d` key is present before including in the response.
3. If `d` is found (misconfiguration), return `500` and log an error — do not serve a manifest with a leaked private key.

**Manifest must be public:** `/.well-known/ucp` must not require authentication. The controller must not use `Magento\Framework\App\Action\HttpGetActionInterface` with customer session checks. The custom router runs before session initialisation.

---

## Implementation order

1. `registration.php`, `composer.json`, `etc/module.xml`
2. `Model/Config.php` — all getters
3. `etc/adminhtml/system.xml` + `etc/config.xml` — full config UI and defaults
4. `Model/Router.php` + `etc/di.xml` router registration
5. `Model/ProfileBuilder.php` (capabilities all disabled by default, signing key section optional)
6. `Controller/WellKnown/Ucp.php`
7. `Console/Command/KeygenCommand.php`
8. `Model/AiPluginBuilder.php` + `Controller/WellKnown/AiPlugin.php`
9. `Model/SecurityTxtBuilder.php` + `Controller/WellKnown/SecurityTxt.php`
10. `Plugin/Robots/AppendAiDirectivesPlugin.php` + `etc/di.xml` plugin registration
11. `Api/CapabilityProviderInterface.php` + `Model/CapabilityPool.php`
12. — **Tests** —
13. Unit: `ProfileBuilder` — disabled returns null, `d` field in JWK triggers error, no capabilities produces minimal valid manifest
14. Unit: `AppendAiDirectivesPlugin` — allow/disallow config correctly appends/omits User-agent blocks
15. Unit: `KeygenCommand` — keypair generation produces valid EC P-256 keys, `d` absent from stored public JWK
16. Integration: `GET /.well-known/ucp` returns 200 with valid JSON when enabled, 404 when disabled
17. Integration: `GET /.well-known/security.txt` returns RFC 9116-compliant output
18. Integration: `GET /.well-known/ai-plugin.json` returns valid manifest

---

## Commands the developer will need to run after implementation

```
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
bin/magento mageos:seo:ucp:keygen --website=1
```

Run `keygen` once per website after the module is live. The profile will be incomplete (missing `signing_keys`) until keygen is run.

---

## Open questions before implementation

1. **Capability enablement timing** — when does Phase 2 (live endpoints) target? The manifest can be served now with all capabilities disabled (`enabled: true` on `dev.ucp.shopping` transport only), and capabilities toggled on as their endpoints are built.

2. **Merchant ID convention** — Google recommends a reverse-domain format (`com.example.uk`). Should the CLI auto-generate this from the store domain, or should it be manually entered in config? Auto-generation is more convenient but may need adjustment for subdomain-based stores.

3. **Multiple signing keys** — during key rotation there should be two keys in the manifest simultaneously. Should the CLI command support a rotation mode (generate new key, keep old) or is single-key management sufficient for now?

4. **`security.txt` expiry reminder** — the Expires field going stale is a common misconfiguration. Should the module emit an admin notification (via `Magento\AdminNotification`) when the expiry is within 60 days?

5. **`ai-plugin.json` vs UCP** — the `ai-plugin.json` format predates UCP and is increasingly superseded by it for Google agents. Is it still a required output or can it be deprioritised relative to the UCP profile?
