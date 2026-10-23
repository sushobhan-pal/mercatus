# Mercatus API-First Marketplace — Master Architecture & Testing Constitution

> **How to use this document with AI Coding & Review Agents:**
> This document is the single source of truth and architectural constitution for **Mercatus** (Core) and **Mercatus Pro**. Every human developer and AI agent writing, refactoring, or reviewing code must validate every file against the rules, schemas, boundaries, performance budgets, and mandatory test suites defined below. Any code that violates a rule in **Section 1 (AI Agent Code-Review Guardrails)** or lacks the required test coverage in **Section 10 (Testing Architecture & Module Test Suites)** must be rejected immediately.

---

## 1. AI Agent Code-Review Guardrails (Automatic Rejection Triggers)

When reviewing any Pull Request, commit, or generated code step, an AI agent or human reviewer **MUST REJECT** the code if any of the following violations exist:

1. **Architecture & Boundary Violations:**
   - A REST Controller contains business rules, state transitions, or direct `$wpdb` / SQL queries instead of delegating to an Application Command or Query.
   - Domain or Application classes access `$_POST`, `$_GET`, `$_REQUEST`, `$_SERVER`, call `wp_send_json*()`, or output HTML.
   - Any class in `Mercatus\Core\` imports or references a class in `Mercatus\Pro\`.
   - Any class in `Mercatus\Pro\` imports or references `Mercatus\Core\Internal\*`, directly queries a database table owned by Core or another Pro module, or reads private options instead of using `Mercatus\Core\Contracts\*`.
   - An in-process PHP component (Gutenberg block, SSR template, WooCommerce hook, CLI command, or queue worker) makes an HTTP loopback call (`wp_remote_request`) to `/wp-json/mercatus/v1` instead of invoking the Application Command/Query service directly in memory.
2. **Database & Scale Violations:**
   - Storefront product lists, category pages, attribute filters, or vendor dashboards use `WP_Query`, `get_posts()`, `wc_get_products()`, or join `wp_postmeta` / `wp_term_relationships` instead of querying `wp_mercatus_offer_availability` or `wp_mercatus_catalog_facets` (and L2 Redis/Memcached cache).
   - Product filtering/specification attributes (Tier 2) are registered as global WordPress `pa_*` taxonomies instead of rows in `wp_mercatus_attributes` and `wp_mercatus_catalog_facets`.
   - Any list endpoint executes queries inside a loop ($N+1$ query violation) or uses `OFFSET` pagination on high-volume tables (products, offers, orders, media, wallet entries) instead of cursor pagination (`next_cursor`).
   - Any code writes directly to WooCommerce-owned tables (`wp_wc_orders*`, `wp_posts`, `wp_postmeta`) bypassing WooCommerce CRUD / HPOS data stores.
   - Any code relies on legacy WooCommerce `wp_posts` order storage instead of HPOS (`custom_order_tables`).
   - An event publisher schedules 1:1 `ActionScheduler` database rows per event instead of writing to `wp_mercatus_events_outbox` for batch-polling consumption.
3. **Caching Violations:**
   - Any service calls `wp_cache_flush()` or uses wildcard key scans (`KEYS *`) instead of incrementing version-pointer keys via `CachePort`.
   - Authenticated endpoints resolve `ActorIdentity` (vendor ownership, staff permissions, module entitlements) via live SQL joins on every request instead of reading from the `mercatus_actors` / `mercatus_capabilities` cache groups.
4. **Financial & Tax Violations:**
   - Wallet balances, holds, ledger entries, order allocations, or commissions are stored or calculated in PHP `float` instead of `int` (`BIGINT` minor currency units + `CHAR(3)` ISO currency code).
   - Unit prices, percentage rates, or tax rates use `float` instead of exact decimal strings backed by `DECIMAL(20,6)`.
   - Child `WC_Order` objects are materialized *before* payment authorization/capture (or COD confirmation), or historical tax/commission snapshots are recalculated when rules change.
   - Any table has an editable `balance` column without an immutable append-only journal entry and optimistic `version` check.
5. **Security Violations:**
   - Controllers use scattered `current_user_can()` checks instead of invoking a dedicated `Policy` class (`StorePolicy`, `AllocationPolicy`, `PayoutPolicy`, etc.).
   - Dynamic SQL values are interpolated without `$wpdb->prepare()`, or `ORDER BY` / column identifiers are not resolved from a strict static allow-list map.
   - Financial, stock-mutating, or external-provider endpoints lack `Idempotency-Key` enforcement, or mutable resource updates (`PATCH`/`PUT`) lack `If-Match` / `ETag` optimistic concurrency checks.
6. **Testing Violations (Zero-Tolerance Policy):**
   - Any new Command, Query, Policy, Repository, Controller, Migration, or Module is submitted without its mandatory Unit, Contract, Integration, and Security test files.
   - Any test suite mocks `$wpdb` SQL queries for repository tests instead of running against the integration test database.
   - Query-count assertions or $p_{95}$ latency assertions regress on the seeded scale benchmark.

---

## 2. Purpose, Product Split, and Core Decisions

Build a new, standalone marketplace platform as two installable WordPress plugins:

- **Mercatus** (free): the stable marketplace platform, public/admin/vendor APIs, extension SDK, security system, multi-tier caching engine (L1 memory + L2 Redis/Memcached + L3 HTTP Edge), core data ownership, and the essential vendor, store, high-scale catalog, tax, shipping, wallet, and two-stage order-allocation workflow.
- **Mercatus Pro** (paid): a single installable plugin package containing independently activatable modules that depend on Mercatus and extend it strictly through published contracts. It contains commercial features, advanced workflows, finance automation, integrations, and entitlement checks.

`API-first` means every first-party UI calls the same versioned REST API available to approved third-party clients. It does **not** mean domain rules live in controllers. Controllers adapt HTTP requests to application commands and queries; domain and application services own all rules. WordPress and WooCommerce are infrastructure adapters around that core.

Mercatus is 100% independent of WCFM. It has no runtime dependency, shared PHP namespace, shared database tables, compatibility layer, data migration bridge, or direct integration with WCFM. It uses its own namespace, schema, API, settings, roles/capabilities, and release cycle.

### Explicit non-goals for version 1

- Rebuild every historical setting, filter, CSS template, payment gateway, and third-party integration.
- Make the REST API a proxy to `admin-ajax.php` or mutate `$_POST` to reuse a different plugin's controllers.
- Let a paid plugin replace, subclass, or patch Core internals.
- Store financial values in floating point or stringly typed mega-tables.
- Support WooCommerce's legacy `wp_posts` order tables; require HPOS (`custom_order_tables`) from Day 1.
- Import, synchronise, or otherwise link WCFM data and functionality.

### Design constraints & governing architecture rules

1. **Blazing fast at high scale ($1,000\text{s}$ of vendors, $100,000\text{--}500,000+$ products, $1,000\text{s}$ of categories and attributes):** avoid redundant work, network round trips to the same WordPress site, unbounded queries, `wp_postmeta` / `wp_term_relationships` multi-join scans, and synchronous work that can run asynchronously. Define performance budgets and profile real high-scale marketplace paths in CI.
2. **API-first and headless-ready:** every business action is a documented, permission-checked Mercatus API command/query. Admin, vendor, customer, and future headless/mobile clients use the same application services and policies; the UI is a client, never the source of business rules.

### API-first execution rules (zero-overhead guarantee)

1. **In-process PHP calls use Application Services directly:** Server-rendered storefront blocks, WooCommerce checkout hooks, CLI commands, and background workers invoke Application Commands/Queries directly in memory—never via an HTTP loopback to `/wp-json/mercatus/v1`.
2. **SSR initial state hydration:** Buyer-facing pages hydrate initial above-the-fold data in the first PHP response using the exact same DTO serializer output as the REST API, avoiding client-side HTTP waterfalls on first paint.
3. **Coarse-grained portal bootstrap:** First-party vendor and admin apps load initial actor identity, capabilities, and store context in a single `/mercatus/v1/me` bootstrap call rather than firing parallel startup requests that exhaust PHP-FPM workers.
4. **Fast-path CQRS reads:** Read queries (`GET`) query denormalized Mercatus read projections and Redis/Memcached cache layers, serializing directly to response DTOs without hydrating rich domain aggregates or instantiating `WC_Product` / `WC_Order` CRUD objects in loops.

### WooCommerce boundary: Ecosystem compatibility vs. Mercatus CQRS scale

Mercatus retains WooCommerce strictly for ecosystem compatibility—specifically $100+$ payment gateways and specialized third-party product-type plugins (Bookings, Subscriptions, Auctions).

- **Write path & checkout payment:** Use supported WooCommerce CRUD/domain objects, HPOS data-store abstractions, and checkout/payment hooks. Never write WooCommerce-owned tables directly.
- **Read path, catalog browsing, & multi-vendor logic:** Never use `WP_Query`, `wc_get_products()`, or `wp_term_relationships` joins for storefront browsing, category/attribute filtering, offer selection, or vendor dashboards. Mercatus-owned read projection tables (`wp_mercatus_offer_availability`, `wp_mercatus_catalog_facets`) and Redis/Memcached caches serve all high-volume read traffic using prepared, indexed SQL behind repositories.
- **Gateway compatibility bridge:** Hook into WooCommerce Store API (`ExtendSchema`, `woocommerce_store_api_checkout_update_order_from_request`) for modern block-compatible gateways, and provide a thin Legacy Gateway Bridge endpoint (`POST /mercatus/v1/checkout/orders/{parent_id}/pay`) that initializes the WooCommerce session and invokes `$gateway->process_payment($parent_id)` so classic `WC_Payment_Gateway` plugins work seamlessly with API-first checkouts.

---

## 3. Target Architecture, Project Layout, & Module Contracts

### Layers and inward dependency rule

```text
Browser / Mobile / Partner Client / SSR Gutenberg Block
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
 WordPress, WooCommerce (HPOS), Redis/Memcached, MySQL, Action Scheduler
