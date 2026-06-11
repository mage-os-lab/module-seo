# Planned Feature: Merchant Policies Schema

**Status:** Planned — not yet implemented  
**Module:** `MageOS_Seo` (additions to existing product schema builders)  
**Complexity:** Medium  
**Priority driver:** Four schema.org properties on the product `Offer` node that Google uses for Merchant Center free listings, Shopping rich results, and AI answer engine trust signals. Without these, products are ineligible for free product listings and miss return/shipping badges in search results.

---

## The four signals

| Schema property | schema.org type | What it does |
| --- | --- | --- |
| `hasMerchantReturnPolicy` | `MerchantReturnPolicy` | Return window, method, fees, refund type. Google shows "30-day returns" badge in Shopping results. Required for Google Merchant Center free listings. |
| `shippingDetails` | `OfferShippingDetails` | Shipping cost, destination region, handling + transit times. Google shows "Free shipping" / "Arrives in 2–5 days" in Shopping results. |
| `priceValidUntil` | date string on `Offer` | Date until which the listed price is guaranteed. Google requires this for free product listing eligibility — without it, products may be excluded from free Shopping listings. |
| `itemCondition` | URL (schema.org enum) | New / Refurbished / Used / Damaged. Required signal for marketplaces. Defaults to `NewCondition` for a new-goods store. |

All four are added to the `Offer` node inside the product's JSON-LD schema, which is built by `AbstractBuilder::buildBase()`.

---

## Architecture

A new `MerchantPolicyProvider` service reads config and constructs the policy data. It is injected into `AbstractBuilder` via the constructor. `buildBase()` calls `MerchantPolicyProvider::getOfferAdditions(int $storeId): array` and merges the result into the `Offer` node.

This keeps the schema builders unaware of the policy specifics, and allows bridge modules to override the provider via a `MerchantPolicyProviderInterface` preference binding.

```text
MerchantPolicyProviderInterface
  └── MerchantPolicyProvider (default implementation)
        ├── Reads return policy config → builds MerchantReturnPolicy array
        ├── Reads shipping config → builds OfferShippingDetails array(s)
        ├── Resolves priceValidUntil (product attribute → global fallback)
        └── Resolves itemCondition (product attribute → global default)
```

`AbstractBuilder::buildBase()` receives the current `ProductInterface` and calls:

```php
$policyAdditions = $this->merchantPolicyProvider->getOfferAdditions($product, $storeId);
$offer = array_merge($offer, $policyAdditions);
```

`getOfferAdditions()` returns an array that may contain any combination of the four fields — it omits fields that are not configured, so partially-configured stores still produce valid schema.

---

## Feature 1: hasMerchantReturnPolicy

### Schema output

```json
"hasMerchantReturnPolicy": {
  "@type": "MerchantReturnPolicy",
  "@id": "https://uk.example.com/#merchant-return-policy",
  "applicableCountry": "GB",
  "returnPolicyCategory": "https://schema.org/MerchantReturnFiniteReturnWindow",
  "merchantReturnDays": 30,
  "returnMethod": "https://schema.org/ReturnByMail",
  "returnFees": "https://schema.org/FreeReturn",
  "refundType": "https://schema.org/FullRefund",
  "merchantReturnLink": "https://uk.example.com/returns-policy"
}
```

The `@id` (`{storeBaseUrl}/#merchant-return-policy`) anchors the policy as a named entity. Future optimisation: define the full node once in the `OrganizationProvider` / `LocalBusinessProvider` output and reference it only by `@id` in product pages. For Phase 1, inline the full object — correct and simpler.

### Config fields (return policy group)

| Field | Path | Type | Scope | Default |
| --- | --- | --- | --- | --- |
| Enable Return Policy Schema | `mageos_seo_merchant/return/enabled` | Yes/No | Website | 0 |
| Applicable Country | `mageos_seo_merchant/return/applicable_country` | Text | Website | `GB` |
| Return Policy Category | `mageos_seo_merchant/return/policy_category` | Select | Website | `MerchantReturnFiniteReturnWindow` |
| Return Window (days) | `mageos_seo_merchant/return/days` | Integer | Website | `30` |
| Return Method | `mageos_seo_merchant/return/method` | Select | Website | `ReturnByMail` |
| Return Label Source | `mageos_seo_merchant/return/label_source` | Select | Website | `ReturnLabelCustomerResponsibility` |
| Return Fees | `mageos_seo_merchant/return/fees` | Select | Website | `FreeReturn` |
| Return Shipping Fees Amount | `mageos_seo_merchant/return/shipping_fees_amount` | Decimal | Website | `` |
| Refund Type | `mageos_seo_merchant/return/refund_type` | Select | Website | `FullRefund` |
| Returns Policy URL | `mageos_seo_merchant/return/policy_url` | Text | Website | `` |

