# Mercatus — Decision Record

**Status:** Frozen 2026-10-23. Any later change is an amendment: edit the item, then log its ID, the date and the reason in §12.
**Precedence:** this file governs `mercatus_master_architecture_and_testing_constitution.md`, which was aligned with it (through A5) on 2026-10-23. If they ever disagree, this file wins and the constitution is amended to match.

**Labels**
- **Decided**: an explicit product-owner call.
- **Default**: proposed in the design review and not objected to; frozen with the rest.

IDs are stable. The constitution, tickets and code reviews cite them (for example `TAX-2`) instead of restating the rule.

---

## 0. Principles

- **PRIN-1 (Decided)** Mercatus serves many kinds of marketplaces in many countries. Business rules that differ between business models (commission bases, who receives shipping and tax, and similar) are admin settings with sensible defaults, never a single hard-coded rule.
- **PRIN-2 (Decided)** A feature deferred from v1 gets an extension seam now (a policy, a contract, or a documented filter per ARCH-21), so it can be added later without rewriting the flow. Examples: TAX-11, TAX-12, COM-5.
- **PRIN-3 (Decided, A1)** This file governs the whole plugin. Before a major module is implemented, it gets its own decision file at `decisions/<module-id>.md` (for example `decisions/pro.shipment-tracking.md`). A module file cites this file's IDs instead of restating them and may not contradict it; a conflict is resolved by amending this file first. §13 indexes the module files.

## 1. Platform and caching

- **PLAT-1 (Decided)** Redis and Memcached are optional. Mercatus supports and optimises for them, but every feature works without them, using native WordPress optimisations.
- **PLAT-2 (Default)** One `CachePort`, backed only by `wp_cache_*` (including `wp_cache_get_multiple()` and `wp_cache_supports()`). Business code never checks whether Redis exists; only the `CachePort` and `CoordinationPort` adapters do, once at boot, through `wp_using_ext_object_cache()` (A5). No direct PhpRedis/Memcached client.
- **PLAT-3 (Default)** Without a persistent object cache, speed comes from the indexed `wp_mercatus_*` read tables. Transients are used only for a fixed, bounded set of summary values (for example filter counts per category), never per product or per user. No large autoloaded options. Public reads send `ETag` and `Cache-Control`.
- **PLAT-4 (Default)** Cache version pointers start from the current time, not 1, so an evicted pointer can never revive old entries. Pointers are created with `add` before `incr` (Memcached returns false on a missing key).
- **PLAT-5 (Default)** Outbox workers claim jobs with a lease (`UPDATE … SET claimed_by, lease_until … ORDER BY id LIMIT n`), not `FOR UPDATE SKIP LOCKED`.
- **PLAT-6 (Default)** Minimums stay MySQL 8.0 / MariaDB 10.6 (both end-of-life in 2026). CI also runs MySQL 8.4 LTS and MariaDB 10.11/11.4. Table collation comes from `$wpdb->get_charset_collate()`.
- **PLAT-7 (Default)** Performance gates run with and without Redis. Query counts (Mercatus queries only) are a hard gate; latency is reported as a trend. The scale suite runs nightly from a saved database dump.
- **PLAT-8 (Decided, A5)** Caching and coordination are separate. `CachePort` is best effort: losing it only costs speed. `CoordinationPort` provides atomic counters and locks that hold across requests, using `wp_cache_incr`/`wp_cache_add` when a persistent object cache is present. Without one, it reports counters and locks as unavailable, and best-effort users (rate limiting, stampede locks) switch off. Correctness never depends on it: idempotency (PLAT-9), the order split (unique key), the outbox (lease, PLAT-5) and ledger balances (optimistic `version` check) use the database directly. A database-backed implementation (`wp_mercatus_counters`, updated with `INSERT … ON DUPLICATE KEY UPDATE`) is built only when a feature first needs cross-request coordination without Redis (PRIN-2).
- **PLAT-9 (Decided, A5)** Idempotency always writes its database row first. The `wp_mercatus_idempotency` row is inserted with status `processing` before the command runs, so its unique key is the lock. A repeat of the same key returns the stored response if the first request finished, 409 if it is still processing, and 422 if the payload differs. No cache lock is used, with or without Redis.
- **PLAT-10 (Decided, A5)** Rate limiting needs a persistent object cache. With one, every route is rate-limited through `CoordinationPort` counters. Without one, Mercatus does no rate limiting, and the health check says so and points to page-cache, CDN or firewall limits. Limits are admin settings with defaults (Default, PRIN-1).
- **PLAT-11 (Decided, A5)** ETags come from the database, never from cache revision numbers. A single item's ETag is built from its `version` column; a list's ETag is a hash of the IDs and versions on that page, which are already fetched. `If-Match` checks the same `version`. With a persistent cache, a cached response stores its ETag, so a 304 needs no database query. Cache revision numbers (PLAT-4) only key cache entries.