```

Dependencies point strictly inward.

### Repository and directory layout (including mandatory test suites)

Use two repositories/distributions (`mercatus` and `mercatus-pro`), with a shared contract package generated from Core.

```text
mercatus/
  mercatus.php
  composer.json
  phpstan.neon
  deptrac.yaml
  phpunit.xml.dist
  src/
    Kernel/                 # plugin boot, module registry, config, migration runner
    Contracts/              # public SDK; semantic-versioned (CachePort, SearchPort, TaxProvider, etc.)
    Identity/               # actors, capabilities, policies, API clients, OAuth2/PKCE adapter
    Vendor/                 # vendor lifecycle and assignments
    Store/                  # profile, visibility, public storefront data
    Catalog/                # ownership, 2-tier attributes, facets, projections; Woo adapter
    Orders/                 # 2-stage vendor allocations/read models; Woo HPOS adapter
    Tax/                    # TaxContext, ManualTaxProvider, Woo rate bridge
    Shipping/               # vendor shipping profiles, zones, methods, quotes
    Wallet/                 # accounts, immutable minor-unit entries, holds
    Reviews/
    Notifications/
    Api/                    # REST controllers, lazy schemas, response/error mapping
    Infrastructure/         # wpdb repositories, Cache/Redis/Memcached adapter, WP/WC adapters, outbox worker
    Internal/               # private implementation helpers; forbidden to Pro and external plugins
  database/
    migrations/
    seeders/                # includes ScaleBenchmarkSeeder (wp mercatus seed:scale)
  openapi/
    mercatus-v1.yaml
  tests/
    Architecture/           # Deptrac / Pest architecture rules verifying layer & plugin isolation
    Unit/                   # Fast (<5ms), zero-DB tests for domain logic, money math, policies, state machines
    Contract/               # SDK interface conformance, OpenAPI schema validation, Event payload schemas
    Integration/            # Real MySQL + Redis/Memcached + WooCommerce HPOS tests for repos, cache, outbox
    Security/               # Actor x Resource x Endpoint authorization matrix, IDOR, CSRF, SQLi, replay tests
    Scale/                  # High-volume benchmark assertions (query count limits & p95 latency gates)
    EndToEnd/               # Full API acceptance journeys
    Fixtures/               # Deterministic Builders and Factories for all aggregates

mercatus-pro/
  mercatus-pro.php
  composer.json
  phpstan.neon
  deptrac.yaml
  phpunit.xml.dist
  src/
    Licensing/
    Commissions/
    Payouts/
    Refunds/
    Memberships/
    Staff/
    Delivery/
    Analytics/
    Affiliate/
    Ai/
    MultiLocationFulfilment/
    VariableWeightSettlement/
    TaxAutomation/
    AdvancedCatalog/
    Integrations/
  database/
    migrations/
  tests/
    Architecture/           # Enforces zero imports from Mercatus\Core\Internal and cross-module isolation
    Unit/
    Contract/               # Provider conformance tests (PayoutProvider, TaxProvider, CatalogSearchPort)
    Integration/            # Pro module activation/deactivation zero-footprint tests & DB migrations
    Security/               # Pro module entitlement & tenant isolation matrix
    Scale/
    EndToEnd/