Config scope: **website** — return policy is the same for all stores on a domain.

**Return Shipping Fees Amount** is only emitted when `returnFees = ReturnFeesCustomerResponsibility` and a non-zero value is set, as `returnShippingFeesAmount` (`MonetaryAmount` using the store currency).

Source models needed for the five select fields:

| Source model | Values |
| --- | --- |
| `Model/Config/Source/ReturnPolicyCategory.php` | Finite window / Unlimited / Not permitted / Unspecified |
| `Model/Config/Source/ReturnMethod.php` | By mail / In store / At kiosk |
| `Model/Config/Source/ReturnLabelSource.php` | In box / Download / Customer responsibility |
| `Model/Config/Source/ReturnFees.php` | Free return / Customer responsibility / Original shipping fees |
| `Model/Config/Source/RefundType.php` | Full refund / Exchange / Store credit |

All source models map admin-readable labels to the schema.org URL enum values (e.g., `FreeReturn` → `https://schema.org/FreeReturn`).

**Phase 2 granularity:** The spec also supports separate fee/label policies for customer-remorse returns (`customerRemorseReturnFees`, `customerRemorseReturnLabelSource`, `customerRemorseReturnShippingFeesAmount`) vs. defective-item returns (`itemDefectReturnFees`, `itemDefectReturnLabelSource`, `itemDefectReturnShippingFeesAmount`). Phase 1 uses the general `returnFees` / `returnLabelSource` which applies to all return reasons. Add the per-reason fields in Phase 2 if needed.

---

## Feature 2: shippingDetails

### Shipping schema output

A standard "free UK shipping, 2–5 days" example:

```json
"shippingDetails": {
  "@type": "OfferShippingDetails",
  "shippingLabel": "Standard UK Delivery",
  "shippingRate": {
    "@type": "MonetaryAmount",
    "value": "0.00",
    "currency": "GBP"
  },
  "shippingDestination": {
    "@type": "DefinedRegion",
    "addressCountry": "GB"
  },
  "deliveryTime": {
    "@type": "ShippingDeliveryTime",
    "handlingTime": {
      "@type": "QuantitativeValue",
      "minValue": 0,
      "maxValue": 1,
      "unitCode": "DAY"
    },
    "transitTime": {
      "@type": "QuantitativeValue",
      "minValue": 2,
      "maxValue": 5,
      "unitCode": "DAY"
    }
  }
}
```

Multiple shipping zones are supported — output an array of `OfferShippingDetails` objects when more than one zone is configured (e.g., UK free + EU paid):

```json
"shippingDetails": [
  { ... UK zone ... },
  { ... EU zone ... }
]
```

### Data model: shipping zones

