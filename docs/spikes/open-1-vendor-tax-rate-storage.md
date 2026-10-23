# OPEN-1 spike: where vendor tax rates live

**Date:** 2026-10-23 · **Ticket:** Groundwork 5 (ROAD-5) · **Rules:** OPEN-1, TAX-5 to TAX-8, TAX-12, DATA-2
**Environment:** WordPress 7.1.3, WooCommerce 11.2.1 with HPOS, PHP 8.4, MariaDB 11.4 (the Docker test environment, GW-5)
**Status:** Decided: option (a), approved by the product owner and recorded in master v2.5 (TAX-5, TAX-8, TAX-12, DATA-2, G-DB-4). This report is the evidence; the rules live in the master document. The CI guard is `tests/Integration/Tax/UnregisteredTaxClassLookupTest.php`.

## The question

OPEN-1 compares two ways to store a vendor's own tax rates when store tax is on:

- **(a)** rows in WooCommerce's `woocommerce_tax_rates`, under a per-vendor class (`mercatus-v{vendor}`) that is never registered as a WooCommerce tax class;
- **(b)** rows in a Mercatus table with IDs from a reserved high range (here 1,000,000,001 and up).

Choose the option that passes every check with fewer WooCommerce workarounds.

## Setup

A throwaway spike, run as integration tests in the Docker environment and never committed. A Karnataka customer buys one item from a Karnataka vendor and one from a Maharashtra vendor. The marketplace's own WooCommerce rate is 12%, so any missed vendor rate shows up as 12%. Each vendor has GST rates: CGST 9% and SGST 9% inside its own state, and IGST 18% elsewhere. Expected tax: CGST 90 and SGST 90 from the Karnataka vendor, and IGST 180 from the Maharashtra vendor. Both price modes were run: 1,000 excluding tax and 1,180 including tax.

Three variants ran: (a), (b) with the workarounds it needs, and **b-raw**, which is (b) without those workarounds.

## Results

| Check | (a) | (b) | b-raw |
|---|---|---|---|
| Cart totals | ✅ | ✅ | ❌ every tax line merged into one "Tax" line |
| Parent order tax lines | ✅ | ✅ | ❌ label "Tax", 0%, no rate code |
| Child order tax lines (split copies line taxes, TAX-5) | ✅ | ✅ | ❌ same |
| Partial refund | ✅ | ✅ | ❌ same, on the refund's tax lines |
| WooCommerce Analytics tax report | ✅ | ✅ | ❌ one unnamed 0% row |
| Editing a pending multi-vendor parent | ✅ | ✅ | ❌ same as parent |
| TAX-8 safety net | ✅ | ✅ | ❌ same as parent |
| Shop and product page prices match the cart | ✅ | ✅ | ✅ |

Each check ran in both price modes: 51 tests, and every (a) and (b) case passed. In all variants the tax *amounts* were right; what b-raw loses is the rate data stored on each tax line.

## What each option needs

**(a) needs one workaround.**
- Mercatus writes the vendor rows itself and then invalidates WooCommerce's `taxes` cache group. WooCommerce's own `WC_Tax::_insert_tax_rate()` silently turns an unregistered class into the *standard* class, which would make a vendor's rate apply to the whole marketplace.
- Lookups go through `WC_Tax::find_rates()`, which accepts the unregistered class. So WooCommerce's own matching (country, state, postcode ranges and wildcards, city, priorities, compound rates) applies unchanged. Order tax lines, refunds and the Analytics report read the real rows.
- OPEN-1's CI guard is written and passes: lookups accept unregistered classes, while the insert API rewrites them.

**(b) needs four hooks and a rebuilt matcher.**
- Its own location matcher. The spike covers country and state only; WooCommerce's postcode ranges, wildcards and city matching would all have to be rebuilt.
- `woocommerce_rate_label`, `woocommerce_rate_code` and `woocommerce_rate_compound` filters for the reserved IDs.
- `woocommerce_before_order_item_object_save`, to fix `rate_percent` on every tax line. `WC_Tax::get_rate_percent_value()` reads the table with no filter, and WooCommerce 11's batched `TaxRateDataStore` isn't filterable either. The Analytics report reads the stored percent and rate code directly, so these fixes have to be stored, not only displayed.

## Findings that apply to either option

1. **The cart hook isn't enough (TAX-5).** WooCommerce also picks rates in two places the cart hook never reaches:
   - Order recalculation: an edited pending parent, or a child recalculation. WooCommerce uses the item's own class there, so Mercatus re-applies vendor rates in `woocommerce_order_item_after_calculate_taxes`.
   - Shop and product page prices: `wc_get_price_including_tax()` and `wc_get_price_excluding_tax()`. Without `woocommerce_get_price_including_tax` and `woocommerce_get_price_excluding_tax`, a vendor product showed 1,120 (the marketplace's 12%) where the cart charged 1,180.

   All three hook points should use the same `TaxRateResolver`.
2. **Never hard-delete a rate an order may reference.** WooCommerce rebuilds tax lines from the rate row whenever `update_taxes()` runs: on recalculation, on refunds, and when a child order is built. In the spike, deleting a vendor's rows and rebuilding in a later request lost the "CGST" label. Revoked or replaced rates should move out of use (under (a), to an archive class) and stay readable. Approval (TAX-3) can work the same way: under (a), pending rates under a pending class become live when the class changes.
3. **TAX-8's "changed" means changed since the taxes were written.** The safety net has to compare the items and tax address with a fingerprint saved when the taxes were last set (at the split), not when the recalculation starts. With that, both scenarios passed under (a) and (b). Unchanged order: the taxes come back even though the rates no longer resolve. Changed items with unresolvable rates: the old tax is kept and an order note is added.
4. **Unregistered classes never reach products or order items.** WooCommerce drops an unknown class on products and rejects it on order items. The per-vendor class is only ever a lookup key, which is what both options assume.

## Recommendation

**Option (a).** It passes every check with one workaround (writing its own rows), where (b) needs four hooks plus a rebuilt location matcher. It also keeps vendor rates in exactly WooCommerce's shape and matching, which TAX-12 asks for.

The costs of (a), all manageable:
- **Direct writes to a WooCommerce-owned table.** G-DB-4 should name this as the one sanctioned exception.
- **Vendor rows are visible through the WooCommerce REST API** (`GET /wc/v3/taxes`), which only administrators can use. They don't appear on WooCommerce's tax settings screens, which list registered classes only.
- **Every vendor rate edit invalidates WooCommerce's whole `taxes` cache group.** Vendors edit rates rarely.

## Master changes (v2.5)

- **TAX-12:**
  - Vendor rates are rows in `woocommerce_tax_rates` under per-vendor classes that are never registered.
  - Mercatus writes and archives these rows itself and invalidates the `taxes` cache. Rates an order may reference are archived, never deleted. Approval changes a row's class.
  - A CI test fails if WooCommerce starts rejecting unregistered classes in rate lookups.
- **TAX-5:** rates are chosen through the same resolver at three points: the cart, order recalculation and price display.
- **TAX-8:** "changed" is measured against a fingerprint saved when the taxes were written.
- **DATA-2:** no Mercatus table for vendor tax rates.
- **G-DB-4:** allow vendor tax-rate rows in `woocommerce_tax_rates` per TAX-12.
- **OPEN-1:** closed.