```

Use Composer PSR-4 autoloading with prefixes `Mercatus\Core\` and `Mercatus\Pro\`. Avoid a global singleton except for a minimal `mercatus()` facade that returns public interfaces. All new public function, option, transient, cache-group, database-table, hook, REST, and capability names use the `mercatus` prefix.

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

Mercatus validates the manifest, version compatibility, unique IDs/routes/permissions, dependency order, and database migration state. It exposes a read-only module/feature manifest at `GET /mercatus/v1/capabilities` (and embeds it in `GET /mercatus/v1/me`) so UIs can feature-detect.

Published extension points are strictly limited to:

1. **Commands and queries** — typed interfaces for work owned by a module.
2. **Domain events** — immutable event name, event ID, occurrence time, aggregate ID/version, actor, correlation ID, and payload schema.
3. **Policies and capabilities** — named capabilities and policy interfaces.
4. **REST route registration** — a module can add routes under the core namespace only through the registrar, with lazy schema loading and permission callback required.
5. **Read-model/field extensions** — namespaced fields with explicit visibility rules; never arbitrary filters over internal arrays.

Use semantic versioning for Core contracts. Deprecate a contract for at least one supported Core minor release, publish an upgrade guide, and remove it only in the next major.

---

## 4. Free vs. Paid Split & Unchanging Core Domain Model

Every Pro capability is an independently activatable module inside the single Mercatus Pro package. Installing Mercatus Pro does not turn a capability on, and an inactive capability contributes **zero** REST routes, workflow states, scheduled jobs, database queries, event subscribers, or checkout-time calculations. Its schema migrations run only when that module is explicitly activated (and are retained safely on deactivation).

| Domain | Mercatus (Free) | Mercatus Pro (Paid) |
|---|---|---|
| **Platform** | API kernel, module SDK, multi-tier cache engine (Redis/Memcached), migrations, two-lane outbox worker, audit/health foundations, roles/capabilities | License/entitlement verification and Pro module activation |
| **Vendor & Storefront** | Vendor application/approval, one public storefront per vendor, profile, policies, visibility, store list, and one implicit default fulfilment location | Verification/KYC, multi-location fulfilment, vacation/scheduled availability, advanced SEO, branded storefront features |
| **Catalog** | Vendor ownership mapping, allowed CRUD for Woo products, category-scoped attribute templates, high-speed facet & availability projections, media ownership/quotas, catalog moderation basics | Multi-vendor offers (SPMV), catalog cloning, bulk high-speed importer, external search engine adapters (Typesense/Meilisearch/OpenSearch), variable-weight settlement, advanced product rules, bookings/subscriptions/auctions integrations |
| **Orders** | Parent checkout order plus two-stage deterministic vendor-owned WooCommerce child order per vendor, legacy gateway bridge, vendor-scoped order API, basic fulfilment status | Advanced workflows, shipment tracking, returns/disputes automation |
| **Tax** | Configurable tax foundation: marketplace/vendor/facilitator seller modes, seller tax profiles and registrations, tax classes and local rate rules, `TaxContext`, per-allocation calculation/snapshots, child-order reconciliation, refunds, basic tax reporting/export | Automated `TaxProvider` adapters, registration validation, advanced jurisdiction policy packs, e-invoices/tax documents, audit/reconciliation automation, filing/remittance integrations |
| **Wallet** | Customer and vendor wallet accounts, immutable minor-unit wallet entries, balances, holds, and history | Wallet top-ups/payment-provider adapters, vendor settlement automation, withdrawals, reconciliation, promotional credit rules |
| **Money** | Immutable order allocation snapshot, `SettlementPolicyContract` (default $0\%$ fee in Free), and wallet/accounting primitives | Commission rule engine, marketplace double-entry ledger, withdrawal/payout requests, gateways, reverse withdrawals, reconciliation |
| **Shipping** | Vendor-owned shipping settings, zones, methods/rates, dispatch/ship-from address, basic pickup, test-quote API, and Woo shipping integration | Location-based pickup, delivery, shipping/routing rules, distance/weight/country rules, delivery agents/routes/time slots |
| **Engagement** | Public store reviews and moderation | Groups & Staff, Memberships, Affiliate, advanced notifications, customer support extensions |
| **Data & Intelligence** | Exportable basic data and event stream | Analytics warehouse/read models, scheduled reports, AI Suite with explicit provider consent and quotas |

### Capability modules without changing the core model

The internal path is always:
$$\text{Catalog Product} \rightarrow \text{Vendor Offer} \rightarrow \text{Fulfilment Location} \rightarrow \text{Inventory Balance} \rightarrow \text{Order Line}$$

| Capability Module | Disabled Behaviour | Enabled Behaviour |
|---|---|---|
| **Multi-location fulfilment** | Every vendor receives one automatically-created default fulfilment location. Location-management UI/API is hidden and all products/inventory resolve to that location. | A vendor can add branches, pickup points, delivery locations, and warehouses with separate inventory, staff, shipping, pickup, delivery, prices, and promotions. |
| **Single Product, Multiple Vendors (SPMV)** | A catalog product permits one active vendor offer. The other-sellers UI and offer-selection API are unavailable. | A catalog product permits multiple active vendor offers; buyers select an offer based on seller, price, location, and fulfilment terms. |
| **Location-specific Prices** | A vendor offer's base price applies at every location. | An offer-location may supply a validated price override for that vendor offer. |
| **Location-specific Promotions** | Only vendor-wide promotions are evaluated. | Location-level discount/promotion rules are evaluated after the fulfilment location is known. |
| **Variable-weight settlement** | Products use fixed-quantity price, payment, child-order, refund, commission, and completion flow. No measurement fields or extra statuses exist. | Eligible offers may be sold by measured unit with an estimate, approved tolerance, measured final quantity, and post-weigh settlement. |

### Two-tier attribute & category architecture (scaling to thousands of taxonomies)

To prevent WordPress from registering thousands of global `pa_*` taxonomies in memory on every request or bloating `wp_term_relationships` across lakhs of products:

1. **Tier 1 — WooCommerce Native Attributes (Variation-generating only):** Used strictly for attributes that generate distinct purchasable `WC_Product_Variation` records (e.g., `Size`, `Color`).
2. **Tier 2 — Mercatus Catalog Attributes (Filtering & Specifications):** Stored in dedicated Mercatus tables (`wp_mercatus_attributes`, `wp_mercatus_category_attribute_maps`, `wp_mercatus_catalog_facets`).
3. **Category-Scoped Attribute Loading:** Categories use a materialized path index (`path`, `depth`, `is_leaf`). Attributes are mapped to categories via `wp_mercatus_category_attribute_maps`. When a vendor creates or edits a product in a category, `GET /mercatus/v1/catalog/categories/{id}/schema` returns *only* the attributes mapped to that category branch—never dumping thousands of global attributes to the browser.
4. **Lazy Tree & Cursor Autocomplete:** Category trees load lazily by parent (`GET /categories?parent_id=X`), and attribute terms use cursor-based search autocomplete (`GET /attributes/{id}/terms?search=...&cursor=...`).

---

## 5. First-Class Order Splitting, Tax, Shipping, Wallet, & Variable-Weight Rules

### Parent order and two-stage vendor child orders

At checkout, WooCommerce creates one customer-facing **parent order**. To minimize checkout latency and avoid creating orphan child orders for abandoned or failed payments, Mercatus executes a deterministic **Two-Stage Order Split**:

1. **Stage 1 — Pre-Payment Allocation & Tax Snapshot (Synchronous at Checkout Finalization):**
   - After WooCommerce builds the final line items, coupons, and shipping packages, Mercatus resolves the selected vendor offer, fulfilment location, and `TaxContext` for every line.
   - Mercatus writes the parent `WC_Order`, `wp_mercatus_order_groups`, immutable `wp_mercatus_order_allocations`, `wp_mercatus_tax_snapshots`, and `wp_mercatus_inventory_reservations` in one fast transaction before the payment intent is executed.
   - Payment is collected against the parent `WC_Order` once through any configured WooCommerce payment gateway.
2. **Stage 2 — Post-Authorization Child `WC_Order` Materialization:**
   - Immediately when the parent `WC_Order` transitions to an authorized/actionable state (`on-hold`, `processing`, `completed`, or verified COD), Mercatus deterministically materializes one child `WC_Order` per `(parent_order_id, vendor_id)` from the immutable allocation and tax snapshots.
   - The split is idempotent and locked via `wp_mercatus_vendor_orders`, so webhook retries or concurrent hooks can never create duplicate child orders.

**Mandatory Order Split Rules:**
- The sum of child financial totals (item totals, line discounts, taxes, fees, shipping, shipping tax) must reconcile exactly to the parent total:
  $$\sum_{i=1}^{k} \text{ChildTotal}_i = \text{ParentTotal}$$
- Child orders never initiate payment, never reduce stock a second time, never send duplicate customer emails, and never expose payment secrets.
- Refunds are requested/approved against child lines (`wp_mercatus_order_allocations`) but executed through the parent WooCommerce payment/refund workflow with idempotency and reconciliation.

### Tax: seller-of-record and supply-context model

Tax is calculated **per taxable supply** (each vendor-child allocation and its shipping/fee lines) after the selling entity, fulfilment method, and fulfilment location are known. Every calculation receives an immutable `TaxContext`:

```text
seller of record + seller tax profile/registrations
+ product tax category + price/discount basis
+ fulfilment method (delivery | pickup | digital/service)
+ origin (actual fulfilment location)
+ destination (shipping address or selected pickup location)
+ billing address + exemption/validation evidence
+ transaction time, currency, jurisdiction policy, and tax provider version
= tax quote and order-line tax snapshot
```

Supported legal operating modes per jurisdiction:
1. **Marketplace as seller of record:** The marketplace tax profile calculates and collects customer tax (Core).
2. **Vendor as seller of record:** Core stores the vendor's marketplace-approved tax profile/registrations and calculates/snapshots tax for that vendor's child order. The marketplace separately taxes its commission or service fee to the vendor where required.
3. **Marketplace facilitator / hybrid:** Core assigns collection responsibility in a configured jurisdiction policy; Pro adds jurisdiction-policy packs, registration validation, and automated `TaxProvider` adapters.

`TaxProvider` is a Core contract. Core ships `ManualTaxProvider` and a WooCommerce tax-rate bridge. A manual Core policy is allowed only for an explicitly configured supported jurisdiction/seller profile; it must fail checkout safely for an unsupported combination rather than silently falling back to the marketplace base rate.

### Vendor Shipping & Tax settings (Core)

Every vendor receives a tenant-scoped **Settings $\rightarrow$ Shipping** and **Settings $\rightarrow$ Tax** API and UI experience, isolated from global `woocommerce_*` settings. It includes a read-only **test quote** endpoint (`POST /mercatus/v1/me/shipping-tax/test-quote`) where the vendor selects an offer, destination/pickup point, and amount, and receives a full shipping and tax breakdown without creating an order.

### Customer and vendor wallet

Each customer and vendor has currency-specific wallet accounts (`wp_mercatus_wallet_accounts`) and an append-only journal of immutable entries (`wp_mercatus_wallet_entries`) stored in `BIGINT` minor currency units.
- Never allow a negative spendable wallet balance or expose a generic `PATCH /wallet/balance` endpoint.
- Checkout wallet reservations use `wp_mercatus_wallet_holds` and are captured or released exactly once as the parent order succeeds, fails, is cancelled, or is refunded.
- Core exposes `SettlementPolicyContract` (defaulting to $100\%$ vendor net / $0\%$ fee in Free) so Pro Commissions and Ledger modules hook into order completion/settlement without altering Core order or wallet workflows.

### Optional variable-weight goods and post-weigh settlement (Pro)

When activated in Pro, eligible offers are priced per unit (e.g., per kg) and ordered by target weight with a customer-approved lower/upper tolerance.
- Checkout snapshots unit price, target quantity, approved tolerance, estimated total, and maximum authorised total.
- Before dispatch, an authorised fulfilment user records one actual measured weight. Mercatus validates tolerance, computes final line total from snapshotted rates, and settles via: (1) Authorise max, capture actual; (2) Capture estimate, refund/wallet-credit lower difference; (3) Capture estimate, charge saved method or record a transparent `customer_settlement` receivable debit for higher actual; or (4) Verified COD cash collection.
- Vendor gross sales, taxes, and commissions are calculated strictly from the **actual final line amount**. Incremental vendor payout on unpaid customer settlement debits is held until collected unless policy explicitly assumes credit risk.

---

## 6. Performance, Multi-Tier Caching (Redis/Memcached/Edge), & Location Discovery

### Multi-tier caching architecture

Mercatus defines a `CachePort` interface in `Mercatus\Core\Contracts\Cache` with a default implementation backed by WordPress's persistent object cache (`wp_cache_*` via **Redis** or **Memcached**) and a direct `PhpRedis` / `Memcached` pipeline adapter for high-throughput batch operations.

1. **L1 — In-Memory Request Memoization:**
   - Per-request static memory map inside the Mercatus Kernel so repeated lookups of `ActorIdentity`, `ModuleManifest`, feature flags, or policies within a single HTTP request cost $0$ network hops.
2. **L2 — Persistent Distributed Object Cache (Redis / Memcached):**
   - **Explicit Cache Groups:** `mercatus_actors`, `mercatus_capabilities`, `mercatus_stores`, `mercatus_offers`, `mercatus_availability`, `mercatus_facets`, `mercatus_categories`, `mercatus_shipping`, `mercatus_locks`, and `mercatus_ratelimits`.
   - **Version-Stamped Keys ($O(1)$ Fine-Grained Invalidation):** Never call `wp_cache_flush()` or scan keys. Every entity/collection key incorporates a version pointer:
     $$\text{Key} = \text{mercatus\_availability:p:\{product\_id\}:v:\{product\_rev\}:loc:\{location\_id\}}$$
     When stock, price, promotion, or location config changes for `product_id`, Mercatus atomically increments `mercatus_rev:product:{product_id}` (`wp_cache_incr`). Stale keys become unreachable immediately and expire via TTL.
   - **Batched Multi-Get (`wp_cache_get_multiple` / `MGET`):** List endpoints fetch cached projections in a single batched `MGET` round-trip, querying MySQL only for missed IDs and backfilling Redis/Memcached.
   - **Stampede Protection (Singleflight Lock + Stale-While-Revalidate):** Hot keys store a soft-expiry timestamp inside the cached payload alongside a longer hard TTL. When soft-expired, a single worker acquires a non-blocking lock (`wp_cache_add('lock:...', 1, 'mercatus_locks', 5)`) to refresh the cache while concurrent requests immediately receive the stale value.
   - **Atomic Rate Limiting & Idempotency Locks:** Rate-limit counters (`mercatus_ratelimits`) and in-flight mutation locks use atomic Redis/Memcached `INCR` and `ADD` operations before touching MySQL.
3. **L3 — HTTP / CDN Edge Cache & Client ETags:**
   - Public read endpoints (`GET /stores`, `GET /stores/{slug}`, `GET /catalog/offers`, `GET /catalog/categories`) emit `ETag` (derived from entity revision numbers), `Cache-Control: public, max-age=60, stale-while-revalidate=300`, and `Cache-Tag` / `Surrogate-Key` headers for Cloudflare, Varnish, or Nginx FastCGI caching.
   - Authenticated read endpoints emit `Cache-Control: private, no-cache` with `ETag` headers so browsers receive fast `304 Not Modified` responses.
4. **Graceful SQL Fallback & Health Check:**
   - When `wp_using_ext_object_cache()` is `false`, Mercatus falls back to its indexed SQL read-projection tables without writing transient bloat to `wp_options`, and flags a warning in `GET /mercatus/v1/health`.

### Performance & scale budgets (enforced in CI against $100,000$ products / $300,000$ offers)

| Operation / Endpoint | Max DB Queries (Warm Redis) | Max DB Queries (Cold Cache) | $p_{95}$ Latency Budget (Warm / Cold) |
|---|---|---|---|
| `GET /mercatus/v1/me` (Portal Bootstrap) | $0\text{--}1$ | $\le 4$ | $< 15\text{ ms}$ / $< 45\text{ ms}$ |
| `GET /mercatus/v1/stores/{slug}` | $0$ | $\le 2$ | $< 12\text{ ms}$ / $< 35\text{ ms}$ |
| `GET /mercatus/v1/catalog/offers` ($24$ items + facets) | $0\text{--}1$ | $\le 3$ | $< 25\text{ ms}$ / $< 95\text{ ms}$ |
| `GET /mercatus/v1/catalog/categories/{id}/schema` | $0$ | $\le 2$ | $< 10\text{ ms}$ / $< 30\text{ ms}$ |
| Cart Line Offer + Location + Tax Quote | $\le 2$ | $\le 5$ | $< 35\text{ ms}$ / $< 80\text{ ms}$ |
| Stage 1 Checkout Allocation ($3$ vendors, $6$ lines) | $\le 8$ writes | $\le 12$ ops | $< 120\text{ ms}$ (excluding external gateway HTTP) |

### Location discovery and H3/Geohash routing

- Geocode/validate a location address only when created or updated; never call an external geocoding service during cart or checkout.
- Store `latitude`, `longitude`, and an indexed **H3 hexagonal index (Resolution 7 & 8 `bigint unsigned`)** or 6-character geohash prefix (`geo_cell`) on `wp_mercatus_fulfilment_locations` and `wp_mercatus_offer_availability`.
- Query the target `geo_cell` and its immediate neighbor ring using an indexed `WHERE geo_cell IN (...)` lookup, then compute exact distance in PHP only for the matched candidate set.

---

## 7. Domain Model and Complete Database Schema

**Global Schema Rules:**
- Engine: `InnoDB`, Charset: `utf8mb4`, Collation: `utf8mb4_unicode_520_ci`.
- Identifiers: `BIGINT UNSIGNED NOT NULL`.
- Timestamps: `DATETIME(3) NOT NULL` in UTC.
- Monetary Amounts (Balances, Holds, Ledger, Order/Allocation/Tax Totals): `BIGINT NOT NULL` in minor currency units (e.g., paise/cents) paired with `currency CHAR(3) NOT NULL`.
- Unit Prices, Percentage Rates, Tax Rates, & Quantities: `DECIMAL(20,6) NOT NULL`.
- Concurrency: `version BIGINT UNSIGNED NOT NULL DEFAULT 1` on all mutable tables.
- Foreign Keys: Enforced at the application/repository level with explicit indexes; never attach SQL `FOREIGN KEY` constraints to WordPress or WooCommerce tables.

### Core tables (`wp_mercatus_*`)

| Table | Responsibility | Primary / Unique Keys & Required Covering Indexes |
|---|---|---|
| `wp_mercatus_schema_versions` | Tracks applied Core and Pro module migrations, checksums, and execution locks | PK `id`; UNIQUE `(module_id, version)` |
| `wp_mercatus_vendors` | Vendor identity and lifecycle; maps one WP user to marketplace vendor record | PK `id`; UNIQUE `user_id`; INDEX `(status, created_at)` |
| `wp_mercatus_stores` | Store profile, normalized slug, visibility, and owner vendor | PK `id`; UNIQUE `vendor_id`; UNIQUE `slug`; INDEX `(visibility, status)` |
| `wp_mercatus_catalog_products` | Marketplace catalog product, product type (`simple`, `variable`, `booking`, `subscription`, `auction`), and governance state | PK `id`; UNIQUE `woo_product_id`; INDEX `(scope, status, product_type)`; INDEX `(primary_category_id, status)` |
| `wp_mercatus_attributes` | High-scale Tier 2 specification and filter attributes | PK `id`; UNIQUE `slug`; INDEX `(type, is_filterable)` |
| `wp_mercatus_category_attribute_maps` | Maps Woo/Mercatus categories (with materialized `category_path`) to allowed/required attributes | PK `id`; UNIQUE `(category_id, attribute_id)`; INDEX `(category_id, sort_order)`; INDEX `(category_path(64))` |
| `wp_mercatus_catalog_facets` | Denormalized attribute & category filter index for sub-$20\text{ ms}$ faceted catalog queries | PK `id`; UNIQUE `(catalog_product_id, attribute_id, value_id, location_id)`; INDEX `idx_facet_filter (attribute_id, value_id, is_purchasable, catalog_product_id)`; INDEX `idx_num_filter (attribute_id, numeric_value, is_purchasable, catalog_product_id)` |
| `wp_mercatus_vendor_offers` | A vendor's sellable offer for a catalog product (`base_price` `DECIMAL(20,6)`) | PK `id`; UNIQUE `(catalog_product_id, vendor_id)`; INDEX `(vendor_id, status)` |
| `wp_mercatus_fulfilment_locations` | Vendor's default, branch, pickup, delivery, warehouse, or 3PL location | PK `id`; INDEX `(vendor_id, status, is_default)`; INDEX `(geo_cell, status)` |
| `wp_mercatus_offer_locations` | Offer availability and local fulfilment/price override settings at a location | PK `id`; UNIQUE `(vendor_offer_id, location_id)`; INDEX `(location_id, status)` |
| `wp_mercatus_offer_availability` | Denormalized storefront read projection for offers, effective prices, stock, card display JSON, and geo-cells | PK `id`; UNIQUE `(vendor_offer_id, variation_id, location_id)`; INDEX `idx_catalog_avail (catalog_product_id, is_purchasable, effective_price)`; INDEX `idx_geo_avail (geo_cell, is_purchasable, catalog_product_id)`; INDEX `idx_vendor_avail (vendor_id, is_purchasable, updated_at)` |
| `wp_mercatus_inventory_balances` | Current sellable stock by offer, variation, and location | PK `id`; UNIQUE `(vendor_offer_id, variation_id, location_id)` |
| `wp_mercatus_inventory_movements` | Append-only stock change journal | PK `id`; UNIQUE `movement_uuid`; INDEX `(balance_id, created_at)` |
| `wp_mercatus_inventory_reservations` | Checkout stock reservations with TTL expiration | PK `id`; UNIQUE `reservation_uuid`; INDEX `(balance_id, status)`; INDEX `(status, expires_at)` |
| `wp_mercatus_media_ownership` | Vendor media attachment ownership and storage quotas (avoids scanning `wp_posts`) | PK `attachment_id`; INDEX `(vendor_id, created_at DESC)` |
| `wp_mercatus_order_groups` | Parent checkout order grouping and split state machine | PK `id`; UNIQUE `parent_woo_order_id`; INDEX `(status, created_at)` |
| `wp_mercatus_vendor_orders` | One deterministically materialized child order record per vendor | PK `id`; UNIQUE `(parent_woo_order_id, vendor_id)`; UNIQUE `child_woo_order_id` (nullable pre-auth); INDEX `(vendor_id, status, created_at DESC)` |
| `wp_mercatus_order_allocations` | Immutable financial and quantity allocation per vendor/order line (in minor units) | PK `id`; UNIQUE `(parent_woo_order_id, order_item_id, vendor_id)`; INDEX `(vendor_order_id)`; INDEX `(vendor_id, created_at)` |
| `wp_mercatus_order_fulfilments` | One or more fulfilment tasks for a vendor child order, assigned to a location | PK `id`; INDEX `(vendor_order_id, location_id, status)` |
| `wp_mercatus_vendor_shipping_profiles` | Vendor-scoped ship-from settings and approval version | PK `id`; UNIQUE `vendor_id`; INDEX `(status)` |
| `wp_mercatus_shipping_zones` / `wp_mercatus_shipping_methods` | Vendor zone coverage and approved shipping methods/rates | PK `id`; INDEX `(vendor_id, status, priority)`; INDEX `(country_code, state_code, postcode_pattern)` |
| `wp_mercatus_seller_tax_profiles` / `wp_mercatus_tax_registrations` | Marketplace and vendor legal/tax identity, encrypted tax IDs, and approval status | PK `id`; UNIQUE `(seller_type, seller_id, country_code, state_code)` |
| `wp_mercatus_tax_policies` / `wp_mercatus_tax_rules` | Administrator-owned seller-of-record/facilitator rules, tax classes, and local rates | PK `id`; INDEX `(country_code, state_code, tax_class, effective_from, effective_to)` |
| `wp_mercatus_tax_snapshots` | Immutable `TaxContext` digest and reconciled tax components per allocation | PK `id`; UNIQUE `(allocation_id, taxable_component, calculation_version)`; INDEX `(vendor_order_id)` |
| `wp_mercatus_wallet_accounts` | Wallet per actor/currency/type (`balance_minor` `BIGINT`) | PK `id`; UNIQUE `(owner_type, owner_id, currency, wallet_type)` |
| `wp_mercatus_wallet_entries` | Immutable debit/credit journal (`amount_minor` `BIGINT`, `running_balance_minor` `BIGINT`) | PK `id`; UNIQUE `entry_uuid`; UNIQUE `(account_id, idempotency_key)`; INDEX `(account_id, posted_at DESC)` |
| `wp_mercatus_wallet_holds` | Pending wallet reservations (`amount_minor` `BIGINT`) | PK `id`; UNIQUE `hold_uuid`; INDEX `(account_id, status, expires_at)` |
| `wp_mercatus_reviews` / `wp_mercatus_review_replies` | Public store reviews and moderated replies | PK `id`; UNIQUE `(store_id, customer_id, vendor_order_id)`; INDEX `(store_id, status, created_at DESC)` |
| `wp_mercatus_api_clients` | Scoped server-to-server API client credentials and IP allow-lists | PK `id`; UNIQUE `client_key_hash`; INDEX `(actor_type, actor_id, status)` |
| `wp_mercatus_events_outbox` | Transactional events pending dispatch (`lane`: `critical` \| `bulk`, `coalesce_key`) | PK `id`; UNIQUE `event_uuid`; INDEX `idx_outbox_poll (lane, status, available_at, id)`; INDEX `(coalesce_key, status)` |
| `wp_mercatus_audit_log` | Append-only security and business audit trail | PK `id`; INDEX `(actor_id, created_at DESC)`; INDEX `(resource_type, resource_id, created_at DESC)` |
| `wp_mercatus_idempotency` | Mutation request/response replay protection | PK `id`; UNIQUE `(actor_fingerprint, route, idempotency_key)`; INDEX `(expires_at)` |

### Pro tables (`wp_mercatus_pro_*` — migrated only when the owning module is activated)

| Pro Module | Tables | Key Invariant |
|---|---|---|
| **Commissions** | `commission_rules`, `commission_snapshots` | Snapshot every applied rule/version on allocation in minor units; never recalculate historical payouts from edited rules. |
| **Ledger & Payouts** | `ledger_accounts`, `ledger_entries`, `payouts`, `payout_items`, `payment_attempts`, `reconciliation_records` | Double-entry balanced debit/credit entries in minor units; zero editable balance columns; four-eyes approval support. |
| **Refunds** | `refund_cases`, `refund_lines`, `refund_decisions` | References Core `allocation_id` and Woo refund IDs; preserves full state transition history. |
| **Tax Automation** | `tax_provider_requests`, `tax_documents`, `tax_reconciliation_runs` | Stores provider request/response digests, e-invoice metadata, and reconciliation runs without mutating Core `tax_snapshots`. |
| **Variable-Weight Settlement** | `line_measurements`, `line_quantity_adjustments`, `settlement_authorisations`, `customer_settlements` | Immutable target/tolerance/actual measurement, final-price calculation, customer approval, and receivable debit tracking. |
| **Memberships** | `plans`, `subscriptions`, `entitlements`, `billing_events` | Entitlements are compiled into the cached `mercatus_capabilities` / `mercatus_actors` manifest. |
| **Multi-Location Fulfilment** | `location_memberships`, `location_delivery_zones`, `location_shipping_profiles`, `inventory_transfers`, `routing_rules` | Extends Core default location to $N$ branches/warehouses with H3/geohash routing and inter-location stock transfers. |
| **Staff & Groups** | `groups`, `group_permissions`, `staff_assignments` | Vendor- and location-scoped permissions; never grants global WordPress roles. |
| **Delivery** | `agents`, `agent_assignments`, `delivery_jobs`, `delivery_events` | Strict assignment-scoped visibility; delivery agents cannot query unassigned orders. |
| **Analytics** | `analytics_daily_vendor_rollups`, `analytics_product_rollups`, `analytics_exports` | Populated asynchronously via the `bulk` outbox lane; never queried or updated synchronously during checkout. |
| **Affiliate** | `affiliates`, `referrals`, `attributions`, `affiliate_commissions` | Attribution is immutable once locked after the configured attribution window. |
| **AI Suite** | `ai_requests`, `ai_usage_quotas`, `ai_approvals` | Redacts PII/secrets from prompts; requires explicit human approval before publishing catalog or moderation actions. |

---

## 8. Identity, Authorization, & Security Controls

### Actors and authentication

Actors are resolved into an immutable `ActorIdentity` value object independent of WordPress roles: `anonymous`, `customer`, `vendor_owner`, `vendor_staff`, `delivery_agent`, `marketplace_manager`, `administrator`, `integration_client`, and `webhook_provider`.

- **Browser UI:** Authenticated WordPress cookies + `X-WP-Nonce` (CSRF protection).
- **Server-to-Server Integrations:** WordPress Application Passwords mapped to `wp_mercatus_api_clients` with explicit permission scopes, expiry/revocation, and optional IP allow-list.
- **Mobile Apps (Customer, Vendor, Delivery):** OAuth 2.0 + PKCE issuing short-lived access JWTs ($5\text{--}15\text{ min}$) + revocable refresh tokens via a dedicated Auth Adapter.
- **Webhooks:** Raw-body HMAC signature verification + timestamp window + stored event ID replay defense.
- **Background Workers / CLI:** System actor scoped strictly to the executing worker contract.

### Authorization pipeline & cached actor context

Every request passes through this deterministic pipeline:

```text
Request Authentication
  -> L1/L2 Cached ActorIdentity Resolution (Redis group: mercatus_actors)
  -> API-Client Scope / Named Permission Check
  -> Module Entitlement Check (L1/L2 Cached Capability Manifest)
  -> Resource Policy Check (StorePolicy, AllocationPolicy, PayoutPolicy, etc.)
  -> Field-Level Serializer Visibility Filter
  -> Command State-Machine Transition Validation