Rather than a flat config, shipping is modelled as an ordered list of zones. Two zones cover the majority of stores: domestic and international. The admin UI uses a dynamic rows component (same pattern used in Magento's checkout shipping methods config).

Store in a single JSON config value at `mageos_seo_merchant/shipping/zones` (serialised array). Default: empty (no shipping schema emitted until configured).

Each zone:

```json
{
  "label": "Standard UK Delivery",
  "destination_country": "GB",
  "rate_value": "0.00",
  "rate_currency": "GBP",
  "handling_min_days": 0,
  "handling_max_days": 1,
  "transit_min_days": 2,
  "transit_max_days": 5
}
```

### Config fields (shipping group)

| Field | Path | Type | Scope | Default |
| --- | --- | --- | --- | --- |
| Enable Shipping Details Schema | `mageos_seo_merchant/shipping/enabled` | Yes/No | Website | 0 |
| Shipping Zones | `mageos_seo_merchant/shipping/zones` | Dynamic rows | Website | `[]` |

Dynamic rows columns: Label, Destination Country (text, ISO 3166-1), Shipping Rate, Currency, Handling Min, Handling Max, Transit Min, Transit Max.

### Extensibility

`ShippingDetailsProviderInterface` allows bridge modules (e.g., a real shipping rules module) to override the config-based shipping data with live-calculated values:

```php
interface ShippingDetailsProviderInterface
{
    /**
     * Return shipping detail arrays for this product, or [] to use config-based zones.
     *
     * @return array<int, array>
     */
    public function getShippingDetails(ProductInterface $product, int $storeId): array;
}
```

If a provider returns a non-empty array, it takes precedence over the config zones. Default implementation returns `[]` (falls through to config).

---

## Feature 3: priceValidUntil

### Logic

`priceValidUntil` on an `Offer` tells Google until when the listed price is guaranteed. Google Merchant Center requires this for products with promotional or special prices. Without it on any product with a special price, the product may lose free listing eligibility.

Resolution order (most specific to most general):

1. If the product has an active special price with a `special_price_to_date` set: use that date.
2. If the product has a special price but no end date: do not emit `priceValidUntil` (an infinite special price is fine without a date).
3. If the product has no special price: use the **global fallback date** from config (if set).
4. If no global fallback is configured: omit `priceValidUntil`.

### Config field

| Field | Path | Type | Scope | Default |
| --- | --- | --- | --- | --- |
| Default Price Valid Until | `mageos_seo_merchant/pricing/price_valid_until` | Date | Store | `` |

Recommendation: set this to end of the current year and update annually. Remind admin via an admin notification (low priority — future enhancement) when the date is within 30 days.

### Output format

ISO 8601 date string: `"2026-12-31"`. Do not include time component.

```json
"priceValidUntil": "2026-12-31"
```

---

## Feature 4: itemCondition

### itemCondition resolution

`itemCondition` signals whether the item is new, refurbished, used, or damaged. For an artisan/handmade marketplace, everything is typically `NewCondition`, but the field should be configurable.

Resolution order:

1. If the product has a custom attribute mapped to `itemCondition` (configurable in admin): use that attribute's value (mapped to the schema.org URL enum).
2. Otherwise: use the **global default** from config.

### Config fields

| Field | Path | Type | Scope | Default |
| --- | --- | --- | --- | --- |
| Default Item Condition | `mageos_seo_merchant/condition/default_condition` | Select | Store | `NewCondition` |
| Product Attribute for Condition | `mageos_seo_merchant/condition/product_attribute` | Select (attribute list) | Store | `` |

The "Product Attribute for Condition" field lists all product attributes with a `Select` frontend input. If configured, its values must map to schema.org condition enum values; the admin UI should show a mapping table (future enhancement — for Phase 1, just configure the global default).

Source model: `Model/Config/Source/ItemCondition.php` with values `NewCondition`, `RefurbishedCondition`, `UsedCondition`, `DamagedCondition`.

### Output

```json
"itemCondition": "https://schema.org/NewCondition"
```

---

## Integration into AbstractBuilder

`AbstractBuilder` gains a new constructor dependency: `MerchantPolicyProviderInterface`.

In `buildBase()`, after the `Offer` array is constructed:

```php
$offer = [
    '@type'         => 'Offer',
    'priceCurrency' => $currency,
    'price'         => $price,
    'availability'  => $availability,
    'url'           => $url,
];

$policyAdditions = $this->merchantPolicyProvider->getOfferAdditions($product, $storeId);
if (!empty($policyAdditions)) {
    $offer = array_merge($offer, $policyAdditions);
}
```

`getOfferAdditions()` returns only fields that are enabled and configured — no null fields emitted.

---

## New files to create

| File | Purpose |
| --- | --- |
| `Api/MerchantPolicyProviderInterface.php` | Contract for offer policy additions |
| `Model/MerchantPolicy/MerchantPolicyProvider.php` | Default implementation reading from config |
| `Model/MerchantPolicy/ReturnPolicyBuilder.php` | Builds `MerchantReturnPolicy` array from config |
| `Model/MerchantPolicy/ShippingDetailsBuilder.php` | Builds `OfferShippingDetails` array(s) from config |
| `Api/ShippingDetailsProviderInterface.php` | Extensibility hook for real shipping data |
| `Model/MerchantPolicy/NullShippingDetailsProvider.php` | Default no-op implementation (returns `[]`) |
| `Model/Config/Source/ReturnPolicyCategory.php` | Admin select source |
| `Model/Config/Source/ReturnMethod.php` | Admin select source |
| `Model/Config/Source/ReturnFees.php` | Admin select source |
| `Model/Config/Source/RefundType.php` | Admin select source |
| `Model/Config/Source/ItemCondition.php` | Admin select source |

### Files to modify

| File | Change |
| --- | --- |
| `etc/adminhtml/system.xml` | Add new `merchant` section with `return`, `shipping`, `pricing`, `condition` groups |
| `etc/config.xml` | Defaults for all new config paths |
| `etc/di.xml` | Wire `MerchantPolicyProviderInterface` preference + `ShippingDetailsProviderInterface` preference |
| `Model/Config.php` | Add getters for all new merchant policy config paths |
| `Model/Product/Builder/AbstractBuilder.php` | Inject `MerchantPolicyProviderInterface`, call in `buildBase()` |

---

## Config section structure

New top-level section in `system.xml`: **Merchant Policies** (or add as a new group under the existing `mageos_seo_general` if a separate section feels excessive).

Recommendation: separate section `mageos_seo_merchant` — the merchant policy config is substantial and shouldn't compete for space with the SEO config. Admin menu path: **Stores → Configuration → MageOS → SEO Merchant Policies**.

---

## Implementation order

1. `Model/Config/Source/` — all five source model files (standalone, no dependencies)
2. `etc/adminhtml/system.xml` — add `mageos_seo_merchant` section with all four groups
3. `etc/config.xml` — defaults for all paths
4. `Model/Config.php` — add merchant policy getters
5. `Api/ShippingDetailsProviderInterface.php`
6. `Model/MerchantPolicy/NullShippingDetailsProvider.php`
7. `Api/MerchantPolicyProviderInterface.php`
8. `Model/MerchantPolicy/ReturnPolicyBuilder.php`
9. `Model/MerchantPolicy/ShippingDetailsBuilder.php`
10. `Model/MerchantPolicy/MerchantPolicyProvider.php`
11. `etc/di.xml` — wire interfaces to implementations
12. `Model/Product/Builder/AbstractBuilder.php` — inject provider, call in `buildBase()`
13. — **Tests** —
14. Unit: `ReturnPolicyBuilder` — disabled returns null, enabled returns correctly structured array with schema.org URL enum values
15. Unit: `ShippingDetailsBuilder` — empty zones config returns `[]`; single zone returns single object; multiple zones return array
16. Unit: `MerchantPolicyProvider::getOfferAdditions()` — only enabled features contribute to output
17. Unit: `priceValidUntil` resolution — special price date used when set; global fallback used when no special price; omitted when neither configured
18. Integration: product with all four policies enabled → verify offer node contains all four fields with correct structure
19. Integration: all policies disabled → offer node unchanged from current output

---

## Open questions before implementation

1. **Marketplace / per-seller return policies** — on a multi-vendor marketplace, different sellers may have different return policies. For Phase 1, a single store-wide policy is sufficient. Phase 2 could add a per-seller return policy override via a bridge module. Flag the hook point in `ShippingDetailsProviderInterface` for this future use.

2. **Shipping zone UI** — the dynamic rows admin component is the right pattern, but requires a `Magento\Config\Block\System\Config\Form\Field\FieldArray` implementation. Is that level of admin UX complexity wanted for Phase 1, or is a simpler two-field approach (domestic country + rate, second optional zone) acceptable initially?

3. **`priceValidUntil` on regular-priced products** — Google's recommendation is to set it even on non-promotional products (to a date roughly a year out). The global fallback config covers this, but it requires manual renewal. Should the module auto-compute a "today + 1 year" default if no date is configured, rather than omitting the field?

4. **Currency on `MonetaryAmount`** — `shippingRate.currency` should match the store's display currency. Confirm that `$store->getCurrentCurrencyCode()` returns the correct value in the context where `MerchantPolicyProvider` runs (it's called during schema rendering, which is FPC-cached per store view — should be correct).

5. **Multiple applicable countries on return policy** — `applicableCountry` accepts an array. For a UK + EU seller, should the admin allow multiple countries, or is a single country per policy sufficient for Phase 1?
