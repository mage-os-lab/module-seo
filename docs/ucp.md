# UCP Profile (`/.well-known/ucp`)

The module serves a [Universal Commerce Protocol](https://ucp.dev/) **business profile** at
`/.well-known/ucp`, following **UCP 2026-08-25**. The profile is how an AI shopping agent discovers
which UCP services and capabilities a store supports, and where to reach them.

This module serves the **discovery document only**. It implements no UCP service. So the profile
declares only what another installed module **registers**, and with nothing registered it declares
nothing. That is deliberate: a profile that claims checkout, cart or catalogue support the store
does not have sends agents to endpoints that don't exist.

---

## What is served

Out of the box, enabled:

```json
{
  "ucp": {
    "version": "2026-08-25",
    "services": {},
    "capabilities": {},
    "payment_handlers": {}
  }
}
```

With a signing key generated, and a module that registers the shopping service's REST binding and
the checkout capability:

```json
{
  "ucp": {
    "version": "2026-08-25",
    "services": {
      "dev.ucp.shopping": [
        {
          "version": "2026-08-25",
          "spec": "https://ucp.dev/2026-08-25/specification/overview/",
          "transport": "rest",
          "endpoint": "https://shop.example.com/ucp/v1",
          "schema": "https://ucp.dev/2026-08-25/services/shopping/rest.openapi.json"
        }
      ]
    },
    "capabilities": {
      "dev.ucp.shopping.checkout": [
        {
          "version": "2026-08-25",
          "spec": "https://ucp.dev/2026-08-25/specification/shopping/checkout",
          "schema": "https://ucp.dev/2026-08-25/schemas/shopping/checkout.json"
        }
      ]
    },
    "payment_handlers": {}
  },
  "keys": [
    { "kty": "EC", "crv": "P-256", "use": "sig", "kid": "ucp-key-2026-09", "x": "…", "y": "…" }
  ]
}
```

- `services`, `capabilities` and `payment_handlers` are always present, as JSON objects. Each maps
  a reverse-domain name to a **list** of entries: one per transport binding for a service, and one
  per version for a capability.
- `payment_handlers` has no registration point yet, so it is always `{}`. UCP requires the member.
- `keys` appears only when a public signing key is stored (see [Signing keys](#signing-keys)).

---

## Enabling it

**Stores → Configuration → MageOS SEO → SEO Agentic Commerce (UCP) → UCP Profile → Enable UCP Profile**
(`mageos_seo_ucp/general/enabled`, per website, default No). When it's disabled, the URL answers
404.

UCP places requirements on how the profile is served:

- **HTTPS.** UCP requires every published artifact to be served over HTTPS. Serve the store
  over HTTPS in production.
- **No redirect.** A plain `GET /.well-known/ucp` answers 200 directly. The module 301s only
  variants of it: a query string, or the internal `/mageos-seo/wellknown/index` URL. Don't add a
  web-server or CDN redirect in front of the canonical URL, such as `http → https`, `www`, or a
  trailing slash. Agents are required not to follow one.
- **Cacheable.** The response carries `Cache-Control: public, max-age=300`. UCP requires `public`
  and a `max-age` of at least 60, and forbids `private`, `no-store` and `no-cache`. No session is
  started for the request, so no session cookie or `Pragma: no-cache` is added.

---

## Declaring a service or capability

A module that really implements UCP registers providers in its **own** `di.xml`. Nothing in
MageOS_Seo is edited.

### A capability

```php
namespace Vendor\UcpCheckout\Model;

use MageOS\Seo\Api\UcpCapabilityProviderInterface;

class CheckoutCapability implements UcpCapabilityProviderInterface
{
    public function getCapabilityKey(): string
    {
        return 'dev.ucp.shopping.checkout';
    }

    public function isEnabled(): bool
    {
        return true; // e.g. read your module's own "checkout is live" setting
    }

    public function getCapabilityData(): array
    {
        return [
            'version' => '2026-08-25',
            'spec'    => 'https://ucp.dev/2026-08-25/specification/shopping/checkout',
            'schema'  => 'https://ucp.dev/2026-08-25/schemas/shopping/checkout.json',
        ];
    }
}
```

```xml
<type name="MageOS\Seo\Model\Ucp\CapabilityPool">
    <arguments>
        <argument name="providers" xsi:type="array">
            <item name="checkout" xsi:type="object">Vendor\UcpCheckout\Model\CheckoutCapability</item>
        </argument>
    </arguments>
</type>
```

`getCapabilityData()` returns **one** entry. Entries from several providers with the same key are
listed together under it, for example one provider per supported version.

### A service binding

`Api\UcpServiceProviderInterface` has the same shape: `getServiceKey()`, `isEnabled()` and
`getServiceData()`. Register **one provider per transport binding** in
`MageOS\Seo\Model\Ucp\ServicePool`'s `providers` argument.

```php
public function getServiceData(): array
{
    return [
        'version'   => '2026-08-25',
        'spec'      => 'https://ucp.dev/2026-08-25/specification/overview/',
        'transport' => 'rest',
        'endpoint'  => $this->storeManager->getStore()->getBaseUrl() . 'ucp/v1',
        'schema'    => 'https://ucp.dev/2026-08-25/services/shopping/rest.openapi.json',
    ];
}
```

### What an entry must satisfy

Every entry is checked against the UCP 2026-08-25 business schema by `Model\Ucp\EntryValidator`.

| Rule | Service | Capability |
|---|---|---|
| Name is a reverse-domain name (`dev.ucp.shopping`, `com.example-shop.loyalty`) | ✓ | ✓ |
| `version` is a `YYYY-MM-DD` date | required | required |
| `schema` is an absolute URL | optional | **required** |
| `transport` is `rest`, `mcp`, `a2a` or `embedded` | required | — |
| `endpoint` is an absolute URL | required for rest, mcp and a2a | — |
| `spec` is an absolute URL | optional | optional |
| `extends` is a name, or a non-empty list of names | — | optional |
| `id` is a string; `config` is a non-empty object | optional | optional |

URLs must be absolute `http` or `https` URLs. UCP expects HTTPS, and `http` is accepted only so a
development store can declare its own endpoints.

**Namespace binding.** When an entry has a `schema` URL, the URL's host, reversed, must equal the
entry's name or be a label-aligned prefix of it. For example, `dev.ucp.shopping.checkout` passes
with a schema on `ucp.dev` or `shopping.ucp.dev`, and `com.example.pay` fails with one on
`evil.example`. Platforms reject an entry that fails this binding. `dev.ucp.*` names are reserved
for capabilities the UCP governing body sanctions, so your own capabilities belong under your own
domain.

**An entry that fails is left out, and logged.** The profile stays valid, and `var/log/system.log`
names the entry, the provider class and every reason:

```text
MageOS_Seo: UCP capability "dev.ucp.shopping.checkout" from Vendor\UcpCheckout\Model\CheckoutCapability left out of /.well-known/ucp: schema is required.
```

If your entry is missing from the profile, check that log first.

---

## Signing keys

UCP uses the business's public keys to verify its signed webhooks and messages. Generate a keypair
per website:

```bash
bin/magento mageos:seo:ucp:keygen --website=1
bin/magento cache:flush config
```

The command generates an ECDSA P-256 keypair. It stores the private key **encrypted**, and stores
and prints the public JWK. The profile publishes the public key in `keys`, which is a JWK Set.

- **A stored key carrying private material** (any of `d`, `p`, `q`, `dp`, `dq`, `qi`, `oth` or
  `k`) makes the endpoint answer 500 and log the error. The profile is never served with it.
- **A stored key the schema would reject** is left out and logged, and the rest of the profile is
  served. Examples: unparseable JSON; no `kid` or `kty`; an EC key without `crv`, `x` and `y`; an
  `alg` that does not match the curve. Run the keygen command again to replace it.

---

## Compatibility

- The profile follows **UCP 2026-08-25**, the latest release as of September 2026. It changed two
  things from 2026-04-08: signing keys moved from `signing_keys` to `keys`, and a capability's
  `schema` became required.
- Google's UCP guide
  ([developers.google.com/merchant/ucp](https://developers.google.com/merchant/ucp/guides/ucp-profile))
  documents implementations up to 2026-04-08.
- UCP lets a business link profiles for older versions through `ucp.supported_versions`. This
  module does not publish one.

## Sources

- [UCP overview, 2026-08-25](https://ucp.dev/2026-08-25/specification/overview/): profile
  structure, hosting rules, namespace governance.
- [UCP schemas at the `v2026-08-25` tag](https://github.com/Universal-Commerce-Protocol/ucp/tree/v2026-08-25/source/schemas):
  `profile.json`, `ucp.json`, `service.json`, `capability.json`, `payment_handler.json`.