```

### Mandatory security controls

- **Input Validation:** JSON Schema validation (lazy-loaded only for the matched route) for shape and types; semantic validation inside Application Commands.
- **Mass-Assignment Protection:** Command DTOs accept only explicit, actor-permitted fields. Fields like `vendor_id`, `approved_by`, `commission_amount`, `tax_amount`, or `balance_minor` are never populated from client request bodies.
- **Enumeration Policy:** Return consistent `404 Not Found` (rather than `403 Forbidden`) when an actor requests a private resource ID belonging to another tenant (`vendor_orders`, `wallet_accounts`, `payouts`, unpublished `vendor_offers`).
- **Idempotency & Optimistic Locking:** Require `Idempotency-Key` header on all `POST` financial, stock, checkout, and external-provider mutations. Require `If-Match` ETag/version header on `PATCH`/`PUT` mutations, returning `409 Conflict` on version mismatch.
- **Sensitive Data Protection:** Encrypt provider API keys and tax registration numbers at rest using `sodium_crypto_secretbox` with a key derived from environment/site secrets. Store KYC documents and delivery proofs outside public web roots and serve only via short-lived signed URLs.

---

## 9. REST API Standard & Two-Lane Outbox Architecture

### REST API conventions

- **Namespace:** `/wp-json/mercatus/v1`.
- **State-Machine Subresources:** Use explicit action verbs on subresources (`POST /mercatus/v1/payouts/{id}/approve`), never generic status patching.
- **Error Envelope:** Every error returns HTTP status + JSON `{ "code": "mercatus_...", "message": "...", "details": {...}, "request_id": "req_..." }`. Stack traces are never exposed.
- **Cursor Pagination:** All high-volume collections use `?cursor=...&per_page=24` and return `{ "items": [...], "per_page": 24, "next_cursor": "...", "has_more": true }`.
- **OpenAPI 3.1 Contract:** Every route, parameter, request body, and response DTO is defined in `openapi/mercatus-v1.yaml` and validated automatically in contract tests.

### Initial Core endpoint inventory

| Resource Domain | Endpoints |
|---|---|
| **Bootstrap & System** | `GET /me` (returns actor, store summary, and active module capabilities), `GET /capabilities`, `GET /health`, `POST /system/cache/flush-group` |
| **Vendor Applications** | `POST /vendor-applications`, `GET /vendor-applications/{id}`, `POST /vendor-applications/{id}/approve`, `POST /vendor-applications/{id}/reject` |
| **Stores** | `GET /stores`, `GET /stores/{slug}`, `GET /me/store`, `PATCH /me/store`, `POST /stores/{id}/suspend` |
| **Catalog & Taxonomy** | `GET /catalog/categories`, `GET /catalog/categories/{id}/schema`, `GET /catalog/attributes/{id}/terms`, `GET /catalog/offers` (faceted projection), `GET /catalog/products/{id}/offers` |
| **Vendor Products & Offers** | `GET /me/products`, `POST /me/products`, `GET /me/products/{id}`, `PATCH /me/products/{id}`, `DELETE /me/products/{id}`, `PATCH /me/offers/{id}/inventory` |
| **Media** | `POST /me/media`, `GET /me/media`, `DELETE /me/media/{id}` |
| **Shipping & Tax** | `GET /me/shipping-profile`, `PATCH /me/shipping-profile`, `GET/POST/PATCH/DELETE /me/shipping-zones`, `GET /me/tax-profile`, `PATCH /me/tax-profile`, `POST /me/shipping-tax/test-quote`, Admin policy/rate CRUD |
| **Checkout & Orders** | `POST /checkout/orders/{parent_id}/pay` (legacy gateway bridge), `GET /me/orders`, `GET /me/orders/{id}`, `POST /me/orders/{id}/fulfilment-status`, `GET /orders/{parent_id}/allocations` (admin) |
| **Wallet** | `GET /me/wallets`, `GET /me/wallets/{currency}`, `GET /me/wallets/{currency}/entries`, internal/admin hold, capture, release, and credit commands |
| **Reviews** | `GET /stores/{slug}/reviews`, `POST /stores/{slug}/reviews`, `POST /reviews/{id}/reply`, `POST /reviews/{id}/moderate` |

### Two-lane pull-based outbox worker

Commands write domain state, `wp_mercatus_audit_log`, and `wp_mercatus_events_outbox` inside a single MySQL transaction, then bump Redis/Memcached revision keys after commit.

- **No 1:1 Action Scheduler Bloat:** Mercatus never creates one Action Scheduler row per domain event. Instead, a recurring worker (via Action Scheduler or `wp mercatus outbox:work` daemon) polls `wp_mercatus_events_outbox` in batches of $200$ rows using:
  ```sql
  SELECT * FROM wp_mercatus_events_outbox
  WHERE lane = %s AND status = 'pending' AND available_at <= UTC_TIMESTAMP(3)
  ORDER BY id ASC LIMIT 200
  FOR UPDATE SKIP LOCKED
  ```
- **Two Priority Lanes:**
  1. `critical`: Post-auth child `WC_Order` materialization, wallet holds/captures, payment webhooks, payout transitions.
  2. `bulk`: `wp_mercatus_offer_availability` & `wp_mercatus_catalog_facets` rebuilds, external search indexing, analytics rollups, notifications, AI tasks.
- **Coalescing:** On the `bulk` lane, duplicate pending events sharing the same `coalesce_key` (e.g., `offer_proj:1045`) within a batch are collapsed into a single execution.

---

## 10. Comprehensive Testing Architecture & Module-by-Module Test Mandate

> **Strict Engineering Rule:** No feature, bugfix, migration, or endpoint is considered implemented until its automated test suite is written and passing across all applicable layers below. Writing plugin code without tests is strictly forbidden.

### The 6-Layer Automated Test Pyramid

#### Layer 1: Architectural & Static Analysis Tests (`tests/Architecture/`)
- **Tools:** PHPStan (Level 8+), PHP_CodeSniffer (WordPress-Strict), Deptrac / Pest Architecture plugin.
- **What It Enforces Automatically on Every Commit:**
  - `Mercatus\Core\Domain\*` and `Mercatus\Core\Application\*` have zero dependencies on `WP_REST_Request`, `WC_Product`, `WC_Order`, or `$wpdb`.
  - `Mercatus\Core\*` has zero references to `Mercatus\Pro\*`.
  - `Mercatus\Pro\*` only imports from `Mercatus\Core\Contracts\*` (never `Mercatus\Core\Internal\*` or concrete Core repositories).
  - Controllers never import `$wpdb` or repository classes directly.
  - Every Command handler and Policy class has a matching `*Test.php` file.

#### Layer 2: Fast Unit Tests (`tests/Unit/`)
- **Environment:** Pure PHP 8.2+ in memory. Zero database connection, zero WordPress bootstrap. Execution speed: $< 5\text{ ms}$ per test ($< 3\text{ seconds}$ for the entire suite).
- **What It Tests:**
  - Minor-unit `BIGINT` money arithmetic, deterministic multi-vendor discount/shipping/tax pro-rata allocation, and penny-rounding reconciliation so $\sum \text{Child} = \text{Parent}$ even on indivisible remainders (e.g., ₹$100$ split $3$ ways = ₹$33.34$, ₹$33.33$, ₹$33.33$).
  - Order status state machines (parent aggregation from child statuses, allowed/forbidden transitions).
  - `TaxContext` resolution rules across Delivered, Pickup, Digital, and Marketplace Fee scenarios.
  - Variable-weight tolerance validation and post-weigh settlement math.
  - Policy decision logic (`StorePolicy`, `AllocationPolicy`, `WalletPolicy`, `PayoutPolicy`) across all $9$ Actor types.
  - Version-stamped cache key generation and H3/geohash cell resolution.

#### Layer 3: Contract & Conformance Tests (`tests/Contract/`)
- **Environment:** Schema validators and interface conformance harnesses.
- **What It Tests:**
  - **OpenAPI 3.1 Conformance:** Every registered REST route's request and response JSON is validated against `openapi/mercatus-v1.yaml`. Undocumented fields or schema mismatches fail CI.
  - **SDK Provider Conformance Suites:** Abstract test traits (`CachePortConformanceTrait`, `TaxProviderConformanceTrait`, `PayoutProviderConformanceTrait`, `CatalogSearchPortConformanceTrait`) that *every* Core and Pro adapter must implement and pass.
  - **Domain Event Schema Validation:** Every emitted outbox event payload is validated against its versioned JSON Schema.

#### Layer 4: Database, Cache, & WooCommerce HPOS Integration Tests (`tests/Integration/`)
- **Environment:** Real MySQL 8.0 / MariaDB 10.6 + Real Redis & Memcached instances + WordPress 6.6+ + WooCommerce 9.2+ with HPOS enabled.
- **What It Tests:**
  - **Migrations:** Up-migrations, idempotency (running migrations twice), checksum verification, and Pro module lazy migration on activation.
  - **Repositories:** Real SQL execution against `wp_mercatus_*` tables, verifying composite index usage via `EXPLAIN` assertions (ensuring `type != 'ALL'` and zero `Using filesort` on hot queries).
  - **Multi-Tier Cache (`CachePort`):** Verifies L1 memoization, L2 Redis/Memcached `MGET` batching, $O(1)$ version-pointer invalidation (`wp_cache_incr`), singleflight stampede locks under simulated concurrency, and graceful SQL fallback when Redis is disconnected.
  - **Two-Stage Order Split with HPOS:** Verifies Stage 1 synchronous allocation/tax/reservation writes and Stage 2 child `WC_Order` creation in `wp_wc_orders` upon payment authorization, ensuring zero duplicate stock deductions and zero duplicate emails.
  - **Outbox Concurrency:** Runs $2$ parallel worker processes against `wp_mercatus_events_outbox` to prove `FOR UPDATE SKIP LOCKED` never dispatches the same event twice and properly coalesces `bulk` events.

#### Layer 5: Security & Authorization Matrix Tests (`tests/Security/`)
- **Environment:** Automated HTTP REST security harness executing requests across an **Actor $\times$ Resource Ownership $\times$ Endpoint Matrix**.
- **What It Tests Automatically for Every Endpoint:**
  - **IDOR / BOLA Matrix:** Vendor A attempts to read, update, refund, or ship Vendor B's store, product, offer, media, child order, shipping zone, tax profile, or wallet $\rightarrow$ must return `404 Not Found` (or `403` per enumeration rule) with zero data leakage.
  - **Privilege Escalation & Staff Scope:** Vendor Staff assigned to Location 1 attempts to mutate inventory at Location 2 or approve a payout $\rightarrow$ rejected.
  - **Mass Assignment Fuzzing:** Sends extra restricted fields (`vendor_id`, `status: 'approved'`, `balance_minor: 99999999`, `commission_rate: 0`) in `POST`/`PATCH` payloads $\rightarrow$ verifies restricted fields are ignored or rejected.
  - **Replay, CSRF, & Stale Writes:** Verifies missing `X-WP-Nonce` on cookie auth fails (`403`), duplicate `Idempotency-Key` replays original response without double-execution, and stale `If-Match` ETag returns `409 Conflict`.
  - **PII & Secret Redaction:** Inspects public, customer, and vendor API responses and logs to assert tax IDs, encrypted gateway secrets, and other vendors' customer addresses never appear.

#### Layer 6: Scale & Performance Gate Tests (`tests/Scale/`)
- **Environment:** Dedicated CI performance stage seeded via `wp mercatus seed:scale` ($2,000$ vendors, $5,000$ categories, $2,000$ attributes, $100,000$ products, $300,000$ offers).
- **What It Tests:**
  - Wraps `$wpdb->queries` counter and high-resolution timer (`hrtime(true)`) around critical queries and REST endpoints.
  - Fails the build automatically if query count exceeds the allowed ceiling (e.g., $>3$ queries on cold catalog filter, $>0$ SQL queries on warm Redis store read) or if $p_{95}$ execution time exceeds the budget table in Section 6.

---

### Module-by-Module Mandatory Test Matrix

Every module must ship with the following specific test classes before its ticket can be marked complete:

| Module / Bounded Context | Required Unit Tests (`tests/Unit/...`) | Required Integration & Contract Tests (`tests/Integration/...`, `tests/Contract/...`) | Required Security & Scale Tests (`tests/Security/...`, `tests/Scale/...`) |
|---|---|---|---|
| **1. Kernel & Migrations** | `ModuleManifestValidatorTest`, `VersionCompatibilityTest`, `CapabilityCompilerTest` | `MigrationRunnerTest` (lock, checksum, idempotency), `CapabilityEndpointContractTest` | `HealthEndpointAuthTest` (redacts details for non-admins), `ZeroQueryKernelBootTest` |
| **2. Multi-Tier Cache (`CachePort`)** | `VersionedCacheKeyResolverTest`, `SoftExpiryStampedeCalculatorTest` | `RedisCacheAdapterTest`, `MemcachedCacheAdapterTest`, `SqlFallbackCacheTest`, `BatchMultiGetIntegrationTest`, `SingleflightLockConcurrencyTest` | `CacheFlushAuthorizationTest`, `WarmCacheZeroSqlBenchmarkTest` |
| **3. Outbox & Async Worker** | `EventPayloadSerializerTest`, `BulkEventCoalescerTest`, `RetryBackoffPolicyTest` | `OutboxTransactionalWriteTest`, `SkipLockedParallelWorkerTest`, `CriticalVsBulkLanePriorityTest` | `CronContextSpoofingTest` (blocks external HTTP trigger spoofing), `OutboxBatchThroughputBenchmarkTest` ($1,000$ events $< 2\text{ s}$) |
| **4. Identity & Policy Engine** | `ActorIdentityResolverTest`, `PolicyEngineMatrixTest`, `PkceVerifierTest` | `CachedActorIdentityInvalidationTest`, `ApiClientScopeIntegrationTest`, `IdempotencyStoreIntegrationTest` | `IdorActorMatrixTest`, `CsrfNonceEnforcementTest`, `RateLimiterAtomicRedisTest` |
| **5. Vendor & Storefront** | `VendorLifecycleStateMachineTest`, `StoreSlugNormalizerTest`, `StorePolicyTest` | `VendorApplicationWorkflowTest`, `DefaultLocationAutoCreationTest`, `StoreRepositoryTest` | `StoreCrossTenantIsolationTest`, `StorePiiFieldRedactionTest`, `StorefrontEdgeCacheHeaderTest` |
| **6. Catalog, Attributes, & Projections** | `TwoTierAttributePolicyTest`, `MaterializedCategoryPathTest`, `EffectivePriceResolverTest`, `CatalogPolicyTest` | `WooProductCrudAdapterTest`, `OfferAvailabilityProjectionSyncTest`, `CatalogFacetIndexQueryTest`, `MediaOwnershipQuotaTest` | `ProductMassAssignmentOwnershipTest`, `MediaCrossVendorDeleteTest`, `HundredThousandProductFacetBenchmarkTest` |
| **7. Shipping & Tax (Core)** | `TaxContextBuilderTest`, `ManualTaxProviderMathTest`, `ProRataDiscountTaxRoundingTest`, `ShippingZoneMatcherTest` | `WooTaxRateBridgeTest`, `TaxSnapshotPersistenceTest`, `VendorShippingTaxTestQuoteEndpointTest` | `UnapprovedTaxProfileCheckoutBlockTest`, `UnsupportedJurisdictionFailClosedTest`, `VendorGlobalWooSettingsIsolationTest` |
| **8. Checkout & Two-Stage Order Split** | `DeterministicOrderAllocationMathTest`, `ParentStatusAggregatorTest`, `AllocationPolicyTest` | `StageOnePrePaymentSnapshotTest`, `StageTwoPostAuthChildOrderMaterializationTest`, `LegacyGatewayBridgePayTest`, `IdempotentOrderSplitRetryTest`, `StockSingleDeductionTest` | `VendorParentOrderAccessDeniedTest`, `CrossVendorChildOrderAccessDeniedTest`, `MultiVendorCheckoutLatencyBenchmarkTest` |
| **9. Customer & Vendor Wallets** | `MinorUnitWalletMathTest`, `WalletHoldStateMachineTest`, `NegativeBalancePreventionTest` | `WalletJournalOptimisticLockTest`, `CheckoutWalletHoldCaptureReleaseTest`, `ParentOrderRefundWalletReconciliationTest` | `WalletBalancePatchRouteAbsenceTest`, `ConcurrentWalletSpendRaceConditionTest`, `WalletIdempotencyReplayTest` |
| **10. Pro: Commissions & Ledger** | `CommissionRuleEvaluatorTest`, `DoubleEntryLedgerBalanceTest`, `FourEyesApprovalPolicyTest` | `ImmutableCommissionSnapshotHistoricalTest` (changing rule doesn't alter past orders), `PayoutProviderConformanceTest`, `WebhookSignatureReplayTest` | `UnentitledProFinanceRouteBlockTest`, `SelfApprovalPreventionTest`, `DuplicatePayoutWebhookReplayTest` |
| **11. Pro: Variable-Weight Settlement** | `WeightToleranceValidatorTest`, `PostWeighFinalPriceMathTest`, `CustomerSettlementReceivableTest` | `InactiveModuleZeroFootprintTest`, `MaxAuthCaptureActualFlowTest`, `LowerActualWalletCreditFlowTest`, `HigherActualReceivableHoldPayoutTest` | `OutOfToleranceUnapprovedChargeBlockTest`, `PostLockMeasurementTamperTest` |
| **12. Pro: Multi-Location, Staff, & Ultimate** | `H3GeoCellNeighborRingTest`, `LocationRoutingPriorityTest`, `StaffLocationScopePolicyTest` | `MultiLocationOfferAvailabilityTest`, `InventoryTransferTransactionTest`, `ExternalSearchPortConformanceTest` | `StaffCrossLocationMutationBlockTest`, `GeoSearchHundredThousandOfferBenchmarkTest` |

---

## 11. Phased Build Roadmap (With Mandatory Test Gates)

No phase may advance to the next until $100\%$ of its implementation and test gates pass in CI.

### Phase 0 — Product Boundary, Threat Model, & Contracts ($1\text{--}2$ weeks)
1. Freeze `Mercatus\Core\Contracts\*` interfaces (`ModuleManifest`, `CachePort`, `TaxProvider`, `SettlementPolicyContract`, `CatalogSearchPort`, `PayoutProvider`).
2. Freeze the initial `openapi/mercatus-v1.yaml` specification and actor-resource policy matrix.
3. Complete STRIDE + OWASP API Top 10 threat model and map every threat to an automated test case in `tests/Security/`.
- **Exit Gate:** Architecture contracts and OpenAPI spec pass lint and schema validation.

### Phase 1 — Foundation, Multi-Tier Caching, Outbox, & Scale Harness ($2\text{--}3$ weeks)
1. Scaffold `mercatus` and `mercatus-pro` with Composer, PHPStan Level 8, Deptrac (`tests/Architecture/`), and PHPUnit suites (`Unit`, `Contract`, `Integration`, `Security`, `Scale`).
2. Implement Kernel, `ModuleManifest` registry, `CachePort` (L1 + L2 Redis/Memcached with version pointers, `MGET`, and singleflight locks), and migration runner (`wp_mercatus_schema_versions`).
3. Implement two-lane pull-based Outbox worker (`critical` and `bulk` with `FOR UPDATE SKIP LOCKED`), audit log, idempotency store, Redis rate limiter, and cached `ActorIdentity` + `/mercatus/v1/me` bootstrap.
4. Build `wp mercatus seed:scale` ($2,000$ vendors, $5,000$ categories, $2,000$ attributes, $100,000$ products, $300,000$ offers).
- **Exit Gate:** All Layer 1–6 tests for Modules 1, 2, 3, and 4 pass in CI against MySQL 8.0 + Redis + Memcached.

### Phase 2 — Vendors & Storefronts ($2\text{--}3$ weeks)
1. Implement vendor application/approval state machine, single public storefront per vendor, automatic default fulfilment location creation (with H3/geohash index), `StorePolicy`, and edge-cacheable `/stores` APIs.
2. Build thin first-party Admin/Vendor Store UI backed exclusively by `/mercatus/v1/me` and `/mercatus/v1/stores`.
- **Exit Gate:** All Unit, Integration, IDOR Security, and Warm-Cache Zero-SQL tests for Module 5 pass.

### Phase 3 — High-Scale Catalog, Two-Tier Attributes, & Projections ($3\text{--}4$ weeks)
1. Implement `wp_mercatus_catalog_products`, `wp_mercatus_vendor_offers`, `wp_mercatus_media_ownership`, and the WooCommerce `WC_Product` CRUD adapter.
2. Implement Tier 2 attributes (`wp_mercatus_attributes`, `wp_mercatus_category_attribute_maps`, `wp_mercatus_catalog_facets`) and `wp_mercatus_offer_availability` projection with Redis `MGET` caching and SSR initial-state hydration.
- **Exit Gate:** All Module 6 tests pass, including `< 25 ms` warm / `< 95 ms` cold faceted filtering across $100,000$ seeded products with zero `wp_postmeta` or `wp_term_relationships` joins.

### Phase 4 — Two-Stage Split Orders, Shipping, Tax, Wallet, & Free MVP ($4\text{--}6$ weeks)
1. Implement Core vendor Shipping & Tax settings, test-quote endpoint, `TaxContext`, `ManualTaxProvider`, Woo rate bridge, and `SettlementPolicyContract` ($0\%$ fee default).
2. Implement Stage 1 synchronous pre-payment allocation/tax/reservation snapshots and Stage 2 post-authorization child `WC_Order` materialization in HPOS, plus the Legacy Gateway Bridge (`POST /checkout/orders/{parent_id}/pay`).
3. Implement Core Customer & Vendor Wallets (`BIGINT` minor units, holds, captures, releases, refunds).
- **Exit Gate:** All Module 7, 8, and 9 tests pass, including multi-vendor checkout reconciliation, race-condition wallet tests, and cross-vendor order isolation tests. **First shippable Free Marketplace MVP.**

### Phase 5 — Pro Finance, Ledger, Refunds, & Variable-Weight ($4\text{--}6$ weeks)
1. Implement Pro Licensing/Entitlement gate and verify zero runtime footprint for inactive Pro modules.
2. Implement Pro Commissions, Double-Entry Ledger, Payout state machine (with four-eyes approval), `PayoutProvider` adapters, Refund workflows, Optional Variable-Weight Settlement, and Pro Tax Automation.
- **Exit Gate:** All Module 10 and 11 tests pass, including historical commission immutability, double-entry balance reconciliation, and webhook replay protection.

### Phase 6 — High-Value Pro Modules ($1$ Bounded Context at a Time)
1. Staff & Groups $\rightarrow$ 2. Multi-Location Fulfilment $\rightarrow$ 3. Memberships $\rightarrow$ 4. Delivery $\rightarrow$ 5. Analytics $\rightarrow$ 6. Affiliate $\rightarrow$ 7. Advanced Catalog / Ultimate (SPMV, Bulk Importer, Typesense/Meilisearch/OpenSearch adapters) $\rightarrow$ 8. AI Suite.
- **Exit Gate per Module:** Contract, migration, inactive zero-footprint test, tenant security matrix, and scale benchmark must pass before starting the next module.

---

## 12. First 13 Implementation Tickets (With Mandatory Test Deliverables)

1. **Ticket 1 — Repo Scaffold, Static Analysis, & Architecture Tests:** Create `mercatus` and `mercatus-pro` packages, CI workflow (MySQL 8.0, Redis, Memcached, WP 6.6+, WC 9.2+ HPOS), PHPStan Level 8, and `tests/Architecture/DependencyRulesTest.php`.
2. **Ticket 2 — Domain, Cache, & Security Matrix Specification:** Freeze `openapi/mercatus-v1.yaml` skeleton and `tests/Security/PolicyMatrixDataProvider.php`.
3. **Ticket 3 — ADR `0001` & Core SDK Interfaces:** Commit `docs/architecture/decisions/0001-api-first-modular-monolith.md` and `Mercatus\Core\Contracts\*` with contract tests.
4. **Ticket 4 — Kernel & Module Manifest Registry:** Implement boot kernel and `ModuleManifest` validation + `tests/Unit/Kernel/ModuleManifestValidatorTest.php` and `tests/Integration/Kernel/InactiveProModuleZeroFootprintTest.php`.
5. **Ticket 5 — Multi-Tier `CachePort` (L1 + Redis/Memcached + Stampede Locks):** Implement `CachePort` adapters + `tests/Integration/Cache/RedisCacheAdapterTest.php`, `MemcachedCacheAdapterTest.php`, and `SingleflightLockConcurrencyTest.php`.
6. **Ticket 6 — Migration Runner & Two-Lane Outbox Worker:** Implement `wp_mercatus_schema_versions` and `wp_mercatus_events_outbox` (`FOR UPDATE SKIP LOCKED` + coalescing) + `tests/Integration/Infrastructure/MigrationRunnerTest.php` and `SkipLockedParallelWorkerTest.php`.
7. **Ticket 7 — Actor Resolution, Policy Engine, & `/me` Bootstrap:** Implement cached `ActorIdentity`, policy dispatcher, audit log, and `/mercatus/v1/me` + `tests/Unit/Identity/PolicyEngineMatrixTest.php` and `tests/Integration/Identity/CachedActorIdentityInvalidationTest.php`.
8. **Ticket 8 — REST Base Controller, Lazy Schemas, & OpenAPI Validator:** Implement base controller, error envelope, cursor pagination, ETags, `Cache-Control`, and `tests/Contract/OpenApiSchemaConformanceTest.php`.
9. **Ticket 9 — API Client Scopes, OAuth2/PKCE, Rate Limiter, & Idempotency:** Implement `wp_mercatus_api_clients`, `wp_mercatus_idempotency`, and Redis atomic rate limiter + `tests/Security/RateLimiterAndIdempotencyTest.php`.
10. **Ticket 10 — Minor-Unit Wallet Accounts, Journal, & Holds:** Implement `wp_mercatus_wallet_*` tables and commands + `tests/Unit/Wallet/MinorUnitWalletMathTest.php` and `tests/Security/ConcurrentWalletSpendRaceConditionTest.php`.
11. **Ticket 11 — Vendor Lifecycle, Storefront, & Default Fulfilment Location:** Implement `wp_mercatus_vendors`, `wp_mercatus_stores`, `wp_mercatus_fulfilment_locations`, and `StorePolicy` + `tests/Integration/Vendor/VendorStoreWorkflowTest.php` and `tests/Security/StoreCrossTenantIsolationTest.php`.
12. **Ticket 12 — Scale Benchmark Seeder (`wp mercatus seed:scale`) & Performance Harness:** Implement deterministic CLI seeder ($2,000$ vendors, $5,000$ categories, $2,000$ attributes, $100,000$ products, $300,000$ offers) + `tests/Scale/BaselineQueryAndLatencyGateTest.php`.
13. **Ticket 13 — High-Scale Catalog, Two-Tier Attributes, & Offer Availability Projection:** Implement `wp_mercatus_catalog_products`, `wp_mercatus_attributes`, `wp_mercatus_catalog_facets`, and `wp_mercatus_offer_availability` + `tests/Scale/HundredThousandProductFacetBenchmarkTest.php`.

---

## 13. Locked Architectural Decisions Summary

1. **Namespaces & Slugs:** `mercatus` and `mercatus-pro` plugin slugs; `Mercatus\Core\` and `Mercatus\Pro\` PHP namespaces; `/wp-json/mercatus/v1` REST namespace; `wp_mercatus_*` and `wp_mercatus_pro_*` tables; `mercatus_*` cache groups.
2. **Pro Packaging:** Single `mercatus-pro` plugin zip containing modular bounded contexts; inactive modules have zero runtime, hook, route, or schema footprint.
3. **Required Stack:** PHP 8.2+, WordPress 6.6+, WooCommerce 9.2+ with HPOS (`custom_order_tables`) mandatory, MySQL 8.0+ / MariaDB 10.6+, and Redis/Memcached persistent object cache required for production scale ($>1,000$ products).
4. **Authentication:** WP Cookie + Nonce for browser UI; Application Passwords (`wp_mercatus_api_clients`) for server-to-server; OAuth 2.0 + PKCE short-lived JWT + Refresh Token for mobile apps.
5. **Money Representation:** `BIGINT` in minor currency units + `CHAR(3)` ISO currency code for all balances, holds, ledger entries, and order/allocation/tax totals; `DECIMAL(20,6)` strictly for unit prices, tax rates, and percentage rates.
6. **Clean-Install Policy:** Version 1 is strictly a clean-install product with zero WCFM legacy bridges.
7. **Zero-Untested-Code Policy:** Every PR/ticket must pass all 6 test layers (Architecture, Unit, Contract, Integration, Security, and Scale) before merging.