# Mercatus API-First Marketplace — Master Architecture & Testing Constitution

> **How to use this document with AI coding and review agents:**
> This document is the architectural constitution for **Mercatus** (Core) and **Mercatus Pro**. `DECISIONS.md` is the decision record that governs it: where the two disagree, `DECISIONS.md` wins and this document is amended to match. Rules here carry IDs (for example `G-DB-2`); decisions carry their own IDs (for example `TAX-2`). Code reviews, tickets and module decision files (`decisions/<module-id>.md`, PRIN-3) cite IDs instead of restating rules (DRY-7). Code that violates a rule in **Section 1**, or lacks the tests required by **Section 10**, must be rejected.
>
> Last aligned with `DECISIONS.md` (frozen 2026-10-23, amendments A1–A5) on 2026-10-23. The pre-decision version is archived at `archive/constitution-2026-10-22-original.md`.

---

## 1. AI Agent Code-Review Guardrails (Automatic Rejection Triggers)

When reviewing any pull request, commit or generated code, an AI agent or human reviewer **MUST REJECT** the code if any of the following violations exist.

1. **Architecture & boundary violations:**
   - **G-BND-1** A REST controller contains business rules, state transitions, or direct `$wpdb`/SQL queries instead of delegating to an Application Command or Query.
   - **G-BND-2** Domain or Application classes access `$_POST`, `$_GET`, `$_REQUEST` or `$_SERVER`, call `wp_send_json*()`, output HTML, or call WordPress translation functions. Domain code returns error codes; the API layer translates them (ARCH-16).
   - **G-BND-3** Any class in `Mercatus\Core\` imports or references a class in `Mercatus\Pro\`.
   - **G-BND-4** Any class in `Mercatus\Pro\` references `Mercatus\Core\Internal\*`, queries or alters a table owned by Core or another Pro module, or reads private options instead of using `Mercatus\Core\Contracts\*` (ORD-16).
   - **G-BND-5** An in-process PHP component (block, SSR template, WooCommerce hook, CLI command, queue worker) makes an HTTP loopback call to `/wp-json/mercatus/v1` instead of invoking the Application Command/Query directly.
   - **G-BND-6** A database transaction wraps WooCommerce CRUD writes. Transactions wrap only `wp_mercatus_*` writes; WooCommerce writes happen outside them and are safe to retry (ARCH-4).
   - **G-BND-7** A module rebuilds something WooCommerce already provides: the cart and checkout API (Store API), emails (`WC_Email`), the order-pay page, the shipping method API, or tax maths (DRY-5, TAX-2).
2. **Database & scale violations:**
   - **G-DB-1** A list on a Mercatus-owned surface (vendor storefronts, vendor dashboard, Mercatus catalog/offer blocks) uses `WP_Query`, `get_posts()`, `wc_get_products()`, or joins `wp_postmeta`/`wp_term_relationships` instead of querying `wp_mercatus_offer_availability` / `wp_mercatus_catalog_facets` through repositories. WooCommerce shop and category pages remain WooCommerce's, and reading one product through WooCommerce CRUD to edit it is allowed (SCOPE-2).
   - **G-DB-2** Tier 2 filtering/specification attributes are registered as global `pa_*` taxonomies instead of rows in `wp_mercatus_attributes`, `wp_mercatus_attribute_values` and `wp_mercatus_catalog_facets`.
   - **G-DB-3** A list endpoint runs queries in a loop (N+1), or uses `OFFSET` pagination on high-volume tables (products, offers, orders, media, ledger entries) instead of cursor pagination (`next_cursor`).
   - **G-DB-4** Code writes directly to WooCommerce-owned tables (`wp_wc_orders*`, `wp_posts`, `wp_postmeta`) instead of going through WooCommerce CRUD / HPOS data stores.
   - **G-DB-5** Code relies on legacy `wp_posts` order storage instead of HPOS (`custom_order_tables`).
   - **G-DB-6** An event publisher schedules one Action Scheduler row per event instead of writing to `wp_mercatus_events_outbox`.
   - **G-DB-7** A table uses a hard-coded charset or collation instead of `$wpdb->get_charset_collate()` (PLAT-6).
3. **Caching violations:**
   - **G-CACHE-1** Code calls `wp_cache_flush()` or scans keys instead of bumping version pointers through `CachePort`.
   - **G-CACHE-2** Code talks to Redis or Memcached directly, or branches on whether a persistent object cache exists, instead of using `CachePort` or `CoordinationPort` over `wp_cache_*`. Only those two adapters check, once at boot (PLAT-2, PLAT-8).
   - **G-CACHE-3** Code writes per-product or per-user transients, or large autoloaded options (PLAT-3).
   - **G-CACHE-4** An authenticated endpoint resolves `ActorIdentity` through live SQL joins on every request instead of the cached identity service, or a suspension or staff change fails to bump the actor's identity revision (ARCH-10).
   - **G-CACHE-5** Correctness (idempotency, uniqueness, money) depends on the cache or `CoordinationPort` instead of the database (PLAT-8), or an ETag is derived from a cache revision number instead of the entity's database `version` (PLAT-11).
4. **Financial & tax violations:**
   - **G-MONEY-1** Ledger balances, holds, entries, allocations or commissions are stored or calculated as PHP `float` instead of `int` (`BIGINT` minor units plus a `CHAR(3)` ISO currency code).
   - **G-MONEY-2** Unit prices, percentage rates or tax rates use `float` instead of exact decimal strings backed by `DECIMAL(20,6)`.
   - **G-MONEY-3** A child order is created before the payment conditions in ORD-9 are met, or historical tax or commission snapshots are recalculated when rules change (COM-3).
   - **G-MONEY-4** A table has an editable balance column without an immutable append-only journal entry and an optimistic `version` check.
   - **G-MONEY-5** A money change (ledger entry, hold, capture, earnings credit or reversal) happens in an asynchronous outbox handler instead of synchronously in the command's transaction (ARCH-5).
   - **G-MONEY-6** Mercatus calculates tax amounts itself instead of letting WooCommerce do it, or builds tax snapshots from its own calculation instead of the saved order's line taxes (TAX-2, TAX-12).
5. **Security violations:**
   - **G-SEC-1** A controller uses scattered `current_user_can()` checks instead of a dedicated Policy class (`StorePolicy`, `AllocationPolicy`, `WithdrawalPolicy` and so on).
   - **G-SEC-2** Dynamic SQL values are interpolated without `$wpdb->prepare()`, or `ORDER BY`/column identifiers are not resolved from a static allow-list.
   - **G-SEC-3** A financial, stock-changing or external-provider `POST` lacks `Idempotency-Key` enforcement, or a `PATCH`/`PUT` lacks `If-Match`/`ETag` optimistic concurrency, or the idempotency row is not written before the command runs (PLAT-9).
   - **G-SEC-4** Another tenant's resource returns 403 instead of 404 (ARCH-6).
   - **G-SEC-5** Vendor-supplied rich text is not cleaned on save with a fixed allow-list and escaped on output, an upload bypasses the MIME/SVG policy, or an outbound request bypasses the allow-list (ARCH-14).
   - **G-SEC-6** Secrets are encrypted with a key derived from WordPress salts instead of `MERCATUS_ENCRYPTION_KEY` (ARCH-13).
   - **G-SEC-7** Vendors are granted WooCommerce capabilities such as `edit_products` (ARCH-8).
6. **Testing violations (zero tolerance):**
   - **G-TEST-1** A new Command, Query, Policy, Repository, Controller, Migration or Module arrives without the tests Section 10 requires.
   - **G-TEST-2** A repository test mocks `$wpdb` instead of running against the integration test database.
   - **G-TEST-3** A query-count ceiling regresses on the scale benchmark, with or without a persistent object cache (PLAT-7). Latency is tracked as a trend, not a blocking gate.
7. **DRY, configurability & extension violations:**
   - **G-DRY-1** A cross-cutting concern is re-implemented instead of using the shared kernel (DRY-3): Money/Currency/Allocator, IDs, Clock, state machine, error codes, cursors, cache keys and revisions, projection runner, repository base, base REST controller.
   - **G-DRY-2** A fact gets a second source of truth: a hand-written copy of an API shape instead of the generated one, money held outside the ledger, or a setting outside the typed settings registry (DRY-2).
   - **G-DRY-3** DTOs are shared across bounded contexts, or Pro reaches into Core internals, to save lines (DRY-6).
   - **G-EXT-1** A business rule that differs between business models (commission bases, shipping or tax recipient, completion or earnings-release rules and similar) is hard-coded instead of being an admin setting with a default (PRIN-1).
   - **G-EXT-2** A public WordPress filter is added outside a documented policy decision point, or over internal arrays (ARCH-21), or a domain event reaches WordPress other than through the single post-commit `do_action()` bridge (ARCH-15).
   - **G-EXT-3** A decision that has a single decision point is made elsewhere: store tax (`StoreTaxPolicy`, TAX-11), rate choice (`TaxRateResolver`, TAX-12), child-order visibility (`ChildOrderExposure`, ORD-10), or order completion (`OrderCompletionPolicy`, ORD-13).

---

## 2. Purpose, Product Split, and Core Decisions

Build a new, standalone marketplace platform as two installable WordPress plugins:

- **Mercatus** (free): the stable marketplace platform, public/admin/vendor APIs, extension SDK, security system, multi-tier caching engine (Redis/Memcached optional, PLAT-1), core data ownership, and the essential vendor, store, high-scale catalog, tax, shipping, commission, ledger, manual withdrawal and two-stage order-allocation workflows.
- **Mercatus Pro** (paid): a single installable package containing independently activatable modules that depend on Mercatus and extend it strictly through published contracts. It contains commercial features, advanced workflows, finance automation, integrations and entitlement checks.

`API-first` means every first-party UI calls the same versioned REST API available to approved third-party clients. It does **not** mean domain rules live in controllers. Controllers adapt HTTP requests to application commands and queries; domain and application services own all rules. WordPress and WooCommerce are infrastructure adapters around that core.

Mercatus is 100% independent of WCFM. It has no runtime dependency, shared PHP namespace, shared database tables, compatibility layer, data migration bridge or direct integration with WCFM. It uses its own namespace, schema, API, settings, roles/capabilities and release cycle.

Mercatus serves many kinds of marketplaces in many countries, so rules that vary by business model are admin settings with sensible defaults (PRIN-1), and features deferred from v1 get an extension seam now (PRIN-2).

### Explicit non-goals for version 1

- Rebuild every historical setting, filter, CSS template, payment gateway and third-party integration.
- Make the REST API a proxy to `admin-ajax.php`, or mutate `$_POST` to reuse another plugin's controllers.
- Let a paid plugin replace, subclass or patch Core internals.
- Store financial values in floating point or stringly typed mega-tables.
- Support WooCommerce's legacy `wp_posts` order tables; HPOS (`custom_order_tables`) is required from day 1.
- Import, synchronise or otherwise link WCFM data and functionality.
- Replace WooCommerce's shop and category pages (SCOPE-2).
- An OAuth2/PKCE server, H3 indexing, a direct Redis/Memcached client, a Mercatus payment bridge for classic gateways, or CDN purge by tag (SCOPE-1).
- Third-party tax plugin support (TAX-5).
- A customer wallet in Free, or wallet top-up anywhere (WAL-1, WAL-2).

### Design constraints & governing architecture rules

1. **Fast at high scale (1,000s of vendors, 100,000–500,000+ products, 1,000s of categories and attributes), with or without a persistent object cache (PLAT-1):** avoid redundant work, loopback requests, unbounded queries, `wp_postmeta`/`wp_term_relationships` multi-join scans, and synchronous work that can run asynchronously. Performance ceilings are enforced in CI (Section 6).
2. **API-first and headless-ready:** every business action is a documented, permission-checked Mercatus command or query. Admin, vendor, customer and future headless/mobile clients use the same application services and policies; the UI is a client, never the source of business rules.

### API-first execution rules (zero-overhead guarantee)

1. **In-process PHP calls use Application Services directly:** server-rendered blocks, WooCommerce hooks, CLI commands and background workers invoke Application Commands/Queries in memory, never through an HTTP loopback.
2. **SSR initial state hydration:** buyer-facing Mercatus pages hydrate above-the-fold data in the first PHP response using the same DTO serializer output as the REST API.
3. **Coarse-grained portal bootstrap:** first-party vendor and admin apps load actor identity, capabilities, features and store context in a single `/mercatus/v1/me` call.
4. **Fast-path reads:** `GET` queries read denormalized projections and the cache, serializing straight to response DTOs without hydrating rich aggregates or instantiating `WC_Product`/`WC_Order` in loops.

### WooCommerce boundary: ecosystem compatibility vs. Mercatus scale

Mercatus keeps WooCommerce for ecosystem compatibility: its payment gateways, product-type plugins (Bookings, Subscriptions, Auctions), cart, checkout and tax engine.

- **Write path:** use WooCommerce CRUD, HPOS data stores and checkout/payment hooks. Never write WooCommerce-owned tables directly, and never wrap WooCommerce writes in a Mercatus transaction (ARCH-4).
- **Read path on Mercatus-owned surfaces:** vendor storefronts, the vendor dashboard and Mercatus catalog/offer blocks read Mercatus projections (`wp_mercatus_offer_availability`, `wp_mercatus_catalog_facets`) through repositories (SCOPE-2).
- **Source of truth for price and stock:** Mercatus offers and inventory. WooCommerce `_price` and `_stock` are copies written by Mercatus (ARCH-8). Product and stock saves made outside Mercatus update the projections, and a nightly job reconciles them (ARCH-9).
- **Cart and checkout:** WooCommerce's classic checkout and Store API, extended through `ExtendSchema` and checkout hooks. Mercatus builds no cart or checkout API of its own. Headless clients pay with classic gateways through WooCommerce's order-pay URL (`$order->get_checkout_payment_url()`) (SCOPE-1, DRY-5).
- **Tax:** WooCommerce does all tax maths; Mercatus only chooses which rates apply to each cart item (TAX-2).

---

## 3. Target Architecture, Project Layout, & Module Contracts

### Layers and inward dependency rule

```text
Browser / Mobile / Partner Client / SSR Block
                           |
        REST API v1 / Direct In-Process PHP Adapter
                           |
                      API Adapters
                           |
       Application Commands + Queries  <---- Pro Modules
                           |                      |
       Domain Modules + Published Contracts <-----+
                           |
                 Infrastructure Ports
                           |
 WordPress, WooCommerce (HPOS), object cache (optional), MySQL, Action Scheduler