## 2. v1 scope

- **SCOPE-1 (Decided)** Cut from v1: OAuth2/PKCE server, H3 (use geohash), direct Redis/Memcached client, legacy gateway bridge (use WooCommerce's order-pay URL), CDN purge by tag.
- **SCOPE-2 (Decided)** Mercatus owns vendor storefronts, the vendor dashboard and its own catalog/offer blocks. WooCommerce shop and category pages stay WooCommerce's. The `WP_Query` ban (constitution §1.2) applies only to Mercatus-owned pages and to list queries; reading one product through WooCommerce to edit it is allowed.

## 3. Commission

- **COM-1 (Decided)** Free has global flat, percent, and flat + percent commission. Free must be fully usable by the admin.
- **COM-2 (Decided)** Pro adds category, membership, vendor, product, cart-value and total-sales rules.
- **COM-3 (Default)** One commission engine in Core: the resolver plus the global rule. Pro modules plug in rule sources in a fixed priority order. The winning rule and its version are saved on each allocation at Stage 1. `commission_rules` moves from Pro to Core, and the "0% fee in Free" settlement default is removed.
- **COM-4 (Decided)** Free includes manual withdrawals. A vendor requests a withdrawal from their available balance; the admin approves it, pays outside Mercatus (bank transfer, UPI and so on) and marks it paid. Pro adds payout gateways, scheduled and automatic payouts, reverse withdrawals and reconciliation.
- **COM-5 (Decided)** The flat part of a commission applies once per vendor order by default. The filter `mercatus_commission_flat_basis( string $basis, int $vendor_id )` can switch it to `item_quantity` (per unit sold) or `order_line` (per product line); any other value falls back to `vendor_order`. The basis used is saved with the allocation's commission snapshot (COM-3). Default detail: the flat amount is spread across the vendor order's lines with the largest-remainder method (ARCH-17), so a partial refund reverses the matching share.
- **COM-6 (Decided)** Commission settings have two checkboxes: "Commission after considering vendor coupon?" and "Commission after considering admin coupon?". Ticked: that coupon type's discount is deducted first, and the commission and the vendor's earnings are calculated on the discounted price. Unticked: they are calculated on the price before that discount, so the marketplace absorbs it. Both start ticked (Default, as in WCFM).
- **COM-7 (Decided)** A "Shipping cost goes to vendor?" checkbox. Ticked: shipping charges on a vendor's items go to that vendor. Unticked: the marketplace keeps them. Starts ticked (Default, as in WCFM).
- **COM-8 (Decided)** With store tax on (TAX-9), the tax on a vendor's items always goes to that vendor. With store tax off, a "Tax goes to vendor?" checkbox decides; it starts ticked (Default, as in WCFM).
- **COM-9 (Default)** Shipping and tax are never commissioned.
- **COM-10 (Default)** COM-6 to COM-8 are global values resolved through the commission engine (COM-3), so Pro vendor, membership or category rules can override them later (PRIN-2).
- **COM-11 (Decided, A2)** Pro payment integrations move money to vendors, starting with Stripe and PayPal. Each provider offers two modes that the admin selects and configures: payout (the marketplace collects payment, then pays vendors through the provider) and split (the customer's payment is split to vendors at checkout). This mirrors WCFM's Stripe and Stripe Split Pay, and PayPal and Direct PayPal. They are built in Phase 5 with the finance modules, after Shipment Tracking (ORD-15).
- **COM-12 (Default, A2)** For each provider, both modes share one vendor-account connection, API client and webhook handling (with signature and replay checks); only the money flow differs. The ledger records both modes. Split mode uses the Stage 1 allocations (ORD-8), must reverse the vendor's share on refunds and cancellations (ORD-14), and must either handle Subscriptions renewal orders or refuse to run for them. WCFM's Stripe Split Pay renewal gap created phantom vendor earnings.
- **COM-13 (Decided, A3)** Earnings release runs on its own clock, separate from completion (ORD-17):
  - "Earnings become withdrawable N days after completion", default 7 (the common return window). Setting 0 gives WCFM's behaviour.
  - "Minimum withdrawal amount", default none.
  - Always: an open refund or return request freezes that vendor order's earnings until it is resolved.
  - Always: refunds after release are taken back from the vendor's balance, which may go negative (ARCH-18). Withdrawals are blocked while it is negative.
  - Pro: hold days per vendor or membership through the filter `mercatus_earnings_hold_days( int $days, int $vendor_id )` (ARCH-21), a rolling reserve for new or flagged vendors, and automatic payouts (COM-11) that follow the same release rule.
  - Known risk: with vendor completion on, the hold and the admin's approval of each withdrawal (COM-4) are the safeguards in Free. With Pro automatic payouts, the hold or delivery evidence (customer confirmation, OTP or carrier) is the only one.

## 4. Tax

- **TAX-1 (Decided)** Tax works for any country, the way WooCommerce does. When store tax is enabled (TAX-9), vendors manage their own rates from their settings page, in the same shape as WooCommerce's tax rate table (country, state, postcode, city, rate, name, priority, compound, shipping; per tax class).
- **TAX-2 (Decided)** WooCommerce does all tax maths. Mercatus only chooses which rates apply to each cart item, through `woocommerce_cart_totals_get_item_tax_rates`, from the vendor's rates and the item's location (the customer's address, or the vendor's pickup point for pickup items). Tax snapshots copy WooCommerce's computed line taxes, so the parent total always equals the sum of the vendor totals. `ManualTaxProvider` becomes a rate resolver.
- **TAX-3 (Decided)** In vendor-managed mode, prices entered including tax keep the same gross price for every customer (`woocommerce_adjust_non_base_location_prices` = false). Only the tax inside the price changes.
- **TAX-4 (Decided)** Recalculating a child order uses that order's vendor (`woocommerce_order_before_calculate_taxes` and `woocommerce_order_get_tax_location`). A multi-vendor parent is never recalculated after the split. Safety net: before any order recalculation, Mercatus saves the order's taxes. If neither the items nor the tax address changed, it puts them back afterwards. If rates can't be resolved, it keeps the old tax and adds an order note. Tax is never silently lost on recalculation (the pattern WooCommerce Tax uses).
- **TAX-5 (Decided)** Third-party tax plugins (WooCommerce Tax automated taxes, TaxJar, AvaTax) are not supported in v1, whether store tax is on or off. Mercatus ships no compatibility code for them. Automated tax providers may come later as Pro `TaxRateResolver` adapters (TAX-12).
- **TAX-6 (Decided)** Where vendor rates are stored is settled by Phase 0 spike SPIKE-1: (a) WooCommerce's tax table under a per-vendor tax class, or (b) Mercatus's own table with IDs from a reserved high range, labels fixed through `woocommerce_checkout_create_order_tax_item`.
- **TAX-7 (Default)** No matching rate means no tax, as in WooCommerce. The constitution's "fail checkout for unsupported combinations" rule is dropped; the health check and the test-quote tool warn instead.
- **TAX-8 (Default)** Each vendor's shipping tax is set by the Mercatus vendor shipping method on its own rates.
- **TAX-9 (Decided)** An "Enable store tax" checkbox in the marketplace tax settings decides whether Mercatus's per-vendor tax rules apply. Off: WooCommerce's own tax settings and rates apply to every item. On: vendor rates apply (TAX-1 to TAX-4). In both cases Mercatus splits and snapshots the computed taxes per vendor (TAX-2), because child orders need their tax lines either way. Changing the setting affects only new carts; existing orders keep their snapshots.
- **TAX-10 (Default)** The health check shows a notice when an unsupported tax plugin (TAX-5) is active, for example because its order sync would also report child orders.
- **TAX-11 (Decided)** Whether store tax applies is decided per vendor, in one place, so membership-level or vendor-level settings can be added later without touching the tax flow. v1 reads only the admin checkbox (TAX-9). How (Default): a Core `StoreTaxPolicy` answers "is store tax on for vendor X?", and its answer passes through the filter `mercatus_store_tax_enabled( bool $enabled, int $vendor_id )`. Rates are already chosen per cart item (TAX-2), so a cart mixing vendors with and without store tax needs no change to the calculation.
- **TAX-12 (Decided)** The tax flow must be able to take on third-party tax plugins or automated tax providers later through an adapter, without rewriting the flow. How (Default):
  - Rates are chosen behind one Core `TaxRateResolver` contract. v1 ships two resolvers: WooCommerce rates and vendor store rates. A resolver may return rates or per-line tax amounts, and it is called once per vendor (one origin, one seller), so an Avalara-style provider fits later.
  - Tax snapshots are read from the saved order's line taxes, not from Mercatus's own calculation, so they stay correct whoever calculated the tax.
  - Child orders are shown to or hidden from outside systems through one policy (ORD-10). Supporting a tax plugin's order sync later means adding one context there.
  - Integration adapters ship as modules registered through the module manifest (Pro `Integrations/`), never as patches to the tax code.
- **TAX-13 (Decided)** A "Vendor tax rates need approval" checkbox, starting ticked (Default). When ticked, a vendor's saved rates apply at checkout only after the admin approves them, and editing them needs approval again.
- **TAX-14 (Decided)** With store tax on, an admin setting decides what happens to a vendor without approved rates (without saved rates, if approval is off): use the marketplace's WooCommerce rates, charge no tax, or block selling until their rates are approved (their products can't be added to the cart). It starts on WooCommerce rates (Default).
- **TAX-15 (Default)** With store tax on, the vendor's ship-from address replaces the shop base address for tax. Prices entered including or excluding tax, tax rounding and tax display stay marketplace-wide, because WooCommerce applies them to the whole cart.

## 5. Orders and status

- **ORD-1 (Decided)** Each vendor gets a child `shop_order` under the customer's parent order (Dokan-style), created after payment.
- **ORD-2 (Decided)** The admin owns the parent status. Automation moves the parent only to the least-advanced stage among its active vendor orders, and the parent is completed only when all of them are. After payment the status is `processing` (`on-hold` for authorise-only payments).
- **ORD-3 (Default)** Money lives in the allocations; child orders are generated from them. A money change on a child goes through Mercatus, which records an adjustment and re-syncs the child.
- **ORD-4 (Default)** Refunds are requested on the child and paid out through the gateway on the parent. A WooCommerce refund is created on both; only the parent's counts in reports.
- **ORD-5 (Default)** Child orders are hidden from WooCommerce Analytics and the customer's My Account orders. WooCommerce customer emails are off for children; vendors get their own emails.
- **ORD-6 (Default)** Child orders never reduce or restore stock and never ask for payment. Item changes after payment are recorded as adjustments.
- **ORD-7 (Default)** The parent is the payment record; each child is that vendor's fulfilment record. This is documented for third-party integrations.
- **ORD-8 (Default)** Stage 1 allocation runs for every way an order is created: classic checkout, Store API, admin, REST and Subscriptions renewals.
- **ORD-9 (Default)** Child orders are created on payment complete, on COD, or for allow-listed authorise-only gateways. Never for a plain on-hold bank-transfer or cheque order.
- **ORD-10 (Default)** Child orders carry a documented marker (parent order ID and `created_via` = `mercatus`), and one `ChildOrderExposure` policy decides which outside systems see them. v1 contexts: WooCommerce Analytics, My Account orders and WooCommerce customer emails (ORD-5). New contexts, such as a tax plugin's order sync, are added there and nowhere else.
- **ORD-11 (Decided)** Every paid order is split, even when all items come from one vendor. The parent is always the payment record and each child is that vendor's order, so there is one code path.
- **ORD-12 (Decided)** Shipping progress is a separate fulfilment status, not a custom WooCommerce order status. Order status stays standard WooCommerce (processing → completed, plus cancelled and refunded). Each vendor order has a Mercatus fulfilment status: unfulfilled → partially shipped → shipped → delivered. The parent shows the least-advanced active vendor order (ORD-2). The model is shaped like WooCommerce's Fulfillments feature so it can be mirrored into it later.
- **ORD-13 (Decided)** Vendors can mark their orders shipped, delivered and completed. How orders complete is the admin's choice of model: an admin setting can require customer confirmation instead. Pro adds OTP delivery confirmation that completes the vendor order. The full set of models comes from RESEARCH-1. How (Default): completion triggers sit behind one `OrderCompletionPolicy`, so new models plug in without touching the order flow (PRIN-2); the admin can always complete an order.
- **ORD-14 (Decided)** Admin changes to the parent flow straight down. Completing the parent completes all its vendor orders. Cancelling the parent cancels everything and, if the order is paid, refunds the customer in full. Default details: the admin confirms first, and the confirmation lists any vendor orders already shipped. Vendor earnings are reversed with ledger adjustments (ORD-3), even if that takes a vendor balance negative (ARCH-18). The refund goes through the parent's gateway (ORD-4); if the gateway can't refund automatically, the order is flagged for a manual refund.
- **ORD-15 (Decided, A1)** A Pro Shipment Tracking module lets vendors (on their own vendor orders) and the admin (on any order) add and update shipping details: carrier, tracking number and tracking link. Customers see them in My Account and in emails. Its first version also includes bulk CSV upload of tracking details. It is the first Pro module, built right after the Pro licensing gate, ahead of the finance modules.
- **ORD-16 (Default, A1)** Core owns the shipment record: one package from one vendor order, with its items and quantities, status and dates (`wp_mercatus_order_fulfilments` plus its items), because ORD-12's fulfilment status needs it. The Pro module keeps tracking data in its own table keyed by shipment and never alters Core tables. Local delivery by agents, including OTP confirmation (ORD-13), stays in the Pro Delivery module and updates the same shipment records.
- **ORD-17 (Decided, A3)** Order completion triggers. Whichever enabled trigger fires first completes the vendor order:
  - "Vendors can mark orders completed", on by default (ORD-13).
  - "Customers can confirm receipt": an "Order received" button per vendor order in My Account, on by default.
  - Auto-complete N days after "delivered" (default 3) and, as a backup, N days after "shipped" if the order is never marked delivered (default 14). Setting N to 0 turns it off.
  - "Complete digital-only vendor orders on payment", on by default, as WooCommerce does for virtual, downloadable orders.
  - Always: the admin can complete any order, and an open refund or return request pauses the auto-complete timers.
  - Pro triggers plug in through `OrderCompletionPolicy`: OTP at delivery (Delivery module); carrier-confirmed delivery (Shipment Tracking with a tracking service), which marks the order delivered and so starts the timer; and bookings completing N hours after the booking starts.

## 6. Wallet

- **WAL-1 (Decided)** No wallet top-up or recharge.
- **WAL-2 (Decided)** The whole customer wallet is a Pro module; Free has no wallet. The constitution's Core wallet tables, endpoints and Phase 4 wallet work move to Pro.
- **WAL-3 (Default)** Core keeps the money ledger (accounts, entries, holds), because vendor earnings and manual withdrawals (COM-4) need it; it is a ledger, not a wallet. The Pro wallet adds customer accounts on the same ledger (DRY-2).
- **WAL-4 (Decided)** Refunds go to the original payment method by default. Wallet credit is offered, and used only if the customer accepts.
- **WAL-5 (Default)** Spending wallet credit counts as payment applied after tax, so it changes neither tax nor vendor earnings. The marketplace funds promotional credit through its own ledger account.

## 7. Architecture and code

- **ARCH-1 (Default)** Modules use `src/{Module}/{Domain,Application,Infrastructure,Api}`; Deptrac enforces the layers.
- **ARCH-2 (Decided)** PHPUnit is the test runner (no Pest), with Deptrac for architecture rules.
- **ARCH-3 (Default)** WPCS `WordPress-Extra` without the file-name and naming sniffs. Escaping, nonce, prepared-SQL and i18n sniffs stay on.
- **ARCH-4 (Default)** Database transactions wrap only `wp_mercatus_*` writes. WooCommerce writes happen outside them and are safe to retry.
- **ARCH-5 (Default)** Money changes happen synchronously in the command's transaction. The outbox carries side effects only; critical handlers run right after commit, with the poller as the retry net.
- **ARCH-6 (Default)** Another tenant's resource returns 404. A permission denied inside the actor's own tenant returns 403.
- **ARCH-7 (Default)** `POST /system/cache/flush-group` bumps the group's version. `GET /capabilities` is renamed `GET /features`.
- **ARCH-8 (Default)** Mercatus offers and inventory are the source of truth for price and stock; WooCommerce `_price` and `_stock` are copies. Vendors never get WooCommerce capabilities such as `edit_products`.
- **ARCH-9 (Default)** WooCommerce product and stock saves update the read tables, and a nightly job reconciles them. A vendor's own edit updates that product's row immediately.
- **ARCH-10 (Default)** Suspending a vendor or removing staff bumps their cached identity immediately. The identity cache has a short expiry.
- **ARCH-11 (Default)** Facet counts are precomputed per category, or approximate.
- **ARCH-12 (Default)** Third-party PHP libraries are prefixed (Strauss or PHP-Scoper). Pro builds against Core's contracts but does not ship them.
- **ARCH-13 (Default)** Secrets are encrypted with a dedicated `MERCATUS_ENCRYPTION_KEY` constant with key versions, never with a key derived from WordPress salts.
- **ARCH-14 (Default)** Vendor rich text is cleaned on save with a fixed allow-list and escaped on output. Uploads follow a MIME/SVG policy. Outbound requests use an allow-list. Dashboards send a Content-Security-Policy. GDPR export and erase are supported, and append-only tables hold no free-text personal data.
- **ARCH-15 (Default)** Every domain event is passed to `do_action()` after commit, from one bridge.
- **ARCH-16 (Default)** Domain code returns error codes; the API layer translates them.
- **ARCH-17 (Default)** Amounts round half-up per line. Remainders are split with the largest-remainder method. Currency decimals come from ISO 4217.
- **ARCH-18 (Default)** Schema additions: attribute values, categories (path, depth, leaf flag), allocation adjustments (refunds reduce vendor earnings in Free too), shipping zone locations. A vendor payable balance may go negative.
- **ARCH-19 (Default)** The test pyramid adds end-to-end and JS/UI tests. Test names are unified between the module matrix and the tickets.
- **ARCH-20 (Default)** The roadmap is built in vertical slices, and the scale seeder grows module by module.
- **ARCH-21 (Default)** Public WordPress filters are allowed only at documented policy decision points (for example `mercatus_store_tax_enabled`), named `mercatus_{subject}_{decision}`, and never over internal arrays (constitution §3, extension point 5).

## 8. DRY

- **DRY-1 (Decided)** The codebase follows DRY to stay compact and maintainable.
- **DRY-2 (Default)** One source of truth per fact. API shapes are defined once in PHP and generate the REST schemas, `openapi/mercatus-v1.yaml`, event schemas and TypeScript types; a contract test fails on drift. Migrations are the schema. One ledger holds all money. One typed settings registry handles defaults, sanitising, REST and admin forms.
- **DRY-3 (Default)** One shared-kernel implementation each for Money/Currency/Allocator, IDs, Clock, the state machine, error codes, cursors, cache keys and revisions, the projection runner, the repository base and the base REST controller.
- **DRY-4 (Default)** The IDOR/security test matrix is generated from the route registry, so new routes are covered automatically.
- **DRY-5 (Default)** Reuse WooCommerce where it already does the job: Store API for cart and checkout, `WC_Email`, the order-pay page, the shipping method API and the tax maths (TAX-2).
- **DRY-6 (Default)** Do not share DTOs between bounded contexts, or reach into Core internals from Pro, just to save lines. Extract shared code on the third repeat.
- **DRY-7 (Default)** Documents follow DRY too: the constitution states each rule once with an ID, and tickets and reviews cite the ID.

---

## 9. Questions (all answered 2026-10-23)

Kept as a log of where each answer is recorded.

- **Q1** Answered 2026-10-23: see COM-4.
- **Q2** Answered 2026-10-23: see COM-5.
- **Q3** Answered 2026-10-23: see COM-6 to COM-8.
- **Q4** Answered 2026-10-23: see TAX-13 to TAX-15.
- **Q5** Answered 2026-10-23: see WAL-2 to WAL-5.
- **Q6** Answered 2026-10-23: see ORD-11.
- **Q7** Answered 2026-10-23: see ORD-12 to ORD-14 and RESEARCH-1.

## 10. Phase 0 spikes

- **SPIKE-1: tax rate storage (TAX-6).** Build a cart with a Karnataka vendor, a Maharashtra vendor and a Karnataka customer, with store tax on and prices both including and excluding tax. Under options (a) and (b), check: cart totals, the parent's tax lines, the child orders' tax lines, a partial refund, the WooCommerce tax report, editing a pending multi-vendor parent, the TAX-4 safety net, and prices shown including tax on WooCommerce shop and product pages. That last one matters because `wc_get_price_including_tax()` reads the product's tax class and never goes through the cart hook, so displayed prices must match what the cart charges. Choose the option that passes every check with fewer WooCommerce workarounds. If (a) is chosen, add a CI test that fails if WooCommerce starts rejecting unregistered tax classes.
- **SPIKE-2: wallet partial payment.** Moved out of Phase 0 to the start of the Pro wallet module (WAL-2): apply wallet credit after tax as its own order line, and check taxes, reports and refunds.

## 11. Research

- **RESEARCH-1: order completion and earnings release.** Survey how popular marketplaces complete orders and release seller earnings: customer confirmation, delivery OTP or proof of delivery, auto-complete timers, return windows and payout hold periods. Turn the common models into admin-selectable completion options under ORD-13, recorded as an amendment. **Done 2026-10-23:** see ORD-17 and COM-13 (A3).
- **FOLLOW-1: reverse withdrawal for COD.** Not decided yet. When a vendor collects cash on delivery themselves, they owe the marketplace its commission (WCFM's "Reverse Withdrawal" setting). Negative vendor balances (ARCH-18) already support it.

## 12. Amendments

- **A1, 2026-10-23.** Added ORD-15 and ORD-16 (Pro Shipment Tracking as the first Pro module, with the Core shipment record it builds on) and PRIN-3 (per-module decision files). Reason: shipment tracking was missing from the original review, and the module-level decision process was agreed. This changes the constitution's Pro build order (§11, Phases 5 and 6).
- **A2, 2026-10-23.** Added COM-11 and COM-12 (Stripe and PayPal integrations with payout and split modes, in Phase 5). Reason: vendor payment integrations were raised alongside shipment tracking.
- **A3, 2026-10-23.** Added ORD-17 (order completion triggers) and COM-13 (earnings release on its own clock), with defaults. Reason: the RESEARCH-1 survey of 11 marketplaces found that order completion and seller payout run on separate clocks almost everywhere.
- **A4, 2026-10-23 (editorial).** TAX-5 now names the `TaxRateResolver` contract defined in TAX-12 instead of a separate `TaxProvider` contract. No decision changed. Reason: wording mismatch found while aligning the constitution, which was rewritten to match this file on the same day (original archived at `archive/constitution-2026-10-22-original.md`).
- **A5, 2026-10-23.** Updated PLAT-2 and added PLAT-8 to PLAT-11: coordination separated from caching, idempotency writes its row first, rate limiting only with a persistent object cache, and ETags from database versions. Reason: three features silently broke without Redis. Rate limiting never triggered, idempotency's in-flight lock didn't hold across requests, and ETags tied to cache revisions changed on every request, so every `If-Match` would have failed with 409.

## 13. Module decision files

| Module ID | File | Status | Starting points from the master review |
|---|---|---|---|
| `pro.shipment-tracking` | `decisions/pro.shipment-tracking.md` | Not created | Admin carrier list with tracking-URL templates; "Require tracking number to mark shipped" and "Vendors can add custom carriers" settings (PRIN-1); one customer email per shipment with its tracking link; tracking grouped by vendor in My Account; mirror into WooCommerce Fulfillments when that feature is on (ORD-12). |
| `pro.payment-integrations` | `decisions/pro.payment-integrations.md` | Not created | Module boundaries (one module per provider or one shared module); vendor onboarding and connection flow per provider; payout schedule and minimums; split-mode fee handling (who pays gateway fees); refunds, disputes and chargebacks in each mode; Subscriptions renewals (COM-12). |