```

Dependencies point strictly inward.

### Repository and directory layout

Two repositories/distributions (`mercatus` and `mercatus-pro`), with a shared contract package generated from Core. Every module is laid out as `{Module}/{Domain,Application,Infrastructure,Api}`, and Deptrac enforces the layers (ARCH-1).

```text
mercatus/
  mercatus.php
  composer.json             # PSR-4; third-party libraries prefixed with Strauss or PHP-Scoper (ARCH-12)
  phpstan.neon
  phpcs.xml.dist            # WordPress-Extra without file-name and naming sniffs (ARCH-3)
  deptrac.yaml
  phpunit.xml.dist
  src/
    Kernel/                 # plugin boot, module registry, config, typed settings registry (DRY-2), migration runner
    Shared/                 # shared kernel (DRY-3): Money, Currency, Allocator, Ids, Clock, StateMachine,
                            #   error codes, Cursor, cache keys/revisions, projection runner,
                            #   repository base, base REST controller
    Contracts/              # public SDK, semantic-versioned: ModuleManifest, CachePort, CoordinationPort, TaxRateResolver,
                            #   StoreTaxPolicy, CommissionRuleSource, OrderCompletionPolicy,
                            #   ChildOrderExposure, PayoutProvider, CatalogSearchPort
    Identity/               # actors, capabilities, policies, API clients
    Vendor/                 # vendor lifecycle and assignments
    Store/                  # profile, visibility, public storefront data
    Catalog/                # ownership, 2-tier attributes, facets, projections; WooCommerce product adapter
    Orders/                 # allocations, vendor child orders, fulfilment status, shipment records, completion
    Commission/             # commission engine and global rule (COM-3)
    Ledger/                 # accounts, immutable entries, holds; vendor earnings, earnings release,
                            #   manual withdrawals (WAL-3, COM-4, COM-13)
    Tax/                    # StoreTaxPolicy, TaxRateResolver (WooCommerce rates, vendor store rates), snapshots
    Shipping/               # vendor shipping profiles, zones, methods, quotes
    Reviews/
    Notifications/
    Infrastructure/         # cross-module adapters only: wpdb connection, CachePort and CoordinationPort over wp_cache_*,
                            #   outbox worker, WordPress/WooCommerce bootstrap
    Internal/               # private helpers; forbidden to Pro and external plugins
  database/
    migrations/             # the schema's source of truth (DRY-2)
    seeders/                # ScaleBenchmarkSeeder (wp mercatus seed:scale), grown module by module (ARCH-20)
  openapi/
    mercatus-v1.yaml        # generated from the PHP API definitions, committed and diff-checked (DRY-2)
  tests/
    Architecture/           # Deptrac rules: layer and plugin isolation
    Unit/                   # fast, zero-DB tests: money math, policies, state machines, rules
    Contract/               # SDK conformance, generated OpenAPI checks, event payload schemas
    Integration/            # real MySQL/MariaDB, Redis, Memcached and no persistent cache; WooCommerce HPOS
    Security/               # route-registry-generated actor × resource × endpoint matrix (DRY-4)
    Scale/                  # query ceilings and latency trends on the seeded benchmark
    EndToEnd/               # full API and browser acceptance journeys (ARCH-19)
    Js/                     # JS unit tests for first-party UIs (ARCH-19)
    Fixtures/               # deterministic builders and factories for all aggregates

mercatus-pro/
  mercatus-pro.php
  composer.json
  phpstan.neon
  phpcs.xml.dist
  deptrac.yaml
  phpunit.xml.dist
  src/
    Licensing/
    ShipmentTracking/       # first Pro module (ORD-15)
    Commissions/            # additional rule sources (COM-2)
    Payouts/                # payout automation, schedules, reverse withdrawals, reconciliation
    PaymentIntegrations/    # Stripe and PayPal, payout and split modes (COM-11, COM-12)
    Refunds/
    Wallet/                 # customer wallet on the Core ledger (WAL-2 to WAL-5)
    Memberships/
    Staff/
    Delivery/               # local delivery agents, routes, OTP confirmation (ORD-16, ORD-17)
    Analytics/
    Affiliate/
    Ai/
    MultiLocationFulfilment/
    VariableWeightSettlement/
    TaxAutomation/          # automated TaxRateResolver adapters (TAX-12)
    AdvancedCatalog/
    Integrations/
  database/
    migrations/
  tests/
    Architecture/           # zero imports from Mercatus\Core\Internal; cross-module isolation
    Unit/
    Contract/               # provider conformance (PayoutProvider, TaxRateResolver, CatalogSearchPort)
    Integration/            # activation/deactivation zero-footprint tests and migrations
    Security/               # entitlement and tenant isolation matrix
    Scale/
    EndToEnd/
```

Use Composer PSR-4 autoloading with prefixes `Mercatus\Core\` and `Mercatus\Pro\`. Pro builds against Core's contracts but does not ship them (ARCH-12). Avoid global singletons except a minimal `mercatus()` facade that returns public interfaces. All public function, option, transient, cache-group, database-table, hook, REST and capability names use the `mercatus` prefix.

### Module contract

Each module declares a manifest before it is loaded:

```php
return new ModuleManifest(
    id: 'pro.payouts',
    version: '1.0.0',
    requires: ['core' => '^1.0'],
    provides: ['payouts'],
    routes: PayoutRoutes::class,
    migrations: PayoutMigrations::class,
    permissions: PayoutPermissions::class,
    eventSubscribers: [PayoutSubscriber::class],
    healthChecks: [PayoutHealthCheck::class],
);
```

Mercatus validates the manifest, version compatibility, unique IDs/routes/permissions, dependency order and migration state. It exposes a read-only module/feature manifest at `GET /mercatus/v1/features` (ARCH-7) and embeds it in `GET /mercatus/v1/me` so UIs can feature-detect. Before a major module is implemented, it gets its own decision file (PRIN-3).

Published extension points are strictly limited to:

1. **Commands and queries:** typed interfaces for work owned by a module.
2. **Domain events:** immutable event name, event ID, occurrence time, aggregate ID/version, actor, correlation ID and payload schema. Every event is also passed to WordPress through `do_action()` after commit, from one bridge (ARCH-15).
3. **Policies and capabilities:** named capabilities and policy interfaces. Policies are the single decision points for cross-cutting choices: `StoreTaxPolicy` (TAX-11), `TaxRateResolver` (TAX-12), `CommissionRuleSource` (COM-3), `OrderCompletionPolicy` (ORD-13), `ChildOrderExposure` (ORD-10).
4. **REST route registration:** a module adds routes under the core namespace only through the registrar, with lazy schema loading and a permission callback.
5. **Read-model/field extensions:** namespaced fields with explicit visibility rules; never arbitrary filters over internal arrays.
6. **Documented WordPress filters at policy decision points only (ARCH-21):** named `mercatus_{subject}_{decision}`, for example `mercatus_store_tax_enabled`, `mercatus_commission_flat_basis` and `mercatus_earnings_hold_days`.

Use semantic versioning for Core contracts. Deprecate a contract for at least one supported Core minor release, publish an upgrade guide, and remove it only in the next major.

---

## 4. Free vs. Paid Split & Unchanging Core Domain Model

Every Pro capability is an independently activatable module inside the single Mercatus Pro package. Installing Mercatus Pro does not turn a capability on, and an inactive capability contributes **zero** REST routes, workflow states, scheduled jobs, database queries, event subscribers or checkout-time calculations. Its migrations run only when the module is activated, and its tables are retained safely on deactivation.

| Domain | Mercatus (Free) | Mercatus Pro (Paid) |
|---|---|---|
| **Platform** | API kernel, module SDK, cache engine (Redis/Memcached optional, PLAT-1), typed settings registry, migrations, two-lane outbox worker, audit/health foundations, roles/capabilities | License/entitlement verification and Pro module activation |
| **Vendor & Storefront** | Vendor application/approval, one public storefront per vendor, profile, policies, visibility, store list, one implicit default fulfilment location | Verification/KYC, multi-location fulfilment, vacation/scheduled availability, advanced SEO, branded storefront features |
| **Catalog** | Vendor ownership mapping, allowed CRUD for WooCommerce products, category-scoped attribute templates, facet and availability projections, media ownership/quotas, catalog moderation basics | Multi-vendor offers (SPMV), catalog cloning, bulk importer, external search adapters (Typesense/Meilisearch/OpenSearch), variable-weight settlement, advanced product rules, bookings/subscriptions/auctions integrations |
| **Orders** | Parent order plus one child `shop_order` per vendor for every paid order (ORD-1, ORD-11); vendor-scoped order API; fulfilment status and shipment records (ORD-12, ORD-16); completion triggers (ORD-17); admin parent overrides (ORD-14) | Shipment Tracking, the first Pro module (ORD-15); OTP and carrier-confirmed delivery (ORD-17); advanced workflows; returns/disputes automation |
| **Tax** | WooCommerce tax for every item, or per-vendor store tax rates with approval and fallback (TAX-1, TAX-9, TAX-13, TAX-14); per-allocation snapshots; refunds; basic tax reporting/export | Automated tax providers as `TaxRateResolver` adapters (TAX-12); registration validation; e-invoices/tax documents; filing/remittance integrations |
| **Money** | Commission engine with global flat, percent and flat + percent (COM-1, COM-3, COM-5); coupon, shipping and tax settings (COM-6 to COM-8); ledger (WAL-3); earnings release (COM-13); manual withdrawals (COM-4) | Commission rule sources: category, membership, vendor, product, cart value, total sales (COM-2); Stripe and PayPal payout and split modes (COM-11); automatic and scheduled payouts; reverse withdrawals; reconciliation; per-vendor hold days and rolling reserve (COM-13) |
| **Customer wallet** | None (WAL-2) | Refund credit the customer opts into (WAL-4) and promotional credit, spent as payment after tax (WAL-5); no top-up (WAL-1) |
| **Shipping** | Vendor-owned shipping settings, zones, methods/rates, ship-from address, basic pickup, test-quote API, WooCommerce shipping integration | Location-based pickup, delivery, routing rules, distance/weight/country rules, delivery agents/routes/time slots |
| **Engagement** | Public store reviews and moderation | Groups & Staff, Memberships, Affiliate, advanced notifications, customer support extensions |
| **Data & Intelligence** | Exportable basic data and event stream | Analytics read models, scheduled reports, AI Suite with explicit provider consent and quotas |

### Capability modules without changing the core model

The internal path is always:
$$\text{Catalog Product} \rightarrow \text{Vendor Offer} \rightarrow \text{Fulfilment Location} \rightarrow \text{Inventory Balance} \rightarrow \text{Order Line}$$

| Capability Module | Disabled Behaviour | Enabled Behaviour |
|---|---|---|
| **Multi-location fulfilment** | Every vendor receives one automatically created default fulfilment location. Location-management UI/API is hidden, and all products and inventory resolve to that location. | A vendor can add branches, pickup points, delivery locations and warehouses with separate inventory, staff, shipping, pickup, delivery, prices and promotions. |
| **Single Product, Multiple Vendors (SPMV)** | A catalog product permits one active vendor offer. The other-sellers UI and offer-selection API are unavailable. | A catalog product permits multiple active vendor offers; buyers select an offer by seller, price, location and fulfilment terms. |
| **Location-specific prices** | A vendor offer's base price applies at every location. | An offer-location may supply a validated price override. |
| **Location-specific promotions** | Only vendor-wide promotions are evaluated. | Location-level promotion rules are evaluated after the fulfilment location is known. |
| **Variable-weight settlement** | Products use fixed-quantity price, payment, child-order, refund, commission and completion flow. No measurement fields or extra statuses exist. | Eligible offers may be sold by measured unit with an estimate, approved tolerance, measured final quantity and post-weigh settlement. |

### Two-tier attribute & category architecture

To avoid registering thousands of global `pa_*` taxonomies on every request or bloating `wp_term_relationships`:

1. **Tier 1 — WooCommerce native attributes (variation-generating only):** used only for attributes that create distinct purchasable `WC_Product_Variation` records (for example Size, Color).
2. **Tier 2 — Mercatus catalog attributes (filtering and specifications):** stored in `wp_mercatus_attributes`, `wp_mercatus_attribute_values`, `wp_mercatus_category_attribute_maps` and `wp_mercatus_catalog_facets`.
3. **Category-scoped attribute loading:** categories are indexed with a materialized path, depth and leaf flag in `wp_mercatus_categories` (ARCH-18). `GET /mercatus/v1/catalog/categories/{id}/schema` returns only the attributes mapped to that category branch.
4. **Lazy tree and cursor autocomplete:** category trees load by parent (`GET /categories?parent_id=X`), and attribute values use cursor-based search (`GET /attributes/{id}/terms?search=...&cursor=...`).
5. **Facet counts** are precomputed per category, or approximate (ARCH-11).

---

## 5. Order Splitting, Order Status, Tax, Shipping, Commission & Ledger

### Parent order and vendor child orders

At checkout, WooCommerce creates one customer-facing **parent order**. Mercatus then runs a deterministic two-stage split.

1. **Stage 1 — allocation and snapshot before payment.** It runs for every way an order is created: classic checkout, Store API, admin, REST and Subscriptions renewals (ORD-8).
   - After WooCommerce has saved the parent order with its final line items, coupons, shipping packages and taxes, Mercatus resolves the vendor offer, fulfilment location and commission (COM-3) for every line.
   - In one transaction over Mercatus tables only (ARCH-4), it writes `wp_mercatus_order_groups`, immutable `wp_mercatus_order_allocations` (with the commission snapshot), `wp_mercatus_tax_snapshots` (copied from the saved order's line taxes, TAX-12) and `wp_mercatus_inventory_reservations`.
   - Payment is collected once against the parent order through any WooCommerce gateway. Split-pay gateways (Pro, COM-11) read the Stage 1 allocations.
2. **Stage 2 — child order creation after payment.**
   - Child orders are created on payment complete, on COD, or for allow-listed authorise-only gateways; never for a plain on-hold bank-transfer or cheque order (ORD-9).
   - Every paid order is split, even a single-vendor one: one child `shop_order` per `(parent_order_id, vendor_id)` (ORD-1, ORD-11). The split is idempotent and locked through `wp_mercatus_vendor_orders`, so retries and concurrent hooks never create duplicates.

**Mandatory order split rules:**
- The sum of child financial totals (items, discounts, taxes, fees, shipping, shipping tax) reconciles exactly to the parent total:
  $$\sum_{i=1}^{k} \text{ChildTotal}_i = \text{ParentTotal}$$
- Money lives in the allocations; child orders are generated from them. Money changes on a child go through Mercatus, which records an adjustment and re-syncs the child (ORD-3).
- Refunds are requested on the child and paid out through the gateway on the parent; a WooCommerce refund is created on both, and only the parent's counts in reports (ORD-4).
- Child orders never take payment, never reduce or restore stock, and record item changes after payment as adjustments (ORD-6).
- Child orders carry a documented marker (parent order ID, `created_via` = `mercatus`). `ChildOrderExposure` alone decides which outside systems see them; in v1 they are hidden from WooCommerce Analytics, My Account orders and WooCommerce customer emails (ORD-5, ORD-10). The parent is the payment record and each child is that vendor's fulfilment record (ORD-7).

### Order status, fulfilment and completion

- **Order status** stays standard WooCommerce: `processing` after payment (`on-hold` for authorise-only payments), then `completed`, plus `cancelled` and `refunded`. No custom WooCommerce order statuses (ORD-12).
- **Fulfilment status** is a separate Mercatus status per vendor order: unfulfilled → partially shipped → shipped → delivered. Each shipment record is one package from one vendor order, with its items and quantities (ORD-16). The model is shaped like WooCommerce's Fulfillments feature so it can be mirrored later.
- **Parent status** is owned by the admin. Automation moves the parent only to the least-advanced stage among its active vendor orders, and completes it only when all of them are completed (ORD-2). Admin changes flow straight down: completing the parent completes every vendor order; cancelling it cancels everything and refunds a paid order in full, after a confirmation that lists vendor orders already shipped (ORD-14).
- **Completion** of a vendor order is decided by `OrderCompletionPolicy`; whichever enabled trigger fires first wins (ORD-17): vendor marks completed (on), customer confirms receipt (on), auto-complete N days after delivered (3) or after shipped (14), digital-only orders on payment (on). The admin can always complete, and an open refund or return request pauses the timers. Pro adds OTP, carrier-confirmed delivery and booking-start triggers.

### Tax

- **Modes (TAX-9):** with "Enable store tax" off, WooCommerce's own tax settings and rates apply to every item. With it on, vendors manage their own rates in the shape of WooCommerce's tax rate table (TAX-1). A "Vendor tax rates need approval" setting applies (TAX-13), and a setting decides what happens to a vendor without approved rates: WooCommerce rates, no tax, or blocked selling (TAX-14). Whether store tax applies to a vendor is answered only by `StoreTaxPolicy`, through the filter `mercatus_store_tax_enabled` (TAX-11).
- **Mechanism (TAX-2, TAX-12):** WooCommerce does all tax maths. Mercatus chooses each cart item's rates through `woocommerce_cart_totals_get_item_tax_rates`, using a `TaxRateResolver` (v1 ships a WooCommerce-rates resolver and a vendor-store-rates resolver). A resolver may return rates or per-line amounts and is called once per vendor. Snapshots copy the saved order's line taxes, so the parent always equals the sum of the vendor totals.
- **Resolver input (`TaxContext`):** seller (vendor), product tax class, fulfilment method (delivery, pickup, digital), origin (the vendor's ship-from address, which replaces the shop base address when store tax is on, TAX-15), destination (customer address, or the pickup point for pickup items), transaction time and currency.
- **Rules:** prices entered including tax keep the same gross price for every customer when store tax is on (TAX-3). A child order is recalculated with its own vendor, a multi-vendor parent is never recalculated after the split, and recalculation never silently loses tax (TAX-4). No matching rate means no tax, with health-check and test-quote warnings (TAX-7). Each vendor's shipping tax is set by the Mercatus vendor shipping method (TAX-8). Prices including or excluding tax, rounding and display stay marketplace-wide (TAX-15).
- **Plugins:** third-party tax plugins are not supported in v1; the health check flags them (TAX-5, TAX-10). Automated providers arrive later as `TaxRateResolver` adapters in modules, never as patches to the tax code (TAX-12).
- **Vendor rate storage** is settled by SPIKE-1 (TAX-6): WooCommerce's tax table under a per-vendor class, or a Mercatus table with reserved-range IDs.

### Vendor Shipping & Tax settings (Core)

Every vendor gets a tenant-scoped **Settings → Shipping** and **Settings → Tax** API and UI, isolated from global `woocommerce_*` settings. A read-only test-quote endpoint (`POST /mercatus/v1/me/shipping-tax/test-quote`) lets the vendor pick an offer, a destination or pickup point and an amount, and returns the full shipping and tax breakdown without creating an order.

### Commission, ledger and vendor earnings (Core)

- **Commission engine (COM-3):** one resolver in Core with the global rule (flat, percent, or flat + percent, COM-1). Pro modules add rule sources in a fixed priority order (COM-2). The winning rule, its version and the flat basis are saved on each allocation at Stage 1. The flat part applies once per vendor order by default; `mercatus_commission_flat_basis` switches it to per item or per line (COM-5). Settings decide whether vendor and admin coupons reduce the commission base (COM-6), whether shipping goes to the vendor (COM-7) and, with store tax off, whether tax goes to the vendor (COM-8). Shipping and tax are never commissioned (COM-9). These settings are global values that Pro rule sources can override (COM-10).
- **Ledger (WAL-3, DRY-2):** one ledger of accounts, immutable entries (`BIGINT` minor units) and holds holds all money. There is no generic balance-editing endpoint. A vendor payable balance may go negative (ARCH-18). Every money change happens synchronously in its command's transaction (ARCH-5).
- **Earnings release (COM-13):** earnings become withdrawable N days after completion (default 7; 0 gives WCFM's behaviour). An open refund or return request freezes that vendor order's earnings; refunds after release are taken back from the vendor balance, and withdrawals are blocked while it is negative. Pro adds per-vendor hold days through `mercatus_earnings_hold_days`, a rolling reserve and automatic payouts on the same rule.
- **Manual withdrawals (COM-4):** a vendor requests a withdrawal from their available balance, optionally above a minimum amount; the admin approves it, pays outside Mercatus and marks it paid.

### Customer wallet (Pro)

The customer wallet is a Pro module on the Core ledger (WAL-2, WAL-3). It holds refund credit and promotional credit only; there is no top-up (WAL-1). Refunds go to the original payment method unless the customer accepts wallet credit (WAL-4). Spending credit counts as payment applied after tax, so it changes neither tax nor vendor earnings; the marketplace funds promotional credit from its own ledger account (WAL-5). Partial wallet payment is validated by SPIKE-2 at the start of the module.

### Optional variable-weight goods and post-weigh settlement (Pro)

When activated, eligible offers are priced per unit (for example per kg) and ordered by target weight with a customer-approved lower/upper tolerance.
- Checkout snapshots unit price, target quantity, approved tolerance, estimated total and maximum authorised total.
- Before dispatch, an authorised fulfilment user records one measured weight. Mercatus validates the tolerance, computes the final line total from snapshotted rates, and settles by: (1) authorise max, capture actual; (2) capture estimate, refund the lower difference (or credit the Pro wallet if active and the customer accepts); (3) capture estimate, charge a saved method or record a transparent `customer_settlement` receivable for a higher actual; or (4) verified COD collection.
- Vendor gross sales, taxes and commissions use the **actual final line amount**. Incremental vendor earnings on unpaid customer settlement debits are held until collected unless policy explicitly assumes the credit risk.

---

## 6. Performance, Caching, & Location Discovery

### Caching architecture

Mercatus defines `CachePort` in `Mercatus\Core\Contracts\Cache`, implemented only over `wp_cache_*` (including `wp_cache_get_multiple()` and `wp_cache_supports()`). Business code never checks whether Redis exists, and nothing uses a direct PhpRedis/Memcached client (PLAT-2). Every feature works without a persistent object cache (PLAT-1).

Coordination is separate from caching (PLAT-8). `CoordinationPort` provides counters and locks that hold across requests, using `wp_cache_incr`/`wp_cache_add` when a persistent object cache is present. Without one it reports them as unavailable, and best-effort users (rate limiting, stampede locks) switch off. `CachePort` and `CoordinationPort` are the only classes that check for a persistent cache, once at boot. Nothing that must be correct depends on either: idempotency, the order split, the outbox and ledger balances use the database directly. A database-backed `CoordinationPort` (`wp_mercatus_counters`) is built only when a feature first needs coordination without Redis.

1. **L1 — per-request memoization:** repeated lookups of `ActorIdentity`, `ModuleManifest`, feature flags or policies within one request cost no network hop.
2. **L2 — persistent object cache, when the site has Redis or Memcached:**
   - **Cache groups:** `mercatus_actors`, `mercatus_capabilities`, `mercatus_stores`, `mercatus_offers`, `mercatus_availability`, `mercatus_facets`, `mercatus_categories`, `mercatus_shipping`, `mercatus_locks` and `mercatus_ratelimits`.
   - **Version-stamped keys:** never flush or scan keys. Every entity or collection key includes a version pointer:
     $$\text{Key} = \text{mercatus\_availability:p:\{product\_id\}:v:\{product\_rev\}:loc:\{location\_id\}}$$
     When stock, price, promotion or location config changes, Mercatus increments `mercatus_rev:product:{product_id}`. Pointers start from the current time, not 1, so an evicted pointer can never revive old entries, and are created with `add` before `incr` (PLAT-4).
   - **Batched multi-get:** list endpoints fetch cached projections in one `wp_cache_get_multiple()` call, query MySQL only for misses, and backfill.
   - **Stampede protection:** hot keys store a soft-expiry timestamp inside the payload with a longer hard TTL. When soft-expired, one worker takes a non-blocking `CoordinationPort` lock (`wp_cache_add('lock:...', 1, 'mercatus_locks', 5)`) to refresh while others receive the stale value. Without a persistent cache there is no shared cached value to protect.
   - **Rate limiting (PLAT-10):** every route is rate-limited through `CoordinationPort` counters, with limits as admin settings. Without a persistent cache, Mercatus does no rate limiting; the health check says so and points to page-cache, CDN or firewall limits.
   - **Idempotency (PLAT-9)** never uses the cache: the `wp_mercatus_idempotency` row is written with status `processing` before the command runs, and its unique key is the lock.
3. **L3 — HTTP/CDN cache and client ETags:**
   - Public read endpoints (`GET /stores`, `GET /stores/{slug}`, `GET /catalog/offers`, `GET /catalog/categories`) send an `ETag` derived from database versions (PLAT-11) and `Cache-Control: public, max-age=60, stale-while-revalidate=300`. They may send `Cache-Tag`/`Surrogate-Key` headers, but CDN purge by tag is out of v1 (SCOPE-1).
   - Authenticated read endpoints send `Cache-Control: private, no-cache` with `ETag`, so browsers get fast `304 Not Modified` responses.
   - **ETags (PLAT-11)** come from the database, never from cache revision numbers: an item's `version`, or a hash of the IDs and versions on a list page, which are already fetched. `If-Match` checks the same `version`, so ETags stay stable with or without a persistent cache. With one, a cached response stores its ETag, so a 304 needs no database query.
4. **Without a persistent object cache (PLAT-3):** speed comes from the indexed read-projection tables. Transients are used only for a fixed, bounded set of summary values (for example filter counts per category), never per product or per user, and no large options are autoloaded. `GET /mercatus/v1/health` notes that no persistent cache is present and that rate limiting is off (PLAT-10).

### Performance & scale ceilings (100,000 products / 300,000 offers)

Query ceilings are hard CI gates, counted as Mercatus's own queries and run both with and without a persistent object cache. Latency figures are targets tracked as trends, not blocking gates (PLAT-7).

| Operation / Endpoint | Max Mercatus queries (warm persistent cache) | Max Mercatus queries (cold, or no persistent cache) | $p_{95}$ latency target (warm / cold) |
|---|---|---|---|
| `GET /mercatus/v1/me` (portal bootstrap) | $0\text{--}1$ | $\le 4$ | $< 15\text{ ms}$ / $< 45\text{ ms}$ |
| `GET /mercatus/v1/stores/{slug}` | $0$ | $\le 2$ | $< 12\text{ ms}$ / $< 35\text{ ms}$ |
| `GET /mercatus/v1/catalog/offers` (24 items + facets) | $0\text{--}1$ | $\le 3$ | $< 25\text{ ms}$ / $< 95\text{ ms}$ |
| `GET /mercatus/v1/catalog/categories/{id}/schema` | $0$ | $\le 2$ | $< 10\text{ ms}$ / $< 30\text{ ms}$ |
| Cart line offer + location + tax rate resolution | $\le 2$ | $\le 5$ | $< 35\text{ ms}$ / $< 80\text{ ms}$ |
| Stage 1 allocation (3 vendors, 6 lines) | $\le 8$ writes | $\le 12$ ops | $< 120\text{ ms}$ (excluding gateway HTTP) |

Latency targets measure Mercatus's handler time, excluding WordPress and WooCommerce bootstrap.

### Location discovery (geohash)

- Geocode or validate a location address only when it is created or updated; never call an external geocoding service during cart or checkout.
- Store `latitude`, `longitude` and an indexed 6-character geohash prefix (`geo_cell`) on `wp_mercatus_fulfilment_locations` and `wp_mercatus_offer_availability`. H3 is out of v1 (SCOPE-1).
- Query the target `geo_cell` and its neighbouring cells with an indexed `WHERE geo_cell IN (...)` lookup, then compute exact distances in PHP only for the matched candidates.

---

## 7. Domain Model and Database Schema

The migrations are the schema's source of truth (DRY-2). The tables below are the design reference they implement.

**Global schema rules:**
- Engine `InnoDB`; charset and collation from `$wpdb->get_charset_collate()` (PLAT-6).
- Identifiers: `BIGINT UNSIGNED NOT NULL`.
- Timestamps: `DATETIME(3) NOT NULL` in UTC.
- Monetary amounts (balances, holds, ledger, order/allocation/tax totals): `BIGINT NOT NULL` in minor units paired with `currency CHAR(3) NOT NULL`. Currency decimals come from ISO 4217; amounts round half-up per line, and remainders are split with the largest-remainder method (ARCH-17).
- Unit prices, percentage rates, tax rates and quantities: `DECIMAL(20,6) NOT NULL`.
- Concurrency: `version BIGINT UNSIGNED NOT NULL DEFAULT 1` on all mutable tables.
- Foreign keys are enforced in repositories with explicit indexes; never attach SQL `FOREIGN KEY` constraints to WordPress or WooCommerce tables.
- Append-only tables hold no free-text personal data (ARCH-14).

### Core tables (`wp_mercatus_*`)

| Table | Responsibility | Primary / Unique Keys & Required Covering Indexes |
|---|---|---|
| `wp_mercatus_schema_versions` | Applied Core and Pro module migrations, checksums, execution locks | PK `id`; UNIQUE `(module_id, version)` |
| `wp_mercatus_vendors` | Vendor identity and lifecycle; maps one WP user to a vendor record | PK `id`; UNIQUE `user_id`; INDEX `(status, created_at)` |
| `wp_mercatus_stores` | Store profile, normalized slug, visibility, owner vendor | PK `id`; UNIQUE `vendor_id`; UNIQUE `slug`; INDEX `(visibility, status)` |
| `wp_mercatus_catalog_products` | Catalog product, product type (`simple`, `variable`, `booking`, `subscription`, `auction`), governance state | PK `id`; UNIQUE `woo_product_id`; INDEX `(scope, status, product_type)`; INDEX `(primary_category_id, status)` |
| `wp_mercatus_categories` | Category tree index: materialized path, depth, leaf flag (ARCH-18) | PK `id`; UNIQUE `category_id`; INDEX `(parent_id, sort_order)`; INDEX `(path(64))` |
| `wp_mercatus_attributes` | Tier 2 specification and filter attributes | PK `id`; UNIQUE `slug`; INDEX `(type, is_filterable)` |
| `wp_mercatus_attribute_values` | Values of Tier 2 attributes, referenced by facets (ARCH-18) | PK `id`; UNIQUE `(attribute_id, slug)`; INDEX `(attribute_id, sort_order)` |
| `wp_mercatus_category_attribute_maps` | Allowed/required attributes per category, with materialized `category_path` | PK `id`; UNIQUE `(category_id, attribute_id)`; INDEX `(category_id, sort_order)`; INDEX `(category_path(64))` |
| `wp_mercatus_catalog_facets` | Denormalized attribute and category filter index | PK `id`; UNIQUE `(catalog_product_id, attribute_id, value_id, location_id)`; INDEX `idx_facet_filter (attribute_id, value_id, is_purchasable, catalog_product_id)`; INDEX `idx_num_filter (attribute_id, numeric_value, is_purchasable, catalog_product_id)` |
| `wp_mercatus_vendor_offers` | A vendor's sellable offer for a catalog product (`base_price` `DECIMAL(20,6)`); source of truth for price (ARCH-8) | PK `id`; UNIQUE `(catalog_product_id, vendor_id)`; INDEX `(vendor_id, status)` |
| `wp_mercatus_fulfilment_locations` | Default, branch, pickup, delivery, warehouse or 3PL location, with geohash `geo_cell` | PK `id`; INDEX `(vendor_id, status, is_default)`; INDEX `(geo_cell, status)` |
| `wp_mercatus_offer_locations` | Offer availability and local fulfilment/price overrides at a location | PK `id`; UNIQUE `(vendor_offer_id, location_id)`; INDEX `(location_id, status)` |
| `wp_mercatus_offer_availability` | Storefront read projection: offers, effective prices, stock, card display JSON, geo cells | PK `id`; UNIQUE `(vendor_offer_id, variation_id, location_id)`; INDEX `idx_catalog_avail (catalog_product_id, is_purchasable, effective_price)`; INDEX `idx_geo_avail (geo_cell, is_purchasable, catalog_product_id)`; INDEX `idx_vendor_avail (vendor_id, is_purchasable, updated_at)` |
| `wp_mercatus_inventory_balances` | Sellable stock by offer, variation and location; source of truth for stock (ARCH-8) | PK `id`; UNIQUE `(vendor_offer_id, variation_id, location_id)` |
| `wp_mercatus_inventory_movements` | Append-only stock change journal | PK `id`; UNIQUE `movement_uuid`; INDEX `(balance_id, created_at)` |
| `wp_mercatus_inventory_reservations` | Checkout stock reservations with expiry | PK `id`; UNIQUE `reservation_uuid`; INDEX `(balance_id, status)`; INDEX `(status, expires_at)` |
| `wp_mercatus_media_ownership` | Vendor media ownership and storage quotas | PK `attachment_id`; INDEX `(vendor_id, created_at DESC)` |
| `wp_mercatus_order_groups` | Parent order grouping and split state machine | PK `id`; UNIQUE `parent_woo_order_id`; INDEX `(status, created_at)` |
| `wp_mercatus_vendor_orders` | One child order record per vendor per paid parent; fulfilment status, completion state | PK `id`; UNIQUE `(parent_woo_order_id, vendor_id)`; UNIQUE `child_woo_order_id` (nullable before payment); INDEX `(vendor_id, status, created_at DESC)` |
| `wp_mercatus_order_allocations` | Immutable financial and quantity allocation per vendor/order line, with commission snapshot (rule, rule version, flat basis, amount; COM-3, COM-5) | PK `id`; UNIQUE `(parent_woo_order_id, order_item_id, vendor_id)`; INDEX `(vendor_order_id)`; INDEX `(vendor_id, created_at)` |
| `wp_mercatus_allocation_adjustments` | Append-only adjustments to allocations: refunds, cancellations, item changes after payment (ORD-3, ARCH-18) | PK `id`; UNIQUE `adjustment_uuid`; INDEX `(allocation_id)`; INDEX `(vendor_order_id, created_at)` |
| `wp_mercatus_order_fulfilments` | Shipment records: one package from one vendor order, status and dates (ORD-16) | PK `id`; INDEX `(vendor_order_id, location_id, status)` |
| `wp_mercatus_order_fulfilment_items` | Items and quantities in each shipment (ORD-16) | PK `id`; UNIQUE `(fulfilment_id, allocation_id)` |
| `wp_mercatus_vendor_shipping_profiles` | Vendor ship-from settings and approval version | PK `id`; UNIQUE `vendor_id`; INDEX `(status)` |
| `wp_mercatus_shipping_zones` / `wp_mercatus_shipping_methods` | Vendor zones and approved shipping methods/rates | PK `id`; INDEX `(vendor_id, status, priority)` |
| `wp_mercatus_shipping_zone_locations` | Countries, states and postcode patterns covered by each zone (ARCH-18) | PK `id`; INDEX `(zone_id)`; INDEX `(country_code, state_code, postcode_pattern)` |
| `wp_mercatus_seller_tax_profiles` / `wp_mercatus_tax_registrations` | Vendor tax profile (rate approval status TAX-13, ship-from for tax TAX-15) and encrypted tax registration numbers (ARCH-13) | PK `id`; UNIQUE `(seller_type, seller_id, country_code, state_code)` |
| Vendor tax rates | Storage decided by SPIKE-1 (TAX-6): rows in WooCommerce's `woocommerce_tax_rates` under a per-vendor class, or `wp_mercatus_vendor_tax_rates` with reserved-range IDs | Defined by the SPIKE-1 amendment |
| `wp_mercatus_tax_snapshots` | Tax components per allocation, copied from the saved order's line taxes (TAX-12) | PK `id`; UNIQUE `(allocation_id, taxable_component, calculation_version)`; INDEX `(vendor_order_id)` |
| `wp_mercatus_commission_rules` | Commission rules; Core owns the global rule and Pro sources add theirs (COM-3) | PK `id`; INDEX `(source, scope_type, scope_id, status)` |
| `wp_mercatus_ledger_accounts` | Ledger account per owner/currency/type (vendor payable, marketplace, and Pro customer wallet types); cached `balance_minor` `BIGINT` | PK `id`; UNIQUE `(owner_type, owner_id, currency, account_type)` |
| `wp_mercatus_ledger_entries` | Immutable debit/credit journal (`amount_minor`, `running_balance_minor` `BIGINT`) | PK `id`; UNIQUE `entry_uuid`; UNIQUE `(account_id, idempotency_key)`; INDEX `(account_id, posted_at DESC)` |
| `wp_mercatus_ledger_holds` | Pending reservations against an account (`amount_minor` `BIGINT`), including earnings frozen by open refund requests (COM-13) | PK `id`; UNIQUE `hold_uuid`; INDEX `(account_id, status, expires_at)` |
| `wp_mercatus_withdrawals` | Manual withdrawal requests: requested → approved → paid, or rejected (COM-4) | PK `id`; UNIQUE `withdrawal_uuid`; INDEX `(vendor_id, status, created_at DESC)` |
| `wp_mercatus_reviews` / `wp_mercatus_review_replies` | Public store reviews and moderated replies | PK `id`; UNIQUE `(store_id, customer_id, vendor_order_id)`; INDEX `(store_id, status, created_at DESC)` |
| `wp_mercatus_api_clients` | Scoped server-to-server client credentials (Application Passwords) and IP allow-lists | PK `id`; UNIQUE `client_key_hash`; INDEX `(actor_type, actor_id, status)` |
| `wp_mercatus_events_outbox` | Transactional events pending dispatch (`lane`: `critical` \| `bulk`; `coalesce_key`; lease columns `claimed_by`, `lease_until`, PLAT-5) | PK `id`; UNIQUE `event_uuid`; INDEX `idx_outbox_poll (lane, status, available_at, id)`; INDEX `(status, lease_until)`; INDEX `(coalesce_key, status)` |
| `wp_mercatus_audit_log` | Append-only security and business audit trail | PK `id`; INDEX `(actor_id, created_at DESC)`; INDEX `(resource_type, resource_id, created_at DESC)` |
| `wp_mercatus_idempotency` | Mutation replay protection: written with status `processing` and a request hash before the command runs, then completed with the stored response (PLAT-9) | PK `id`; UNIQUE `(actor_fingerprint, route, idempotency_key)`; INDEX `(expires_at)` |

### Pro tables (`wp_mercatus_pro_*`, migrated only when the owning module is activated)

Pro modules never alter Core tables; they key their own tables to Core IDs (ORD-16, G-BND-4).

| Pro Module | Tables | Key Invariant |
|---|---|---|
| **Shipment Tracking** | `carriers`, `shipment_tracking` (keyed by Core fulfilment ID) | Vendors edit tracking only on their own vendor orders; the admin on any (ORD-15). |
| **Commissions** | Rule-source tables (category, membership, vendor, product, cart value, total sales) | `commission_rules` itself is Core (COM-3); every applied rule and version is snapshotted on the allocation and never recalculated. |
| **Payouts** | `payouts`, `payout_items`, `payment_attempts`, `reconciliation_records` | Uses the Core ledger; no second ledger and no editable balances (DRY-2); four-eyes approval support. |
| **Payment Integrations** | `vendor_payment_accounts`, `provider_transfers`, `webhook_events` | Webhooks verify signatures and reject replays; split transfers are reversed on refunds and cancellations; renewal orders are handled or refused (COM-12). |
| **Customer Wallet** | `wallet_credit_grants` (promotional credit, expiry) | Customer accounts live in the Core ledger (WAL-3); no top-up (WAL-1); refund credit only with customer consent (WAL-4). |
| **Refunds** | `refund_cases`, `refund_lines`, `refund_decisions` | References Core `allocation_id` and WooCommerce refund IDs; preserves full state history. |
| **Tax Automation** | `tax_provider_requests`, `tax_documents`, `tax_reconciliation_runs` | Stores provider request/response digests and e-invoice metadata without mutating Core `tax_snapshots`. |
| **Variable-Weight Settlement** | `line_measurements`, `line_quantity_adjustments`, `settlement_authorisations`, `customer_settlements` | Immutable target/tolerance/actual measurement, final-price calculation, customer approval and receivable tracking. |
| **Memberships** | `plans`, `subscriptions`, `entitlements`, `billing_events` | Entitlements are compiled into the cached `mercatus_capabilities` / `mercatus_actors` manifest. |
| **Multi-Location Fulfilment** | `location_memberships`, `location_delivery_zones`, `location_shipping_profiles`, `inventory_transfers`, `routing_rules` | Extends the default location to N branches/warehouses with geohash routing and inter-location stock transfers. |
| **Staff & Groups** | `groups`, `group_permissions`, `staff_assignments` | Vendor- and location-scoped permissions; never grants global WordPress roles. |
| **Delivery** | `agents`, `agent_assignments`, `delivery_jobs`, `delivery_events`, `delivery_otps` | Assignment-scoped visibility; agents cannot query unassigned orders; OTP confirmation completes the vendor order (ORD-17). |
| **Analytics** | `analytics_daily_vendor_rollups`, `analytics_product_rollups`, `analytics_exports` | Populated asynchronously through the `bulk` lane; never touched during checkout. |
| **Affiliate** | `affiliates`, `referrals`, `attributions`, `affiliate_commissions` | Attribution is immutable once locked after the attribution window. |
| **AI Suite** | `ai_requests`, `ai_usage_quotas`, `ai_approvals` | Redacts PII/secrets from prompts; requires human approval before publishing catalog or moderation actions. |

---

## 8. Identity, Authorization, & Security Controls

### Actors and authentication

Actors resolve into an immutable `ActorIdentity` independent of WordPress roles: `anonymous`, `customer`, `vendor_owner`, `vendor_staff`, `delivery_agent`, `marketplace_manager`, `administrator`, `integration_client` and `webhook_provider`.

- **Browser UI:** authenticated WordPress cookies plus `X-WP-Nonce`.
- **Server-to-server integrations:** WordPress Application Passwords mapped to `wp_mercatus_api_clients` with explicit scopes, expiry/revocation and optional IP allow-lists.
- **Mobile apps:** use Application Passwords in v1. An OAuth2/PKCE server is out of v1 (SCOPE-1) and would arrive as its own module.
- **Webhooks:** raw-body HMAC signature verification, a timestamp window and stored event-ID replay defence.
- **Background workers / CLI:** a system actor scoped to the executing worker contract.

### Authorization pipeline & cached actor context

```text
Request Authentication
  -> L1/L2 Cached ActorIdentity Resolution (mercatus_actors)
  -> API-Client Scope / Named Permission Check
  -> Module Entitlement Check (cached capability manifest)
  -> Resource Policy Check (StorePolicy, AllocationPolicy, WithdrawalPolicy, ...)
  -> Field-Level Serializer Visibility Filter
  -> Command State-Machine Transition Validation
```

Suspending a vendor or removing staff bumps their cached identity immediately, and the identity cache has a short expiry (ARCH-10).

### Mandatory security controls

- **Input validation:** JSON Schema validation (loaded only for the matched route) for shape and types; semantic validation inside Application Commands.
- **Mass-assignment protection:** command DTOs accept only explicit, actor-permitted fields. Fields such as `vendor_id`, `approved_by`, `commission_amount`, `tax_amount` or `balance_minor` are never taken from request bodies.
- **Enumeration policy:** another tenant's private resource (vendor orders, ledger accounts, withdrawals, payouts, unpublished offers) returns 404; a permission denied inside the actor's own tenant returns 403 (ARCH-6).
- **Idempotency & optimistic locking:** `Idempotency-Key` is required on financial, stock, checkout and external-provider `POST`s. The idempotency row is written before the command runs, so a repeat gets the stored response, 409 while the first request is still processing, or 422 for a different payload (PLAT-9). `If-Match` is required on `PATCH`/`PUT` and checks the entity's database `version`, returning `409 Conflict` on a mismatch (PLAT-11).
- **Rate limiting:** every route is rate-limited when a persistent object cache is present; without one there is no application-level rate limiting (PLAT-10).
- **Sensitive data:** provider API keys and tax registration numbers are encrypted with `sodium_crypto_secretbox` using the dedicated `MERCATUS_ENCRYPTION_KEY` constant with key versions, never a key derived from WordPress salts (ARCH-13). KYC documents and delivery proofs live outside the web root and are served only through short-lived signed URLs.
- **Vendor content and uploads (ARCH-14):** vendor rich text is cleaned on save with a fixed allow-list and escaped on output; uploads follow a MIME/SVG policy; outbound requests (webhooks, geocoding, AI, payment providers) use an allow-list; dashboards send a Content-Security-Policy; GDPR export and erase are supported.
- **WooCommerce capabilities:** vendors never receive WooCommerce capabilities such as `edit_products`, so they cannot bypass Mercatus policies through wp-admin or the WooCommerce REST API (ARCH-8).

---

## 9. REST API Standard & Two-Lane Outbox Architecture

### REST API conventions

- **Namespace:** `/wp-json/mercatus/v1`.
- **State-machine subresources:** explicit action verbs on subresources (`POST /mercatus/v1/withdrawals/{id}/approve`), never generic status patching.
- **Error envelope:** every error returns an HTTP status and `{ "code": "mercatus_...", "message": "...", "details": {...}, "request_id": "req_..." }`. Domain code returns the code; the API layer translates the message (ARCH-16). Stack traces are never exposed.
- **Cursor pagination:** high-volume collections use `?cursor=...&per_page=24` and return `{ "items": [...], "per_page": 24, "next_cursor": "...", "has_more": true }`.
- **OpenAPI 3.1:** every route, parameter, request body and response DTO is defined once in PHP; `openapi/mercatus-v1.yaml` is generated from those definitions and diff-checked in CI (DRY-2).

### Initial Core endpoint inventory

| Resource Domain | Endpoints |
|---|---|
| **Bootstrap & System** | `GET /me` (actor, store summary, active features), `GET /features` (ARCH-7), `GET /health`, `POST /system/cache/flush-group` (bumps the group's version, ARCH-7) |
| **Vendor Applications** | `POST /vendor-applications`, `GET /vendor-applications/{id}`, `POST /vendor-applications/{id}/approve`, `POST /vendor-applications/{id}/reject` |
| **Stores** | `GET /stores`, `GET /stores/{slug}`, `GET /me/store`, `PATCH /me/store`, `POST /stores/{id}/suspend` |
| **Catalog & Taxonomy** | `GET /catalog/categories`, `GET /catalog/categories/{id}/schema`, `GET /catalog/attributes/{id}/terms`, `GET /catalog/offers` (faceted projection), `GET /catalog/products/{id}/offers` |
| **Vendor Products & Offers** | `GET /me/products`, `POST /me/products`, `GET /me/products/{id}`, `PATCH /me/products/{id}`, `DELETE /me/products/{id}`, `PATCH /me/offers/{id}/inventory` |
| **Media** | `POST /me/media`, `GET /me/media`, `DELETE /me/media/{id}` |
| **Shipping & Tax** | `GET`/`PATCH /me/shipping-profile`; `GET`/`POST`/`PATCH`/`DELETE /me/shipping-zones`; `GET`/`PATCH /me/tax-profile`; `GET`/`POST`/`PATCH`/`DELETE /me/tax-rates` (store tax, TAX-1); `POST /vendors/{id}/tax-rates/approve` (admin, TAX-13); `POST /me/shipping-tax/test-quote` |
| **Orders & Fulfilment** | No Mercatus cart or checkout endpoints (Store API and the order-pay URL, SCOPE-1, DRY-5). `GET /me/orders`, `GET /me/orders/{id}`, `POST /me/orders/{id}/fulfilment-status` (shipped, delivered, completed), `GET`/`POST`/`PATCH /me/orders/{id}/shipments` (ORD-16), `POST /orders/{parent_id}/vendor-orders/{id}/confirm-receipt` (customer, ORD-17), `GET /orders/{parent_id}/allocations`, `POST /orders/{parent_id}/complete`, `POST /orders/{parent_id}/cancel` (admin, ORD-14) |
| **Earnings & Withdrawals** | `GET /me/earnings`, `GET /me/ledger/entries`, `GET /me/withdrawals`, `POST /me/withdrawals`, `POST /withdrawals/{id}/approve`, `POST /withdrawals/{id}/reject`, `POST /withdrawals/{id}/mark-paid` (admin, COM-4) |
| **Reviews** | `GET /stores/{slug}/reviews`, `POST /stores/{slug}/reviews`, `POST /reviews/{id}/reply`, `POST /reviews/{id}/moderate` |

Commission, completion, earnings-release and tax settings are exposed through the typed settings registry (DRY-2). Customer wallet endpoints belong to the Pro wallet module (WAL-2).

### Two-lane pull-based outbox worker

Commands write domain state, `wp_mercatus_audit_log` and `wp_mercatus_events_outbox` in one transaction over Mercatus tables (ARCH-4), then bump cache revisions after commit. Money changes never run in outbox handlers (ARCH-5). Critical handlers run right after commit, with the poller as the retry net. Every committed event is also passed to WordPress through the single `do_action()` bridge (ARCH-15).

- **No 1:1 Action Scheduler rows:** a recurring worker (Action Scheduler or the `wp mercatus outbox:work` daemon) claims events in batches of 200 with a lease, which works on every supported MySQL/MariaDB version and recovers crashed workers (PLAT-5):
  ```sql
  UPDATE wp_mercatus_events_outbox
     SET claimed_by = %s, lease_until = UTC_TIMESTAMP(3) + INTERVAL 60 SECOND
   WHERE lane = %s AND status = 'pending' AND available_at <= UTC_TIMESTAMP(3)
     AND (lease_until IS NULL OR lease_until < UTC_TIMESTAMP(3))
   ORDER BY id ASC
   LIMIT 200;

  SELECT * FROM wp_mercatus_events_outbox
   WHERE claimed_by = %s AND status = 'pending'
   ORDER BY id ASC;
  ```
- **Two priority lanes:**
  1. `critical`: child order creation (ORD-9), payment and payout webhook processing, payout transitions, notifications customers wait for.
  2. `bulk`: `wp_mercatus_offer_availability` and `wp_mercatus_catalog_facets` rebuilds, search indexing, analytics rollups, other notifications, AI tasks.
- **Coalescing:** on the `bulk` lane, pending events sharing a `coalesce_key` (for example `offer_proj:1045`) within a batch run once.

---

## 10. Testing Architecture & Module-by-Module Test Mandate

> **Strict engineering rule:** no feature, bugfix, migration or endpoint is implemented until its tests are written and passing across every applicable layer below.

### The 7-layer automated test pyramid

#### Layer 1: Architecture & static analysis (`tests/Architecture/`)
- **Tools:** PHPStan (level 8+), PHP_CodeSniffer with WordPress-Extra minus the file-name and naming sniffs (ARCH-3), Deptrac (ARCH-2).
- **Enforced on every commit:**
  - `Mercatus\Core\{Module}\Domain\*` and `Application\*` have zero dependencies on `WP_REST_Request`, `WC_Product`, `WC_Order`, `$wpdb` or WordPress translation functions.
  - `Mercatus\Core\*` has zero references to `Mercatus\Pro\*`; `Mercatus\Pro\*` imports only `Mercatus\Core\Contracts\*`.
  - Controllers never import `$wpdb` or repositories.
  - No direct Redis/Memcached client classes are referenced (PLAT-2).
  - Every Command handler and Policy class has a matching `*Test.php`.

#### Layer 2: Unit tests (`tests/Unit/`)
- **Environment:** pure PHP 8.2+ in memory, no database, no WordPress bootstrap; under 5 ms per test.
- **Covers:** minor-unit money arithmetic and largest-remainder allocation so $\sum \text{Child} = \text{Parent}$ (for example ₹100 split three ways = ₹33.34, ₹33.33, ₹33.33); the commission engine, including flat basis and coupon/shipping/tax settings (COM-5 to COM-8); state machines (vendor orders, fulfilment status, completion triggers ORD-17, withdrawals, holds); earnings release rules (COM-13); rate-resolver selection with store tax on and off, approval and fallback (TAX-9, TAX-13, TAX-14); variable-weight settlement math; policy decisions across all 9 actor types; cache-key generation including pointer seeding (PLAT-4); geohash neighbour cells.

#### Layer 3: Contract & conformance tests (`tests/Contract/`)
- **OpenAPI:** the generated `openapi/mercatus-v1.yaml` matches the committed file, and every route's request and response validates against it (DRY-2).
- **SDK conformance traits** that every Core and Pro adapter must pass: `CachePortConformanceTrait`, `CoordinationPortConformanceTrait`, `TaxRateResolverConformanceTrait`, `PayoutProviderConformanceTrait`, `CatalogSearchPortConformanceTrait`.
- **Event schemas:** every outbox event payload validates against its versioned JSON Schema.

#### Layer 4: Database, cache & WooCommerce integration tests (`tests/Integration/`)
- **Environment:** MySQL 8.0 and 8.4 LTS, MariaDB 10.6, 10.11 and 11.4 (PLAT-6); WordPress 6.6+; WooCommerce 9.2+ with HPOS; run with Redis, with Memcached, and with no persistent object cache (PLAT-7).
- **Covers:** migrations (up, idempotent re-run, checksums, Pro lazy migration); repositories against real tables with `EXPLAIN` assertions (no `type = ALL`, no `Using filesort` on hot queries); `CachePort` version pointers, multi-get, singleflight locks and behaviour without a persistent cache; `CoordinationPort` with and without a persistent cache (PLAT-8); idempotency under concurrent duplicate requests (PLAT-9); ETags that stay stable across requests without a persistent cache (PLAT-11); the two-stage split on HPOS (no duplicate stock deductions or emails; children hidden per ORD-5); WooCommerce tax integration (per-item rates, snapshots from saved order taxes, the TAX-4 recalculation safety net, displayed prices matching the cart, as in SPIKE-1); outbox lease claiming with two parallel workers (PLAT-5).

#### Layer 5: Security & authorization matrix (`tests/Security/`)
- **Environment:** an HTTP security harness whose actor × resource × endpoint matrix is generated from the route registry, so new routes are covered automatically (DRY-4).
- **Covers:** IDOR/BOLA (vendor A against vendor B's store, products, offers, media, child orders, shipments, shipping zones, tax profile and rates, earnings and withdrawals → 404, ARCH-6); privilege escalation and staff scope; mass-assignment fuzzing; missing nonce, idempotent replay and stale `If-Match` (→ 409); PII and secret redaction; rich-text and upload policy (ARCH-14); vendors unable to edit products through the WooCommerce REST API (ARCH-8).

#### Layer 6: Scale gates (`tests/Scale/`)
- **Environment:** a nightly stage restored from a saved database dump of `wp mercatus seed:scale` data (2,000 vendors, 5,000 categories, 2,000 attributes, 100,000 products, 300,000 offers), with and without a persistent object cache (PLAT-7).
- **Covers:** counts Mercatus's own queries around critical endpoints and fails the build when a query ceiling in Section 6 is exceeded. Latency (`hrtime(true)`) is recorded and trended, not used as a blocking gate.

#### Layer 7: End-to-end & JS/UI tests (`tests/EndToEnd/`, `tests/Js/`)
- **Covers:** full API acceptance journeys and browser journeys for checkout, vendor order handling, completion and withdrawals, plus JS unit tests for first-party UIs (ARCH-19). Tooling is chosen with the Phase 1 scaffold.

### Module-by-module mandatory test matrix

Every module ships with these test classes before its ticket can close. Ticket names in Section 12 use the same class names (ARCH-19). Three cross-cutting tests belong to a layer, not a module: `DependencyRulesTest` (Layer 1), `OpenApiSchemaConformanceTest` (Layer 3) and `BaselineQueryAndLatencyGateTest` (Layer 6).

| Module / Bounded Context | Unit (`tests/Unit/...`) | Integration & Contract | Security & Scale |
|---|---|---|---|
| **1. Kernel, Settings & Migrations** | `ModuleManifestValidatorTest`, `VersionCompatibilityTest`, `FeatureManifestCompilerTest`, `SettingsRegistryTest` | `MigrationRunnerTest`, `FeaturesEndpointContractTest` | `HealthEndpointAuthTest`, `ZeroQueryKernelBootTest`, `InactiveModuleZeroFootprintTest` |
| **2. Caching & Coordination (`CachePort`, `CoordinationPort`)** | `VersionedCacheKeyResolverTest`, `SoftExpiryStampedeCalculatorTest`, `EtagFromVersionTest` | `CachePortRedisTest`, `CachePortMemcachedTest`, `CachePortNoPersistentCacheTest`, `CoordinationPortNoPersistentCacheTest`, `BatchMultiGetIntegrationTest`, `SingleflightLockConcurrencyTest` | `CacheFlushAuthorizationTest`, `WarmCacheZeroSqlBenchmarkTest` |
| **3. Outbox & Async Worker** | `EventPayloadSerializerTest`, `BulkEventCoalescerTest`, `RetryBackoffPolicyTest` | `OutboxTransactionalWriteTest`, `LeaseClaimParallelWorkerTest`, `CriticalVsBulkLanePriorityTest`, `DomainEventActionBridgeTest` | `CronContextSpoofingTest`, `OutboxBatchThroughputBenchmarkTest` (1,000 events < 2 s) |
| **4. Identity & Policy Engine** | `ActorIdentityResolverTest`, `PolicyEngineMatrixTest` | `CachedActorIdentityInvalidationTest`, `ApiClientScopeIntegrationTest`, `IdempotencyStoreIntegrationTest` | `IdorActorMatrixTest`, `CsrfNonceEnforcementTest`, `RateLimiterTest` |
| **5. Vendor & Storefront** | `VendorLifecycleStateMachineTest`, `StoreSlugNormalizerTest`, `StorePolicyTest` | `VendorApplicationWorkflowTest`, `DefaultLocationAutoCreationTest`, `StoreRepositoryTest` | `StoreCrossTenantIsolationTest`, `StorePiiFieldRedactionTest`, `StorefrontEdgeCacheHeaderTest` |
| **6. Catalog, Attributes & Projections** | `TwoTierAttributePolicyTest`, `MaterializedCategoryPathTest`, `EffectivePriceResolverTest`, `CatalogPolicyTest` | `WooProductCrudAdapterTest`, `OfferAvailabilityProjectionSyncTest`, `ProjectionDriftReconcilerTest`, `CatalogFacetIndexQueryTest`, `MediaOwnershipQuotaTest` | `ProductMassAssignmentOwnershipTest`, `MediaCrossVendorDeleteTest`, `HundredThousandProductFacetBenchmarkTest` |
| **7. Shipping & Tax** | `TaxRateResolverSelectionTest`, `StoreTaxPolicyTest`, `ShippingZoneMatcherTest`, `ProRataDiscountTaxRoundingTest` | `WooCartItemTaxRatesTest`, `TaxSnapshotFromOrderLinesTest`, `OrderRecalculationTaxSafetyNetTest`, `VendorShippingTaxTestQuoteEndpointTest` | `UnapprovedTaxRatesFallbackTest`, `VendorGlobalWooSettingsIsolationTest`, `DisplayedPriceMatchesCartTaxTest` |
| **8. Order Split, Status & Completion** | `DeterministicOrderAllocationMathTest`, `ParentStatusAggregatorTest`, `AllocationPolicyTest`, `CompletionTriggerTest` | `StageOnePrePaymentSnapshotTest`, `StageTwoChildOrderMaterializationTest`, `IdempotentOrderSplitRetryTest`, `StockSingleDeductionTest`, `ChildOrderExposureTest`, `AdminParentOverrideCascadeTest` | `VendorParentOrderAccessDeniedTest`, `CrossVendorChildOrderAccessDeniedTest`, `MultiVendorCheckoutLatencyBenchmarkTest` |
| **9. Commission, Ledger & Withdrawals** | `CommissionEngineTest`, `MinorUnitLedgerMathTest`, `EarningsReleaseRuleTest`, `WithdrawalStateMachineTest` | `LedgerJournalOptimisticLockTest`, `CommissionSnapshotImmutabilityTest`, `RefundClawbackNegativeBalanceTest` | `WithdrawalIdorTest`, `ConcurrentWithdrawalDoubleSpendTest`, `LedgerBalancePatchRouteAbsenceTest` |
| **10. Pro: Shipment Tracking** | `TrackingUrlTemplateTest`, `ShipmentTrackingPolicyTest` | `CsvTrackingImportTest`, `InactiveModuleZeroFootprintTest` | `CrossVendorTrackingUpdateBlockTest` |
| **11. Pro: Payouts & Payment Integrations** | `PayoutScheduleTest`, `SplitShareCalculatorTest`, `FourEyesApprovalPolicyTest` | `PayoutProviderConformanceTest`, `WebhookSignatureReplayTest`, `SplitRefundReversalTest`, `RenewalOrderSplitTest` | `UnentitledProFinanceRouteBlockTest`, `SelfApprovalPreventionTest`, `DuplicatePayoutWebhookReplayTest` |
| **12. Pro: Variable-Weight Settlement** | `WeightToleranceValidatorTest`, `PostWeighFinalPriceMathTest`, `CustomerSettlementReceivableTest` | `InactiveModuleZeroFootprintTest`, `MaxAuthCaptureActualFlowTest`, `LowerActualRefundFlowTest`, `HigherActualReceivableHoldPayoutTest` | `OutOfToleranceUnapprovedChargeBlockTest`, `PostLockMeasurementTamperTest` |
| **13. Pro: Multi-Location, Staff & Advanced Catalog** | `GeohashNeighbourCellsTest`, `LocationRoutingPriorityTest`, `StaffLocationScopePolicyTest` | `MultiLocationOfferAvailabilityTest`, `InventoryTransferTransactionTest`, `ExternalSearchPortConformanceTest` | `StaffCrossLocationMutationBlockTest`, `GeoSearchHundredThousandOfferBenchmarkTest` |
| **14. Pro: Customer Wallet** | `MinorUnitWalletMathTest`, `RefundToWalletConsentTest` | `WalletPartialPaymentAfterTaxTest` (SPIKE-2), `InactiveModuleZeroFootprintTest` | `WalletTopUpRouteAbsenceTest`, `ConcurrentWalletSpendRaceConditionTest` |

---

## 11. Phased Build Roadmap (With Mandatory Test Gates)

Phases are built as vertical slices: each phase delivers working features end to end, infrastructure arrives when the first feature needs it, and the scale seeder grows module by module (ARCH-20). No phase advances until 100% of its implementation and test gates pass in CI. Each major module starts with its decision file (PRIN-3).

### Phase 0 — Product boundary, threat model, contracts & spikes (1–2 weeks)
1. Freeze `Mercatus\Core\Contracts\*`: `ModuleManifest`, `CachePort`, `CoordinationPort`, `TaxRateResolver`, `StoreTaxPolicy`, `CommissionRuleSource`, `OrderCompletionPolicy`, `ChildOrderExposure`, `PayoutProvider`, `CatalogSearchPort`.
2. Set up OpenAPI generation from the PHP API definitions (DRY-2) and the actor–resource policy matrix.
3. Complete a STRIDE and OWASP API Top 10 threat model and map every threat to a test in `tests/Security/`.
4. Run SPIKE-1 and record the TAX-6 outcome as an amendment.
- **Exit gate:** contracts and generated OpenAPI pass lint and schema validation; TAX-6 is resolved.

### Phase 1 — Foundation (2–3 weeks)
1. Scaffold `mercatus` and `mercatus-pro`: Composer with Strauss/PHP-Scoper (ARCH-12), PHPStan level 8, PHPCS (ARCH-3), Deptrac, PHPUnit suites and JS test tooling.
2. Implement the Kernel, typed settings registry, module registry, migration runner, `CachePort` and `CoordinationPort`.
3. Implement the lease-based outbox (PLAT-5), event `do_action()` bridge, audit log, idempotency store, rate limiter, Application Password API clients, cached `ActorIdentity` and `/mercatus/v1/me`.
4. Build the scale seeder skeleton and the nightly database-dump pipeline.
- **Exit gate:** all tests for modules 1–4 pass in CI, with and without a persistent object cache.

### Phase 2 — Vendors & storefronts (2–3 weeks)
1. Vendor application/approval state machine, one public storefront per vendor, automatic default fulfilment location with geohash, `StorePolicy`, and cacheable `/stores` APIs. The seeder adds vendors.
2. A thin first-party admin/vendor store UI backed by `/mercatus/v1/me` and `/mercatus/v1/stores`.
- **Exit gate:** all module 5 tests pass, including IDOR and warm-cache zero-SQL tests.

### Phase 3 — Catalog, two-tier attributes & projections (3–4 weeks)
1. `wp_mercatus_catalog_products`, `wp_mercatus_vendor_offers`, `wp_mercatus_media_ownership`, the WooCommerce product CRUD adapter, and projection drift reconciliation (ARCH-9).
2. Tier 2 attributes and values, categories index, facets (ARCH-11) and the `wp_mercatus_offer_availability` projection with SSR hydration. The seeder adds products and offers.
- **Exit gate:** all module 6 tests pass, including the 100,000-product facet ceilings with no `wp_postmeta` or `wp_term_relationships` joins.

### Phase 4 — Orders, shipping, tax, commission & Free MVP (4–6 weeks)
1. Vendor shipping and tax settings, the test-quote endpoint, `StoreTaxPolicy`, and the WooCommerce-rates and vendor-store-rates resolvers (TAX-2, TAX-9 to TAX-15).
2. Stage 1 allocation for every order creation path and Stage 2 child orders for every paid order (ORD-8, ORD-9, ORD-11); fulfilment status, shipment records, completion triggers and admin parent overrides (ORD-12 to ORD-14, ORD-16, ORD-17).
3. Commission engine (COM-1, COM-3, COM-5 to COM-10), ledger (WAL-3), earnings release (COM-13) and manual withdrawals (COM-4).
- **Exit gate:** all module 7, 8 and 9 tests pass, including multi-vendor checkout reconciliation, withdrawal double-spend and cross-vendor order isolation. **First shippable Free MVP.**

### Phase 5 — First Pro release & finance (in this order)
1. Pro licensing/entitlement gate, with zero runtime footprint for inactive modules.
2. Shipment Tracking with bulk CSV upload (ORD-15).
3. Finance: commission rule sources (COM-2), payouts automation, Stripe and PayPal payout and split modes (COM-11, COM-12), refund workflows, variable-weight settlement and tax automation.
- **Exit gate:** all module 10, 11 and 12 tests pass, including historical commission immutability, ledger reconciliation and webhook replay protection.

### Phase 6 — Further Pro modules (one bounded context at a time)
1. Staff & Groups → 2. Multi-Location Fulfilment → 3. Memberships → 4. Delivery (including OTP confirmation) → 5. Analytics → 6. Affiliate → 7. Advanced Catalog (SPMV, bulk importer, external search adapters) → 8. AI Suite. The Customer Wallet (WAL-2) also belongs to Pro; its position in this sequence is not decided yet.
- **Exit gate per module:** decision file, contract, migration, inactive zero-footprint, tenant security matrix and scale tests pass before the next module starts.

---

## 12. First 14 Implementation Tickets (With Mandatory Test Deliverables)

1. **Repo scaffold & static analysis:** create `mercatus` and `mercatus-pro` with Composer and Strauss/PHP-Scoper (ARCH-12), a CI matrix covering the PLAT-6 databases and the PLAT-7 cache modes, PHPStan level 8, PHPCS (ARCH-3), Deptrac layer rules (ARCH-1) and `tests/Architecture/DependencyRulesTest.php`.
2. **Contracts, ADR & generated OpenAPI:** commit `docs/architecture/decisions/0001-api-first-modular-monolith.md`, `Mercatus\Core\Contracts\*` with conformance traits, OpenAPI generation (DRY-2) with `tests/Contract/OpenApiSchemaConformanceTest.php`, and `tests/Security/PolicyMatrixDataProvider.php` generated from the route registry (DRY-4).
3. **SPIKE-1, tax rate storage:** run the SPIKE-1 checks for options (a) and (b) and record the TAX-6 amendment.
4. **Kernel, settings registry & module manifest:** `tests/Unit/Kernel/ModuleManifestValidatorTest.php`, `SettingsRegistryTest.php`, `tests/Integration/Kernel/InactiveModuleZeroFootprintTest.php`.
5. **`CachePort` and `CoordinationPort`:** `CachePortRedisTest.php`, `CachePortMemcachedTest.php`, `CachePortNoPersistentCacheTest.php`, `CoordinationPortNoPersistentCacheTest.php`, `SingleflightLockConcurrencyTest.php`, `EtagFromVersionTest.php`.
6. **Migration runner & lease-based outbox:** `MigrationRunnerTest.php`, `LeaseClaimParallelWorkerTest.php`, `DomainEventActionBridgeTest.php`.
7. **Actor identity, policy engine, audit log & `/me`:** `PolicyEngineMatrixTest.php`, `CachedActorIdentityInvalidationTest.php`.
8. **Base REST controller, API clients, rate limiting & idempotency:** error envelope, cursor pagination, ETag/If-Match, Cache-Control, Application Password scopes; `IdempotencyStoreIntegrationTest.php`, `RateLimiterTest.php`, `ApiClientScopeIntegrationTest.php`.
9. **Scale seeder skeleton & dump pipeline:** `wp mercatus seed:scale` with per-module seeders and `tests/Scale/BaselineQueryAndLatencyGateTest.php`.
10. **Vendor lifecycle, storefront & default location:** `wp_mercatus_vendors`, `wp_mercatus_stores`, `wp_mercatus_fulfilment_locations` (geohash), `StorePolicy`; `VendorApplicationWorkflowTest.php`, `StoreCrossTenantIsolationTest.php`. The seeder adds vendors.
11. **Catalog, two-tier attributes & availability projection:** `CatalogFacetIndexQueryTest.php`, `ProjectionDriftReconcilerTest.php`, `HundredThousandProductFacetBenchmarkTest.php`. The seeder adds products and offers.
12. **Vendor shipping & tax:** `WooCartItemTaxRatesTest.php`, `OrderRecalculationTaxSafetyNetTest.php`, `DisplayedPriceMatchesCartTaxTest.php`.
13. **Order split, child orders, fulfilment & completion:** `StageOnePrePaymentSnapshotTest.php`, `StageTwoChildOrderMaterializationTest.php`, `ChildOrderExposureTest.php`, `CompletionTriggerTest.php`.
14. **Commission, ledger, earnings release & manual withdrawals:** `CommissionEngineTest.php`, `EarningsReleaseRuleTest.php`, `ConcurrentWithdrawalDoubleSpendTest.php`.

---

## 13. Locked Architectural Decisions

`DECISIONS.md` is the locked decision record for everything decided in the design review (PRIN-3). The constitution-level decisions below are not repeated there:

1. **Namespaces & slugs:** `mercatus` and `mercatus-pro` plugin slugs; `Mercatus\Core\` and `Mercatus\Pro\` PHP namespaces; `/wp-json/mercatus/v1` REST namespace; `wp_mercatus_*` and `wp_mercatus_pro_*` tables; `mercatus_*` cache groups.
2. **Pro packaging:** a single `mercatus-pro` plugin zip containing modular bounded contexts; inactive modules have zero runtime, hook, route or schema footprint.
3. **Required stack:** PHP 8.2+, WordPress 6.6+, WooCommerce 9.2+ with HPOS mandatory, MySQL 8.0+ / MariaDB 10.6+ (PLAT-6). A Redis or Memcached persistent object cache is optional and used when present (PLAT-1); without one, rate limiting is off (PLAT-10).
4. **Authentication:** WP cookie + nonce for browser UIs; Application Passwords (`wp_mercatus_api_clients`) for server-to-server and, in v1, mobile clients. OAuth2/PKCE is out of v1 (SCOPE-1).
5. **Money representation:** `BIGINT` minor units plus a `CHAR(3)` ISO currency code for all balances, holds, ledger entries and order/allocation/tax totals; `DECIMAL(20,6)` for unit prices, tax rates and percentages.
6. **Clean-install policy:** version 1 is a clean-install product with zero WCFM legacy bridges.
7. **Zero-untested-code policy:** every PR must pass all applicable layers of the 7-layer pyramid (Section 10).
8. **DRY & configurability:** one source of truth per fact and one shared-kernel implementation per cross-cutting concern (DRY-1 to DRY-3); business-model rules are admin settings (PRIN-1); deferred features get extension seams (PRIN-2).
