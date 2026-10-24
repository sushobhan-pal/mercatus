# Mercatus — Master Architecture, Decisions & Testing Constitution

**Version:** 2.7, frozen 2026-10-23.
**Scope:** the single source of truth for **Mercatus** (free) and **Mercatus Pro**: product decisions, architecture, data model, security, testing and roadmap.
**Change control:** this document is frozen. A change needs the product owner's approval, is made directly in the section it belongs to, and raises the version. The previous version is archived in `archive/`.

## How to use this document

- Every rule has a stable ID, such as `TAX-5` or `G-DB-1`. Code reviews, tickets, commit messages and module decision files cite IDs instead of restating rules (DRY-4).
- Section 2 lists automatic rejection triggers. Code that violates one, or lacks the tests Section 14 requires, is rejected.
- Before a major module is built, it gets its own decision file (P-4).
- Undecided items exist only in Section 16.

**Contents:** 1 Principles · 2 Code-review guardrails · 3 Product scope · 4 Platform, caching & performance · 5 Architecture · 6 Catalog · 7 Orders · 8 Tax · 9 Money · 10 Pro modules · 11 Identity & security · 12 REST API & outbox · 13 Database schema · 14 Testing · 15 Roadmap & tickets · 16 Open items

---

## 1. Principles

- **P-1 Configurable business rules.** Mercatus serves many kinds of marketplaces in many countries. Rules that differ between business models (commission bases, who receives shipping and tax, completion and payout timing, and similar) are admin settings with sensible defaults, never a single hard-coded rule.
- **P-2 Extension seams for deferred features.** A feature deferred from v1 gets an extension seam now (a policy, a contract, or a documented filter per ARCH-8), so it can be added later without rewriting the flow.
- **P-3 DRY.** The codebase stays compact and maintainable: one source of truth per fact and one implementation per cross-cutting concern (DRY-1 to DRY-4).
- **P-4 Module decision files.** Before a major module is implemented, it gets its own decision file at `decisions/<module-id>.md` (for example `decisions/pro.shipment-tracking.md`). A module file cites this document's IDs instead of restating them and may not contradict it; a conflict is resolved by changing this document first. Section 16 indexes the module files (OPEN-5).
- **P-5 Independent of WCFM.** Mercatus has no runtime dependency, shared namespace, shared tables, compatibility layer, data migration bridge or integration with WCFM. Version 1 is a clean-install product with its own namespace, schema, API, settings, roles/capabilities and release cycle.
- **P-6 API-first.** Every business action is a documented, permission-checked command or query. Every first-party UI calls the same versioned REST API available to approved third-party clients. Controllers adapt HTTP requests to commands and queries; domain and application services own all rules; WordPress and WooCommerce are infrastructure adapters around that core.
- **P-7 Fast at scale, with or without a persistent object cache.** Targets: thousands of vendors, 100,000–500,000+ products, thousands of categories and attributes. Avoid redundant work, loopback requests, unbounded queries, `wp_postmeta`/`wp_term_relationships` multi-join scans, and synchronous work that can run asynchronously. Query ceilings are enforced in CI (PLAT-10).
- **P-8 Zero untested code.** No feature, bugfix, migration or endpoint is implemented until its tests pass across every applicable layer of Section 14.

---

## 2. Code-Review Guardrails (Automatic Rejection Triggers)

A reviewer, human or AI, **MUST REJECT** a pull request, commit or generated change that contains any of the following.

1. **Architecture & boundaries**
   - **G-BND-1** A REST controller contains business rules, state transitions, or direct `$wpdb`/SQL instead of delegating to an Application Command or Query.
   - **G-BND-2** Domain or Application code reads `$_POST`, `$_GET`, `$_REQUEST` or `$_SERVER`, calls `wp_send_json*()`, outputs HTML, or calls WordPress translation functions (ARCH-7).
   - **G-BND-3** Any class in `Mercatus\Core\` references a class in `Mercatus\Pro\`.
   - **G-BND-4** Any class in `Mercatus\Pro\` references a Core class outside `Mercatus\Core\Contracts\*` and the shared kernel `Mercatus\Core\Shared\*` (ARCH-4), queries or alters a table owned by Core or another Pro module, or reads private options instead of using those published APIs (PRO-1).
   - **G-BND-5** An in-process component (block, SSR template, WooCommerce hook, CLI command, queue worker) makes an HTTP loopback call to `/wp-json/mercatus/v1` instead of calling the Application layer directly (ARCH-9).
   - **G-BND-6** A database transaction wraps WooCommerce CRUD writes (ARCH-10).
   - **G-BND-7** A module rebuilds something WooCommerce already provides: the cart and checkout API, emails, the order-pay page, the shipping method API, or tax maths (ARCH-12).
2. **Database & scale**
   - **G-DB-1** A list on a Mercatus-owned surface uses `WP_Query`, `get_posts()`, `wc_get_products()`, or joins `wp_postmeta`/`wp_term_relationships` instead of the read projections (CAT-5).
   - **G-DB-2** A Tier 2 filtering or specification attribute is registered as a global `pa_*` taxonomy (CAT-2).
   - **G-DB-3** A list endpoint runs queries in a loop (N+1), or uses `OFFSET` pagination on a high-volume table instead of a cursor (API-1).
   - **G-DB-4** Code writes directly to WooCommerce-owned tables (`wp_wc_orders*`, `wp_posts`, `wp_postmeta`) instead of WooCommerce CRUD and HPOS data stores (ARCH-12). The one exception is vendor tax-rate rows in `woocommerce_tax_rates`, written as TAX-12 describes.
   - **G-DB-5** Code relies on legacy `wp_posts` order storage instead of HPOS (PLAT-1).
   - **G-DB-6** An event publisher schedules one Action Scheduler row per event instead of writing to the outbox (API-3).
   - **G-DB-7** A table uses a hard-coded charset or collation (DATA-1).
3. **Caching & coordination**
   - **G-CACHE-1** Code calls `wp_cache_flush()` or scans keys instead of bumping version pointers (PLAT-5).
   - **G-CACHE-2** Code talks to Redis or Memcached directly, or branches on whether a persistent object cache exists, outside the `CachePort` and `CoordinationPort` adapters (PLAT-3).
   - **G-CACHE-3** Code writes per-product or per-user transients, or large autoloaded options (PLAT-4).
   - **G-CACHE-4** An authenticated endpoint resolves the actor through live SQL joins on every request instead of the identity service, or a suspension or staff change fails to bump the actor's identity revision (SEC-2).
   - **G-CACHE-5** Correctness (idempotency, uniqueness, money) depends on the cache or `CoordinationPort` instead of the database (PLAT-6), or an ETag is derived from a cache revision number instead of the database `version` (PLAT-9).
4. **Money & tax**
   - **G-MONEY-1** Balances, holds, ledger entries, allocations or commissions use PHP `float` instead of `int` minor units with a currency code (DATA-1).
   - **G-MONEY-2** Unit prices, percentages or tax rates use `float` instead of exact decimal strings backed by `DECIMAL(20,6)` (DATA-1).
   - **G-MONEY-3** A child order is created before the payment conditions in ORD-2, or a historical tax or commission snapshot is recalculated after a rule changes (MON-1, TAX-5).
   - **G-MONEY-4** A table has an editable balance column without an immutable journal entry and an optimistic `version` check (MON-8).
   - **G-MONEY-5** A money change (ledger entry, hold, capture, earnings credit or reversal) runs in an asynchronous outbox handler instead of the command's transaction (ARCH-11).
   - **G-MONEY-6** Mercatus calculates tax amounts itself, or builds tax snapshots from its own calculation instead of the saved order's line taxes (TAX-5).
5. **Security**
   - **G-SEC-1** A controller uses scattered `current_user_can()` checks instead of a Policy class (SEC-2).
   - **G-SEC-2** A dynamic SQL value is interpolated without `$wpdb->prepare()`, or an `ORDER BY`/column identifier is not taken from a static allow-list (SEC-3).
   - **G-SEC-3** A financial, stock-changing or external-provider `POST` lacks `Idempotency-Key` enforcement or writes the idempotency row after the command runs (PLAT-7), or a `PATCH`/`PUT` lacks `If-Match` (PLAT-9).
   - **G-SEC-4** Another tenant's resource returns 403 instead of 404 (SEC-4).
   - **G-SEC-5** Vendor rich text is not cleaned on save and escaped on output, an upload bypasses the MIME/SVG policy, or an outbound request bypasses the allow-list (SEC-7).
   - **G-SEC-6** A secret is encrypted with a key derived from WordPress salts (SEC-6).
   - **G-SEC-7** A vendor is granted a WooCommerce capability such as `edit_products` (SEC-8).
6. **Testing**
   - **G-TEST-1** A new Command, Query, Policy, Repository, Controller, Migration or Module arrives without the tests Section 14 requires.
   - **G-TEST-2** A repository test mocks `$wpdb` instead of using the integration database.
   - **G-TEST-3** A query ceiling regresses on the scale benchmark, with or without a persistent object cache (PLAT-10).
7. **DRY, configurability & extension**
   - **G-DRY-1** A cross-cutting concern is re-implemented instead of using the shared kernel (DRY-2).
   - **G-DRY-2** A fact gets a second source of truth (DRY-1).
   - **G-DRY-3** DTOs are shared across bounded contexts, or Pro reaches into Core internals, to save lines (DRY-3).
   - **G-EXT-1** A rule that differs between business models is hard-coded instead of being an admin setting with a default (P-1).
   - **G-EXT-2** A public WordPress filter is added outside a documented decision point, or over internal arrays, or a domain event reaches WordPress other than through the single post-commit bridge (ARCH-8).
   - **G-EXT-3** A decision with a single decision point is made elsewhere: store tax (`StoreTaxPolicy`, TAX-2), rate choice (`TaxRateResolver`, TAX-5), commission rules (`CommissionRuleSource`, MON-1), child-order visibility (`ChildOrderExposure`, ORD-7) or order completion (`OrderCompletionPolicy`, ORD-12).

---

## 3. Product Scope

- **SCOPE-1 Two plugins.** **Mercatus** (free) is the marketplace platform: public, admin and vendor APIs, extension SDK, security, caching, core data ownership, and the vendor, store, catalog, tax, shipping, commission, ledger, withdrawal and order-split workflows. **Mercatus Pro** (paid) is a single package of independently activatable modules that depend on Mercatus and extend it only through published contracts. Installing Pro turns nothing on. An inactive module contributes zero routes, workflow states, scheduled jobs, queries, event subscribers or checkout-time calculations; its migrations run on activation, and its tables are kept on deactivation.
- **SCOPE-2 Free is a complete marketplace.** The admin can run a real marketplace on Free alone, including commission and vendor withdrawals. The split:

| Domain | Mercatus (Free) | Mercatus Pro (Paid) |
|---|---|---|
| **Platform** | API kernel, module SDK, cache engine (Redis/Memcached optional), typed settings registry, migrations, two-lane outbox worker, audit/health, roles/capabilities | Licensing and Pro module activation |
| **Vendor & storefront** | Vendor application/approval, one public storefront per vendor, profile, policies, visibility, store list, one default fulfilment location | Verification/KYC, multi-location fulfilment, vacation/scheduled availability, advanced SEO, branded storefronts |
| **Catalog** | Vendor ownership of WooCommerce products, category-scoped attributes, facet and availability projections, media ownership/quotas, moderation basics | Multi-vendor offers (SPMV), catalog cloning, bulk importer, external search adapters, variable-weight settlement, advanced product rules, bookings/subscriptions/auctions integrations |
| **Orders** | Parent order plus one child order per vendor for every paid order; fulfilment status and shipment records; completion triggers; admin parent overrides (ORD-1 to ORD-12) | Shipment Tracking (PRO-2); OTP and carrier-confirmed delivery (ORD-12); advanced workflows; returns/disputes automation |
| **Tax** | WooCommerce tax for every item, or per-vendor store tax with approval and fallback; per-allocation snapshots; refunds; basic tax reporting (TAX-1 to TAX-12) | Automated tax providers as `TaxRateResolver` adapters; registration validation; e-invoices; filing integrations (TAX-11) |
| **Money** | Commission engine with global flat, percent and flat + percent; coupon, shipping and tax settings; ledger; earnings release; manual withdrawals (MON-1 to MON-11) | Commission rule sources; Stripe and PayPal payout and split modes; automatic payouts; reverse withdrawals; reconciliation; per-vendor holds and rolling reserve (PRO-3, PRO-5) |
| **Customer wallet** | None | Refund credit and promotional credit, no top-up (PRO-4) |
| **Shipping** | Vendor shipping settings, zones, methods/rates, ship-from address, basic pickup, test-quote API, WooCommerce shipping integration | Location-based pickup and delivery, routing rules, distance/weight/country rules, delivery agents, routes and time slots (PRO-6) |
| **Engagement** | Public store reviews and moderation | Groups & Staff, Memberships, Affiliate, advanced notifications, support extensions |
| **Data & intelligence** | Exportable basic data and event stream | Analytics, scheduled reports, AI Suite with explicit provider consent and quotas |

- **SCOPE-3 Storefront scope.** Mercatus owns vendor storefronts, the marketplace dashboard (ARCH-13) and its own catalog and offer blocks. WooCommerce's shop and category pages remain WooCommerce's.
- **SCOPE-4 Not in version 1:**
  - an OAuth2/PKCE server (SEC-1), H3 indexing (CAT-6), a direct Redis/Memcached client (PLAT-3), a Mercatus payment bridge for classic gateways (ARCH-12), CDN purge by tag (PLAT-3);
  - third-party tax plugin support (TAX-11);
  - a customer wallet in Free, and wallet top-up anywhere (PRO-4);
  - replacing WooCommerce's shop and category pages (SCOPE-3);
  - WooCommerce's legacy `wp_posts` order storage (PLAT-1);
  - rebuilding every historical setting, template, gateway and integration; proxying `admin-ajax.php` or mutating `$_POST` to reuse other plugins' controllers; letting Pro replace or patch Core internals; floating-point or stringly typed money; importing or syncing WCFM data (P-5).

---

## 4. Platform, Caching & Performance

- **PLAT-1 Required stack.** PHP 8.2+, WordPress 6.6+, WooCommerce 9.2+ with HPOS (`custom_order_tables`) mandatory, MySQL 8.0+ or MariaDB 10.6+. Both database minimums reach end of life in 2026, so CI also runs MySQL 8.4 LTS and MariaDB 10.11 and 11.4.
- **PLAT-2 Redis and Memcached are optional.** Mercatus supports and optimises for a persistent object cache, but every feature works without one.
- **PLAT-3 `CachePort`.** Caching goes through one `CachePort`, implemented only over `wp_cache_*` (including `wp_cache_get_multiple()` and `wp_cache_supports()`). Business code never checks whether Redis exists; only the `CachePort` and `CoordinationPort` adapters do, once at boot, through `wp_using_ext_object_cache()`. Nothing uses a direct PhpRedis or Memcached client. Cache layers:
  - **L1, per request:** repeated lookups of the actor, module manifest, feature flags or policies within one request cost no network hop.
  - **L2, persistent object cache when present:** groups `mercatus_actors`, `mercatus_capabilities`, `mercatus_stores`, `mercatus_offers`, `mercatus_availability`, `mercatus_facets`, `mercatus_categories`, `mercatus_shipping`, `mercatus_locks` and `mercatus_ratelimits`. List endpoints fetch cached projections in one `wp_cache_get_multiple()` call, query only the misses, and backfill. Hot keys carry a soft expiry inside the payload and a longer hard TTL; when soft-expired, one worker takes a non-blocking `CoordinationPort` lock to refresh while others receive the stale value.
  - **L3, HTTP:** public reads (`GET /stores`, `GET /stores/{slug}`, `GET /catalog/offers`, `GET /catalog/categories`) send an ETag (PLAT-9) and `Cache-Control: public, max-age=60, stale-while-revalidate=300`. They may send `Cache-Tag`/`Surrogate-Key` headers, but CDN purge by tag is not in v1. Authenticated reads send `Cache-Control: private, no-cache` with an ETag, so browsers get fast `304 Not Modified` responses.
- **PLAT-4 Without a persistent object cache.** Speed comes from the indexed `wp_mercatus_*` read projections. Transients hold only a fixed, bounded set of summary values (for example filter counts per category), never one per product or per user. No large options are autoloaded. `GET /mercatus/v1/health` reports that no persistent cache is present and that rate limiting is off (PLAT-8).
- **PLAT-5 Version-stamped keys.** Never flush the cache or scan keys. Each entity or collection key includes a version pointer, for example `mercatus_availability:p:{product_id}:v:{product_rev}:loc:{location_id}`. When stock, price, promotion or location settings change, Mercatus increments `mercatus_rev:product:{product_id}`. Pointers start from the current time, not 1, so an evicted pointer can never revive old entries, and are created with `add` before `incr`, because Memcached returns false on a missing key. Revision pointers only key cache entries. Clearing a cache group (`POST /system/cache/flush-group`) bumps that group's version.
- **PLAT-6 `CoordinationPort`.** Coordination is separate from caching. `CachePort` is best effort: losing it only costs speed. `CoordinationPort` provides atomic counters and locks that hold across requests, using `wp_cache_incr`/`wp_cache_add` when a persistent object cache is present. Without one it reports counters and locks as unavailable, and best-effort users (rate limiting, stampede locks) switch off. Correctness never depends on it: idempotency (PLAT-7), the order split (ORD-2), the outbox (API-3) and ledger balances (MON-8) use the database directly. A database-backed implementation is built only when a feature first needs cross-request coordination without Redis (P-2).
- **PLAT-7 Idempotency writes its row first.** `Idempotency-Key` is required on financial, stock-changing, checkout and external-provider `POST`s. The `wp_mercatus_idempotency` row is inserted with status `processing` and a request hash before the command runs, so its unique key is the lock. A repeat of the same key returns the stored response if the first request finished, 409 if it is still processing, and 422 if the payload differs. No cache lock is involved, with or without Redis.
- **PLAT-8 Rate limiting needs a persistent object cache.** With one, every route is rate-limited through `CoordinationPort` counters, and the limits are admin settings with defaults (P-1). Without one, Mercatus does no rate limiting; the health check says so and points to page-cache, CDN or firewall limits.
- **PLAT-9 ETags come from the database.** A single item's ETag is built from its `version` column; a list's ETag is a hash of the IDs and versions on that page, which are already fetched. `If-Match` is required on `PATCH`/`PUT`, checks the same `version`, and returns `409 Conflict` on a mismatch. ETags therefore stay stable with or without a persistent cache. With one, a cached response stores its ETag, so a 304 needs no database query.
- **PLAT-10 Performance ceilings.** Query ceilings are hard CI gates, counted as Mercatus's own queries and measured both with and without a persistent object cache. Latency targets measure Mercatus's handler time, excluding WordPress and WooCommerce bootstrap, and are tracked as trends, not blocking gates. The scale suite runs weekly, on demand and on pull requests labelled `scale`, from a saved database dump (TEST-1, Layer 6). Seeded data: 2,000 vendors, 5,000 categories, 2,000 attributes, 100,000 products, 300,000 offers.

| Operation / endpoint | Max Mercatus queries (warm persistent cache) | Max Mercatus queries (cold, or no persistent cache) | $p_{95}$ latency target (warm / cold) |
|---|---|---|---|
| `GET /mercatus/v1/me` | $0\text{--}1$ | $\le 4$ | $< 15\text{ ms}$ / $< 45\text{ ms}$ |
| `GET /mercatus/v1/stores/{slug}` | $0$ | $\le 2$ | $< 12\text{ ms}$ / $< 35\text{ ms}$ |
| `GET /mercatus/v1/catalog/offers` (24 items + facets) | $0\text{--}1$ | $\le 3$ | $< 25\text{ ms}$ / $< 95\text{ ms}$ |
| `GET /mercatus/v1/catalog/categories/{id}/schema` | $0$ | $\le 2$ | $< 10\text{ ms}$ / $< 30\text{ ms}$ |
| Cart line offer + location + tax rate resolution | $\le 2$ | $\le 5$ | $< 35\text{ ms}$ / $< 80\text{ ms}$ |
| Stage 1 allocation (3 vendors, 6 lines) | $\le 8$ writes | $\le 12$ ops | $< 120\text{ ms}$ (excluding gateway HTTP) |

---

## 5. Architecture

- **ARCH-1 Layers.** Dependencies point strictly inward:

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

- **ARCH-2 Module layout.** Every module is laid out as `{Module}/{Domain,Application,Infrastructure,Api}`, and Deptrac enforces the layers.
- **ARCH-3 Repositories and directories.** Two distributions, `mercatus` and `mercatus-pro`, with a contract package (the contracts and the shared kernel) generated from Core:

```text
mercatus/
  mercatus.php
  composer.json             # PSR-4; third-party libraries prefixed with Strauss or PHP-Scoper (ARCH-4)
  phpstan.neon
  phpcs.xml.dist            # ARCH-5
  deptrac.yaml
  phpunit.xml.dist
  src/
    Kernel/                 # boot, module registry, config, typed settings registry, migration runner
    Shared/                 # shared kernel (DRY-2), published to Pro, semantic-versioned
    Contracts/              # public SDK, semantic-versioned: ModuleManifest, CachePort, CoordinationPort,
                            #   TaxRateResolver, StoreTaxPolicy, CommissionRuleSource, OrderCompletionPolicy,
                            #   CompletionTrigger, ChildOrderExposure, PayoutProvider, CatalogSearchPort
    Identity/               # actors, capabilities, policies, API clients
    Vendor/                 # vendor lifecycle and assignments
    Store/                  # profile, visibility, public storefront data
    Catalog/                # ownership, two-tier attributes, facets, projections, WooCommerce product adapter
    Orders/                 # allocations, child orders, fulfilment status, shipment records, completion
    Tax/                    # StoreTaxPolicy, TaxRateResolver implementations, snapshots
    Shipping/               # vendor shipping profiles, zones, methods, quotes
    Commission/             # commission engine and global rule
    Ledger/                 # accounts, entries, holds, earnings release, manual withdrawals
    Reviews/
    Notifications/
    Infrastructure/         # cross-module adapters only: wpdb connection, CachePort and CoordinationPort
                            #   over wp_cache_*, outbox worker, WordPress/WooCommerce bootstrap
    Internal/               # private helpers; forbidden to Pro and external plugins
  database/
    migrations/             # the schema's source of truth (DRY-1)
    seeders/                # ScaleBenchmarkSeeder (wp mercatus seed:scale), grown module by module
  openapi/
    mercatus-v1.yaml        # generated from the PHP API definitions, committed and diff-checked (DRY-1)
  ui/                       # React app: the marketplace dashboard (ARCH-13)
  compose.yaml              # local test environment (TEST-4)
  .github/workflows/        # CI matrix (TEST-4)
  tests/
    Architecture/ Unit/ Contract/ Integration/ Security/ Scale/ EndToEnd/ Js/ Fixtures/

mercatus-pro/
  mercatus-pro.php
  composer.json, phpstan.neon, phpcs.xml.dist, deptrac.yaml, phpunit.xml.dist
  src/
    Licensing/
    ShipmentTracking/       # PRO-2
    Commissions/            # additional commission rule sources (MON-1)
    Payouts/                # automatic and scheduled payouts, reverse withdrawals, reconciliation (PRO-5)
    PaymentIntegrations/    # Stripe and PayPal, payout and split modes (PRO-3)
    Refunds/
    Wallet/                 # customer wallet on the Core ledger (PRO-4)
    Memberships/
    Staff/
    Delivery/               # local delivery, agents, OTP confirmation (PRO-6)
    Analytics/
    Affiliate/
    Ai/
    MultiLocationFulfilment/
    VariableWeightSettlement/   # PRO-7
    TaxAutomation/          # automated TaxRateResolver adapters (TAX-11)
    AdvancedCatalog/
    Integrations/
  database/
    migrations/
  tests/
    Architecture/ Unit/ Contract/ Integration/ Security/ Scale/ EndToEnd/
```

- **ARCH-4 Packaging and naming.** Composer PSR-4 autoloading with prefixes `Mercatus\Core\` and `Mercatus\Pro\`. Third-party PHP libraries are prefixed with Strauss or PHP-Scoper. Pro builds against Core's published code, the contracts (`Mercatus\Core\Contracts\*`) and the shared kernel (`Mercatus\Core\Shared\*`), but ships neither; every other Core namespace is internal to Core. Global singletons are avoided except a minimal `mercatus()` facade that returns public interfaces. Plugin slugs are `mercatus` and `mercatus-pro`; the REST namespace is `/wp-json/mercatus/v1`; tables are `wp_mercatus_*` (Core) and `wp_mercatus_pro_*` (Pro); every public function, option, transient, cache group, hook and capability name starts with `mercatus`.
- **ARCH-5 Tooling.** PHPUnit is the test runner, with Deptrac for architecture rules. PHPStan runs at level 8 or higher. PHP_CodeSniffer uses WordPress-Extra without the file-name and naming sniffs; escaping, nonce, prepared-SQL and i18n sniffs stay on.
- **ARCH-6 Module manifest.** Each module declares a manifest before it is loaded. Mercatus validates the manifest, version compatibility, unique IDs, routes and permissions, dependency order and migration state, and exposes a read-only feature manifest at `GET /mercatus/v1/features`, also embedded in `GET /mercatus/v1/me`, so UIs can feature-detect. In Mercatus (free), Reviews and Shipping are optional: the admin switches them on or off on a Modules screen that lists optional modules only. Every other free module is always on, and every Pro module is optional. A module that is switched off keeps its data and leaves no footprint: no hooks, routes, menu items or queries. Core contracts and the shared kernel follow semantic versioning: a contract or shared-kernel class is deprecated for at least one supported minor release, with an upgrade guide, and removed only in the next major.

```php
return new ModuleManifest(
    id: 'pro.payouts',
    version: '1.0.0',
    requires: ['core' => '^1.0'],
    provides: ['payouts'],
    routes: PayoutRoutes::class,
    migrations: PayoutMigrations::class,
    permissions: PayoutPermissions::class,
    dashboardMenu: PayoutMenu::class,
    eventSubscribers: [PayoutSubscriber::class],
    healthChecks: [PayoutHealthCheck::class],
);
```

- **ARCH-7 Errors.** Domain code returns error codes; the API layer translates them into messages.
- **ARCH-8 Extension points.** The published extension points are exactly:
  1. **Commands and queries:** typed interfaces for work owned by a module.
  2. **Domain events:** immutable event name, event ID, occurrence time, aggregate ID and version, actor, correlation ID and payload schema. Every committed event is also passed to WordPress through `do_action()`, from one bridge.
  3. **Policies and capabilities:** named capabilities and policy interfaces. Policies are the single decision points for cross-cutting choices (`StoreTaxPolicy`, `TaxRateResolver`, `CommissionRuleSource`, `OrderCompletionPolicy`, `ChildOrderExposure`).
  4. **REST route registration:** through the registrar only, with lazy schema loading and a permission callback.
  5. **Read-model and field extensions:** namespaced fields with explicit visibility rules.
  6. **Documented WordPress filters at policy decision points only,** named `mercatus_{subject}_{decision}`: `mercatus_store_tax_enabled` (TAX-2), `mercatus_commission_flat_basis` (MON-2) and `mercatus_earnings_hold_days` (PRO-5). Never filters over internal arrays.
  7. **Dashboard menu items and screens,** declared in the module manifest with the capability each requires (ARCH-13).
  8. **Module registration:** add-ons, Mercatus Pro included, register their module manifests on the `mercatus_register_modules` action through the `ModuleRegistrar` contract.
- **ARCH-9 In-process execution.** Server-rendered blocks, WooCommerce hooks, CLI commands and workers call Application Commands and Queries in memory, never through an HTTP loopback. Buyer-facing Mercatus pages hydrate above-the-fold data in the first PHP response with the same DTO serializer as the REST API. The marketplace dashboard loads the actor, capabilities, features, store context and menu in one `/mercatus/v1/me` call. `GET` queries read projections and the cache and serialize straight to DTOs, without hydrating rich aggregates or instantiating `WC_Product`/`WC_Order` in loops.
- **ARCH-10 Transactions.** Database transactions wrap only `wp_mercatus_*` writes. WooCommerce writes happen outside them and are safe to retry.
- **ARCH-11 Money is synchronous.** Every money change happens synchronously in its command's transaction. The outbox carries side effects only (API-3).
- **ARCH-12 WooCommerce boundary.** Mercatus keeps WooCommerce for its payment gateways, product-type plugins (Bookings, Subscriptions, Auctions), cart, checkout and tax engine, and reuses it wherever it already does the job:
  - **Cart and checkout:** WooCommerce's classic checkout and Store API, extended through `ExtendSchema` and checkout hooks. Mercatus has no cart or checkout API of its own. Headless clients pay with classic gateways through WooCommerce's order-pay URL (`$order->get_checkout_payment_url()`).
  - **Emails** use `WC_Email`; **shipping** uses the WooCommerce shipping method API; **tax maths** is WooCommerce's (TAX-5).
  - **Writes** go through WooCommerce CRUD and HPOS data stores, never directly into WooCommerce tables.
  - **Price and stock:** Mercatus offers and inventory are the source of truth; WooCommerce `_price` and `_stock` are copies written by Mercatus.
  - **Projection sync:** WooCommerce product and stock saves made outside Mercatus update the read projections, and a nightly job reconciles them. A vendor's own edit updates that product's projection row immediately.
- **ARCH-13 One marketplace dashboard.** All Mercatus management happens in one uncluttered, full-screen dashboard on a frontend page, shared by every role: administrators, marketplace managers, vendors, vendor staff and, with their Pro modules, delivery agents and affiliates. Each user sees only the menu items and screens that their actor type, capabilities and active modules allow. Mercatus adds no management screens to wp-admin beyond a menu link that opens the dashboard, and vendors never enter wp-admin (SEC-8). The dashboard is a React app built with `@wordpress/scripts`, `@wordpress/components` and `@wordpress/data`, talking only to the Mercatus REST API. Each module declares its menu items and screens in its manifest (ARCH-6), with the capability each requires, and `/me` returns the menu for the current actor. A hidden menu item is never the security boundary: every screen's API calls pass the same policies (SEC-2). Buyer-facing store pages stay server-rendered blocks (ARCH-9).

### DRY rules

- **DRY-1 One source of truth per fact.** API shapes are defined once in PHP and generate the REST schemas, `openapi/mercatus-v1.yaml`, event schemas and TypeScript types; a contract test fails on drift. Migrations are the schema. One ledger holds all money (MON-8). One typed settings registry handles defaults, sanitising, REST exposure and admin forms.
- **DRY-2 Shared kernel.** One implementation each for Money, Currency and Allocator, IDs, Clock, the state machine, error codes, cursors, cache keys and revisions, the projection runner, the repository base and the base REST controller. Pro uses the same kernel instead of re-implementing it (ARCH-4).
- **DRY-3 Boundaries over line counts.** DTOs are not shared between bounded contexts, and Pro never reaches into Core internals (anything outside the contracts and the shared kernel), just to save lines. Shared code is extracted on the third repeat.
- **DRY-4 Documents.** Each rule is stated once, with an ID; tickets, reviews and module decision files cite the ID.

---

## 6. Catalog

- **CAT-1 Core model.** The internal path is always Catalog Product → Vendor Offer → Fulfilment Location → Inventory Balance → Order Line. Capability modules change cardinality, never the model:

| Capability module | Disabled | Enabled |
|---|---|---|
| **Multi-location fulfilment** | Every vendor gets one automatically created default location; location management is hidden and everything resolves to it. | Vendors add branches, pickup points, delivery locations and warehouses with separate inventory, staff, shipping, pickup, delivery, prices and promotions. |
| **Single Product, Multiple Vendors (SPMV)** | A catalog product allows one active vendor offer. | A catalog product allows several active offers; buyers choose by seller, price, location and fulfilment terms. |
| **Location-specific prices** | An offer's base price applies everywhere. | An offer-location may supply a validated price override. |
| **Location-specific promotions** | Only vendor-wide promotions apply. | Location-level promotions apply once the fulfilment location is known. |
| **Variable-weight settlement** | Fixed-quantity price, payment, child order, refund, commission and completion flow; no measurement fields. | Eligible offers sell by measured unit (PRO-7). |

- **CAT-2 Two-tier attributes.** Tier 1 attributes are native WooCommerce attributes, used only when they generate distinct purchasable variations (for example Size, Color). Tier 2 filtering and specification attributes live in `wp_mercatus_attributes`, `wp_mercatus_attribute_values`, `wp_mercatus_category_attribute_maps` and `wp_mercatus_catalog_facets`, never as global `pa_*` taxonomies.
- **CAT-3 Categories.** Categories are indexed with a materialized path, depth and leaf flag in `wp_mercatus_categories`. `GET /catalog/categories/{id}/schema` returns only the attributes mapped to that category branch. Category trees load lazily by parent (`GET /categories?parent_id=X`), and attribute values use cursor-based search (`GET /attributes/{id}/terms?search=...&cursor=...`).
- **CAT-4 Facet counts** are precomputed per category, or approximate.
- **CAT-5 Read projections.** Lists on Mercatus-owned surfaces (vendor storefronts, the marketplace dashboard, Mercatus catalog and offer blocks) read `wp_mercatus_offer_availability` and `wp_mercatus_catalog_facets` through repositories, never `WP_Query`, `get_posts()`, `wc_get_products()` or `wp_postmeta`/`wp_term_relationships` joins. Reading one product through WooCommerce CRUD to edit it is allowed.
- **CAT-6 Location discovery.** An address is geocoded only when a location is created or updated, never during cart or checkout. Locations and availability rows store `latitude`, `longitude` and an indexed 6-character geohash prefix (`geo_cell`). Searches query the target cell and its neighbours with an indexed `WHERE geo_cell IN (...)`, then compute exact distances in PHP for the matched candidates only.

---

## 7. Orders

- **ORD-1 Stage 1: allocation before payment.** Stage 1 runs for every way an order is created: classic checkout, Store API, admin, REST and Subscriptions renewals. After WooCommerce has saved the parent order with its final line items, coupons, shipping packages and taxes, Mercatus resolves the vendor offer, fulfilment location and commission (MON-1) for every line. In one transaction over Mercatus tables only (ARCH-10), it writes `wp_mercatus_order_groups`, immutable `wp_mercatus_order_allocations` with the commission snapshot, `wp_mercatus_tax_snapshots` copied from the saved order's line taxes (TAX-5), and `wp_mercatus_inventory_reservations`. Payment is collected once against the parent order through any WooCommerce gateway; split-pay gateways read the Stage 1 allocations (PRO-3).
- **ORD-2 Stage 2: child orders after payment.** Every paid order is split, even a single-vendor one: one child `shop_order` per `(parent_order_id, vendor_id)`, so there is one code path. Child orders are created on payment complete, on COD, or for allow-listed authorise-only gateways; never for a plain on-hold bank-transfer or cheque order. The split is idempotent and locked through the unique key on `wp_mercatus_vendor_orders`, so retries and concurrent hooks never create duplicates.
- **ORD-3 Reconciliation.** The sum of child financial totals (items, discounts, taxes, fees, shipping and shipping tax) equals the parent total exactly: $\sum_{i=1}^{k} \text{ChildTotal}_i = \text{ParentTotal}$.
- **ORD-4 Money lives in the allocations.** Child orders are generated from the allocations. A money change on a child goes through Mercatus, which records an append-only adjustment and re-syncs the child.
- **ORD-5 Refunds.** A refund is requested on the child and paid out through the gateway on the parent. A WooCommerce refund is created on both; only the parent's counts in reports.
- **ORD-6 Child order limits.** Child orders never take payment and never reduce or restore stock. Item changes after payment are recorded as adjustments.
- **ORD-7 Child order visibility.** The parent is the payment record; each child is that vendor's fulfilment record. Child orders carry a documented marker (the parent order ID and `created_via` = `mercatus`). One `ChildOrderExposure` policy decides which outside systems see them. In v1 they are hidden from WooCommerce Analytics, the customer's My Account orders and WooCommerce customer emails; vendors get their own emails. A new context, such as a tax plugin's order sync, is added to this policy and nowhere else.
- **ORD-8 Order status and fulfilment status.** Order status stays standard WooCommerce: `processing` after payment (`on-hold` for authorise-only payments), then `completed`, plus `cancelled` and `refunded`. There are no custom WooCommerce order statuses. Each vendor order also has a Mercatus fulfilment status: unfulfilled → partially shipped → shipped → delivered. Vendors mark their orders shipped and delivered. The model is shaped like WooCommerce's Fulfillments feature so it can be mirrored into it later.
- **ORD-9 Shipment records.** Core owns the shipment record: one package from one vendor order, with its items and quantities, status and dates (`wp_mercatus_order_fulfilments` and `wp_mercatus_order_fulfilment_items`). Pro modules attach tracking and delivery data by shipment ID (PRO-2, PRO-6).
- **ORD-10 Parent status.** The admin owns the parent order's status. Automation moves the parent only to the least-advanced stage among its active vendor orders, and completes it only when all of them are completed. Cancelled and refunded vendor orders are ignored unless every vendor order is cancelled or refunded.
- **ORD-11 Admin changes flow down.** Completing the parent completes all its vendor orders. Cancelling the parent cancels everything and, if the order is paid, refunds the customer in full. The admin confirms first, and the confirmation lists any vendor orders already shipped. Vendor earnings are reversed with ledger adjustments, even if that takes a vendor balance negative (MON-8). The refund goes through the parent's gateway; if the gateway can't refund automatically, the order is flagged for a manual refund.
- **ORD-12 Completion.** `OrderCompletionPolicy` alone decides when a vendor order completes; whichever enabled trigger fires first wins. Settings and defaults:
  - "Vendors can mark orders completed": on.
  - "Customers can confirm receipt", an "Order received" button per vendor order in My Account: on.
  - Auto-complete N days after "delivered" (default 3) and, as a backup, N days after "shipped" if the order is never marked delivered (default 14). Setting N to 0 turns it off.
  - "Complete digital-only vendor orders on payment": on, as WooCommerce does for virtual, downloadable orders.
  - Always: the admin can complete any order, and an open refund or return request pauses the timers.
  - Pro triggers implement `CompletionTrigger` and plug into the same policy: OTP at delivery (PRO-6), carrier-confirmed delivery (PRO-2), which marks the order delivered and so starts the timer, and bookings completing N hours after the booking starts.

---

## 8. Tax

- **TAX-1 Store tax.** Tax works for any country, the way WooCommerce does. An "Enable store tax" checkbox in the marketplace tax settings decides whether per-vendor tax applies. Off: WooCommerce's own tax settings and rates apply to every item. On: vendors' own rates apply. In both modes Mercatus splits and snapshots the calculated taxes per vendor, because child orders need their tax lines. Changing the setting affects only new carts; existing orders keep their snapshots.
- **TAX-2 One decision point.** Whether store tax applies is decided per vendor by `StoreTaxPolicy`, whose answer passes through the filter `mercatus_store_tax_enabled( bool $enabled, int $vendor_id )`. Version 1 reads only the admin checkbox; membership- or vendor-level settings can be added later without touching the tax flow. Because rates are chosen per cart item, a cart mixing vendors with and without store tax needs no special handling.
- **TAX-3 Rate approval.** A "Vendor tax rates need approval" checkbox, on by default. When on, a vendor's saved rates apply at checkout only after the admin approves them, and editing them needs approval again.
- **TAX-4 Vendors without approved rates.** With store tax on, an admin setting decides what happens to a vendor without approved rates (without saved rates, if approval is off): use the marketplace's WooCommerce rates (default), charge no tax, or block selling until their rates are approved, in which case their products can't be added to the cart.
- **TAX-5 WooCommerce does the maths.** Mercatus only chooses which rates apply to each item, using a `TaxRateResolver`, at the three places WooCommerce picks rates: the cart (`woocommerce_cart_totals_get_item_tax_rates`), order recalculation (`woocommerce_order_item_after_calculate_taxes`, because WooCommerce recalculates with the item's own tax class) and displayed prices (`woocommerce_get_price_including_tax` and `woocommerce_get_price_excluding_tax`, because shop and product pages never reach the cart hook). Version 1 ships two resolvers: WooCommerce rates and vendor store rates. A resolver may return rates or per-line tax amounts and is called once per vendor (one origin, one seller). Tax snapshots are copied from the saved order's line taxes, never from Mercatus's own calculation, so the parent always equals the sum of the vendor totals and the snapshots stay correct whoever calculated the tax.
- **TAX-6 Resolver input.** A `TaxContext` carries the seller (vendor), product tax class, fulfilment method (delivery, pickup, digital), origin, destination (the customer's address, or the pickup point for pickup items), transaction time and currency. With store tax on, the vendor's ship-from address replaces the shop base address. Prices entered including or excluding tax, tax rounding and tax display stay marketplace-wide, because WooCommerce applies them to the whole cart.
- **TAX-7 Tax-inclusive prices.** With store tax on, prices entered including tax keep the same gross price for every customer (`woocommerce_adjust_non_base_location_prices` = false); only the tax inside the price changes.
- **TAX-8 Recalculation.** Recalculating a child order uses that order's vendor (`woocommerce_order_before_calculate_taxes` and `woocommerce_order_get_tax_location`). A multi-vendor parent is never recalculated after the split. Before any recalculation, Mercatus saves the order's taxes. If neither the items nor the tax address changed since the taxes were written (compared with a fingerprint saved with them), it puts them back afterwards. If rates can't be resolved, it keeps the old tax and adds an order note. Tax is never silently lost.
- **TAX-9 No matching rate means no tax,** as in WooCommerce. The health check and the test-quote tool warn about vendors whose rates don't cover their destinations.
- **TAX-10 Shipping tax.** Each vendor's shipping tax is set by the Mercatus vendor shipping method on its own rates.
- **TAX-11 Third-party tax plugins.** WooCommerce Tax automated taxes, TaxJar and AvaTax are not supported in v1, whether store tax is on or off, and Mercatus ships no compatibility code for them. The health check shows a notice when one is active, for example because its order sync would also report child orders. Automated tax providers arrive later as `TaxRateResolver` adapters in their own modules (Pro Tax Automation), with child-order handling added through `ChildOrderExposure` (ORD-7), never as patches to the tax code.
- **TAX-12 Vendor tax and shipping settings.** Every vendor gets tenant-scoped Settings → Shipping and Settings → Tax screens and APIs, isolated from global `woocommerce_*` settings. Vendor tax rates use the same shape as WooCommerce's tax rate table (country, state, postcode, city, rate, name, priority, compound, shipping; per tax class). They are rows in WooCommerce's `woocommerce_tax_rates` under per-vendor classes that are never registered as WooCommerce tax classes, so WooCommerce's own location matching, order tax lines, refunds and reports apply to them (OPEN-1). The class is `mv{vendor_id}` for the standard rate and `mv{vendor_id}-{tax-class-slug}` for any other tax class, for example `mv12` and `mv12-reduced-rate`. The vendor ID comes first because WooCommerce indexes only the first 10 characters of `tax_rate_class`, so each vendor with an ID of up to 8 digits gets its own index prefix. The classes are only lookup keys for `WC_Tax::find_rates()`; they never reach products or order items. Mercatus writes these rows itself, because `WC_Tax::_insert_tax_rate()` turns an unregistered class into the standard class, and invalidates WooCommerce's `taxes` cache after each change. Rates waiting for approval (TAX-3) sit under a pending class until the admin approves them. A rate an order may reference is never deleted, only moved to an archive class, because WooCommerce rebuilds order tax lines from the rate row. A CI test fails if WooCommerce starts rejecting unregistered classes in rate lookups, or if its `tax_rate_class` index stops covering the vendor prefix. A read-only test quote (`POST /me/shipping-tax/test-quote`) returns the full shipping and tax breakdown for an offer, a destination or pickup point and an amount, without creating an order.

---

## 9. Money: Commission, Ledger, Earnings & Withdrawals

- **MON-1 Commission engine.** One commission engine lives in Core: the resolver plus the global rule, which can be flat, percent, or flat + percent. Pro modules add rule sources through `CommissionRuleSource` in a fixed priority order (category, membership, vendor, product, cart value, total sales). The winning rule, its version and the flat basis are saved on each allocation at Stage 1 and never recalculated when rules change.
- **MON-2 Flat basis.** The flat part of a commission applies once per vendor order by default. The filter `mercatus_commission_flat_basis( string $basis, int $vendor_id )` can switch it to `item_quantity` (per unit sold) or `order_line` (per product line); any other value falls back to `vendor_order`. The flat amount is spread across the vendor order's lines with the largest-remainder method (MON-11), so a partial refund reverses the matching share.
- **MON-3 Coupons.** Commission settings have two checkboxes, both on by default: "Commission after considering vendor coupon?" and "Commission after considering admin coupon?". On: that coupon type's discount is deducted first, and the commission and the vendor's earnings are calculated on the discounted price. Off: they are calculated on the price before that discount, so the marketplace absorbs it.
- **MON-4 Shipping.** A "Shipping cost goes to vendor?" checkbox, on by default. On: shipping charges on a vendor's items go to that vendor. Off: the marketplace keeps them.
- **MON-5 Tax recipient.** With store tax on (TAX-1), the tax on a vendor's items always goes to that vendor. With store tax off, a "Tax goes to vendor?" checkbox decides; it is on by default.
- **MON-6 Never commissioned.** Shipping and tax are never commissioned.
- **MON-7 Overridable settings.** MON-3 to MON-5 are global values resolved through the commission engine, so Pro vendor, membership or category rules can override them.
- **MON-8 Ledger.** One ledger of accounts, immutable entries and holds, in `BIGINT` minor units, holds all money: vendor payables, the marketplace account and, in Pro, customer wallets. Every account has a cached balance protected by an append-only journal and an optimistic `version` check. There is no generic balance-editing endpoint. A vendor payable balance may go negative, for example after a refund of already-withdrawn earnings.
- **MON-9 Earnings release.** Earnings become withdrawable on their own clock, separate from completion:
  - "Earnings become withdrawable N days after completion", default 7 (the common return window). Setting 0 releases them on completion.
  - An open refund or return request freezes that vendor order's earnings until it is resolved.
  - Refunds after release are taken back from the vendor's balance, which may go negative; withdrawals are blocked while it is negative.
  - Because vendors can complete their own orders (ORD-12), the hold and the admin's approval of each withdrawal (MON-10) are Free's safeguards. Pro automatic payouts rely on the hold or on delivery evidence (customer confirmation, OTP or carrier) instead (PRO-5).
- **MON-10 Manual withdrawals.** A vendor requests a withdrawal from their available balance, above an optional "Minimum withdrawal amount" (default none). The admin approves or rejects it, pays outside Mercatus (bank transfer, UPI and so on) and marks it paid.
- **MON-11 Rounding and currency.** Amounts round half-up per line. Remainders are split with the largest-remainder method (for example ₹100 split three ways is ₹33.34, ₹33.33 and ₹33.33). Currency decimals come from ISO 4217.

---

## 10. Pro Modules

- **PRO-1 Pro rules and build order.** Pro modules never alter Core tables; they key their own tables to Core IDs. Each major module starts with its decision file (P-4). Build order: the licensing and entitlement gate, then Shipment Tracking (PRO-2), then the finance modules (PRO-3, PRO-5, refunds, variable-weight settlement, tax automation), then the further modules in Section 15, Phase 6.
- **PRO-2 Shipment Tracking.** The first Pro module. Vendors (on their own vendor orders) and the admin (on any order) add and update shipping details: carrier, tracking number and tracking link. Customers see them in My Account and in emails. The first version includes bulk CSV upload of tracking details. Tracking data lives in the module's own table, keyed by the Core shipment record (ORD-9). With a tracking service connected, carrier-confirmed delivery marks the shipment delivered (ORD-12).
- **PRO-3 Payment integrations.** Integrations that move money to vendors, starting with Stripe and PayPal. Each provider offers two modes that the admin selects and configures: payout (the marketplace collects payment, then pays vendors through the provider) and split (the customer's payment is split to vendors at checkout). For each provider, both modes share one vendor-account connection, API client and webhook handling, with signature and replay checks; only the money flow differs. The ledger records both modes. Split mode uses the Stage 1 allocations (ORD-1), reverses the vendor's share on refunds and cancellations (ORD-11), and either handles Subscriptions renewal orders or refuses to run for them.
- **PRO-4 Customer wallet.** A Pro module on the Core ledger; Free has no wallet. It holds refund credit and promotional credit only; there is no top-up anywhere. Refunds go to the original payment method by default, and wallet credit is used only if the customer accepts it. Spending credit counts as payment applied after tax, so it changes neither tax nor vendor earnings; the marketplace funds promotional credit from its own ledger account. Partial wallet payment at checkout is validated by OPEN-2 when the module starts.
- **PRO-5 Finance automation.** Commission rule sources (MON-1); automatic and scheduled payouts that follow the earnings-release rule (MON-9); reverse withdrawals; reconciliation; hold days per vendor or membership through the filter `mercatus_earnings_hold_days( int $days, int $vendor_id )`; and a rolling reserve for new or flagged vendors.
- **PRO-6 Delivery.** Local delivery by agents, routes and time slots. Agents see only their assigned orders. An OTP entered at delivery completes the vendor order (ORD-12). Delivery updates the Core shipment records (ORD-9).
- **PRO-7 Variable-weight settlement.** Eligible offers are priced per unit (for example per kg) and ordered by target weight within a customer-approved lower and upper tolerance.
  - Checkout snapshots the unit price, target quantity, approved tolerance, estimated total and maximum authorised total.
  - Before dispatch, an authorised fulfilment user records one measured weight. Mercatus checks the tolerance, computes the final line total from the snapshotted rates, and settles by: (1) authorising the maximum and capturing the actual; (2) capturing the estimate and refunding the lower difference, or crediting the Pro wallet if the customer accepts; (3) capturing the estimate and charging a saved method, or recording a transparent `customer_settlement` receivable, for a higher actual; or (4) verified COD collection.
  - Vendor gross sales, taxes and commissions use the actual final line amount. Extra vendor earnings on an unpaid customer settlement are held until it is collected, unless policy explicitly accepts the credit risk.

---

## 11. Identity & Security

- **SEC-1 Actors and authentication.** Actors resolve into an immutable `ActorIdentity`, independent of WordPress roles: `anonymous`, `customer`, `vendor_owner`, `vendor_staff`, `delivery_agent`, `affiliate`, `marketplace_manager`, `administrator`, `integration_client` and `webhook_provider`. `delivery_agent` and `affiliate` exist only while their Pro modules are active.
  - Browser UIs: WordPress cookies plus `X-WP-Nonce`.
  - Server-to-server integrations and, in v1, mobile apps: WordPress Application Passwords mapped to `wp_mercatus_api_clients`, with scopes, expiry and revocation, and optional IP allow-lists. An OAuth2/PKCE server is not in v1 and would arrive as its own module.
  - Webhooks: raw-body HMAC signature verification, a timestamp window and stored event-ID replay defence.
  - Background workers and CLI: a system actor scoped to the executing worker.
- **SEC-2 Authorization pipeline.** Every request passes: authentication → actor identity resolution (cached per request, and across requests in `mercatus_actors` when a persistent cache is present) → API-client scope or named permission check → module entitlement check → resource policy check (`StorePolicy`, `AllocationPolicy`, `WithdrawalPolicy` and so on) → field-level serializer visibility → command state-machine validation. Controllers never use scattered `current_user_can()` checks. Suspending a vendor or removing staff bumps the actor's identity revision immediately, and the identity cache has a short expiry.
- **SEC-3 Input and SQL safety.** JSON Schema validation, loaded only for the matched route, checks shape and types; Application Commands check meaning. Command DTOs accept only explicit, actor-permitted fields; fields such as `vendor_id`, `approved_by`, `commission_amount`, `tax_amount` or `balance_minor` are never taken from request bodies. Dynamic SQL values always go through `$wpdb->prepare()`, and `ORDER BY` and column identifiers come from static allow-lists.
- **SEC-4 Enumeration.** Another tenant's private resource (vendor orders, shipments, ledger accounts, withdrawals, payouts, unpublished offers) returns 404. A permission denied inside the actor's own tenant returns 403.
- **SEC-5 Idempotency and concurrency** follow PLAT-7 and PLAT-9.
- **SEC-6 Secrets.** Provider API keys and tax registration numbers are encrypted with `sodium_crypto_secretbox`, using a dedicated `MERCATUS_ENCRYPTION_KEY` constant with key versions, never a key derived from WordPress salts. KYC documents and delivery proofs live outside the web root and are served only through short-lived signed URLs.
- **SEC-7 Vendor content, uploads and privacy.** Vendor rich text is cleaned on save with a fixed allow-list and escaped on output. Uploads follow a MIME and SVG policy. Outbound requests (webhooks, geocoding, AI, payment providers) use an allow-list. Dashboards send a Content-Security-Policy. GDPR export and erase are supported, and append-only tables hold no free-text personal data.
- **SEC-8 WooCommerce capabilities.** Vendors never receive WooCommerce capabilities such as `edit_products`, so they can't bypass Mercatus policies through wp-admin or the WooCommerce REST API.

---

## 12. REST API & Outbox

- **API-1 Conventions.**
  - Namespace `/wp-json/mercatus/v1`.
  - State changes use explicit action subresources (`POST /withdrawals/{id}/approve`), never generic status patching.
  - Errors return an HTTP status and `{ "code": "mercatus_...", "message": "...", "details": {...}, "request_id": "req_..." }`; stack traces are never exposed.
  - High-volume collections (products, offers, orders, media, ledger entries) use cursor pagination: `?cursor=...&per_page=24`, returning `{ "items": [...], "per_page": 24, "next_cursor": "...", "has_more": true }`. `OFFSET` pagination and per-item queries are not allowed.
  - OpenAPI 3.1 is generated from the PHP API definitions (DRY-1).
- **API-2 Core endpoints.**

| Area | Endpoints |
|---|---|
| **Bootstrap & system** | `GET /me` (actor, store summary, active features), `GET /features`, `GET /health`, `POST /system/cache/flush-group` (PLAT-5), `GET /modules`, `POST /modules/{id}/activate`, `POST /modules/{id}/deactivate` (optional modules only, ARCH-6) |
| **Vendor applications** | `POST /vendor-applications`, `GET /vendor-applications/{id}`, `POST /vendor-applications/{id}/approve`, `POST /vendor-applications/{id}/reject` |
| **Stores** | `GET /stores`, `GET /stores/{slug}`, `GET /me/store`, `PATCH /me/store`, `POST /stores/{id}/suspend` |
| **Catalog** | `GET /catalog/categories`, `GET /catalog/categories/{id}/schema`, `GET /catalog/attributes/{id}/terms`, `GET /catalog/offers`, `GET /catalog/products/{id}/offers` |
| **Vendor products & offers** | `GET /me/products`, `POST /me/products`, `GET /me/products/{id}`, `PATCH /me/products/{id}`, `DELETE /me/products/{id}`, `PATCH /me/offers/{id}/inventory` |
| **Media** | `POST /me/media`, `GET /me/media`, `DELETE /me/media/{id}` |
| **Shipping & tax** | `GET`/`PATCH /me/shipping-profile`; `GET`/`POST`/`PATCH`/`DELETE /me/shipping-zones`; `GET`/`PATCH /me/tax-profile`; `GET`/`POST`/`PATCH`/`DELETE /me/tax-rates`; `POST /vendors/{id}/tax-rates/approve` (admin); `POST /me/shipping-tax/test-quote` |
| **Orders & fulfilment** | `GET /me/orders`, `GET /me/orders/{id}`, `POST /me/orders/{id}/fulfilment-status`, `GET`/`POST`/`PATCH /me/orders/{id}/shipments`, `POST /orders/{parent_id}/vendor-orders/{id}/confirm-receipt` (customer), `GET /orders/{parent_id}/allocations`, `POST /orders/{parent_id}/complete`, `POST /orders/{parent_id}/cancel` (admin) |
| **Earnings & withdrawals** | `GET /me/earnings`, `GET /me/ledger/entries`, `GET /me/withdrawals`, `POST /me/withdrawals`, `POST /withdrawals/{id}/approve`, `POST /withdrawals/{id}/reject`, `POST /withdrawals/{id}/mark-paid` (admin) |
| **Reviews** | `GET /stores/{slug}/reviews`, `POST /stores/{slug}/reviews`, `POST /reviews/{id}/reply`, `POST /reviews/{id}/moderate` |

  There are no Mercatus cart or checkout endpoints (ARCH-12). Commission, completion, earnings-release, rate-limit and tax settings are exposed through the typed settings registry. Pro modules register their own routes through the registrar.
- **API-3 Outbox.** Commands write domain state, `wp_mercatus_audit_log` and `wp_mercatus_events_outbox` in one transaction over Mercatus tables, then bump cache revisions after commit. Money changes never run in outbox handlers (ARCH-11). Critical handlers run right after commit, with the poller as the retry net, and every committed event also reaches WordPress through the single `do_action()` bridge (ARCH-8).
  - **No one-row-per-event scheduling:** a recurring worker (Action Scheduler or the `wp mercatus outbox:work` daemon) claims events in batches of 200 with a lease, which works on every supported database and recovers crashed workers:
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
  - **Lanes:** `critical` handles child order creation, payment and payout webhooks, payout transitions and notifications customers wait for. `bulk` handles projection and facet rebuilds, search indexing, analytics rollups, other notifications and AI tasks.
  - **Coalescing:** on the `bulk` lane, pending events sharing a `coalesce_key` (for example `offer_proj:1045`) within a batch run once.

---

## 13. Database Schema

- **DATA-1 Global rules.**
  - Migrations are the schema's source of truth; the tables below are the design they implement.
  - Engine `InnoDB`; charset and collation from `$wpdb->get_charset_collate()`.
  - Identifiers `BIGINT UNSIGNED NOT NULL`; timestamps `DATETIME(3) NOT NULL` in UTC.
  - Monetary amounts (balances, holds, ledger, order, allocation and tax totals): `BIGINT NOT NULL` minor units with `currency CHAR(3) NOT NULL`.
  - Unit prices, percentages, tax rates and quantities: `DECIMAL(20,6) NOT NULL`, handled as exact decimal strings.
  - Mutable tables carry `version BIGINT UNSIGNED NOT NULL DEFAULT 1`.
  - Foreign keys are enforced in repositories with explicit indexes; never as SQL `FOREIGN KEY` constraints on WordPress or WooCommerce tables.
  - Append-only tables hold no free-text personal data (SEC-7).
- **DATA-2 Core tables (`wp_mercatus_*`).**

| Table | Responsibility | Keys & required indexes |
|---|---|---|
| `schema_versions` | Applied Core and Pro migrations, checksums, execution locks | PK `id`; UNIQUE `(module_id, version)` |
| `vendors` | Vendor identity and lifecycle; one WP user per vendor | PK `id`; UNIQUE `user_id`; INDEX `(status, created_at)` |
| `stores` | Store profile, normalized slug, visibility, owner vendor | PK `id`; UNIQUE `vendor_id`; UNIQUE `slug`; INDEX `(visibility, status)` |
| `catalog_products` | Catalog product, product type (`simple`, `variable`, `booking`, `subscription`, `auction`), governance state | PK `id`; UNIQUE `woo_product_id`; INDEX `(scope, status, product_type)`; INDEX `(primary_category_id, status)` |
| `categories` | Category index: materialized path, depth, leaf flag (CAT-3) | PK `id`; UNIQUE `category_id`; INDEX `(parent_id, sort_order)`; INDEX `(path(64))` |
| `attributes` | Tier 2 attributes (CAT-2) | PK `id`; UNIQUE `slug`; INDEX `(type, is_filterable)` |
| `attribute_values` | Tier 2 attribute values, referenced by facets | PK `id`; UNIQUE `(attribute_id, slug)`; INDEX `(attribute_id, sort_order)` |
| `category_attribute_maps` | Allowed and required attributes per category, with `category_path` | PK `id`; UNIQUE `(category_id, attribute_id)`; INDEX `(category_id, sort_order)`; INDEX `(category_path(64))` |
| `catalog_facets` | Denormalized attribute and category filter index | PK `id`; UNIQUE `(catalog_product_id, attribute_id, value_id, location_id)`; INDEX `idx_facet_filter (attribute_id, value_id, is_purchasable, catalog_product_id)`; INDEX `idx_num_filter (attribute_id, numeric_value, is_purchasable, catalog_product_id)` |
| `vendor_offers` | A vendor's offer for a catalog product (`base_price` `DECIMAL(20,6)`); source of truth for price | PK `id`; UNIQUE `(catalog_product_id, vendor_id)`; INDEX `(vendor_id, status)` |
| `fulfilment_locations` | Default, branch, pickup, delivery, warehouse or 3PL location, with `geo_cell` | PK `id`; INDEX `(vendor_id, status, is_default)`; INDEX `(geo_cell, status)` |
| `offer_locations` | Offer availability and local overrides at a location | PK `id`; UNIQUE `(vendor_offer_id, location_id)`; INDEX `(location_id, status)` |
| `offer_availability` | Storefront projection: offers, effective prices, stock, card JSON, `geo_cell` | PK `id`; UNIQUE `(vendor_offer_id, variation_id, location_id)`; INDEX `idx_catalog_avail (catalog_product_id, is_purchasable, effective_price)`; INDEX `idx_geo_avail (geo_cell, is_purchasable, catalog_product_id)`; INDEX `idx_vendor_avail (vendor_id, is_purchasable, updated_at)` |
| `inventory_balances` | Sellable stock by offer, variation and location; source of truth for stock | PK `id`; UNIQUE `(vendor_offer_id, variation_id, location_id)` |
| `inventory_movements` | Append-only stock journal | PK `id`; UNIQUE `movement_uuid`; INDEX `(balance_id, created_at)` |
| `inventory_reservations` | Checkout stock reservations with expiry | PK `id`; UNIQUE `reservation_uuid`; INDEX `(balance_id, status)`; INDEX `(status, expires_at)` |
| `media_ownership` | Vendor media ownership and quotas | PK `attachment_id`; INDEX `(vendor_id, created_at DESC)` |
| `order_groups` | Parent order grouping and split state | PK `id`; UNIQUE `parent_woo_order_id`; INDEX `(status, created_at)` |
| `vendor_orders` | One record per vendor per paid parent; fulfilment and completion state | PK `id`; UNIQUE `(parent_woo_order_id, vendor_id)`; UNIQUE `child_woo_order_id` (nullable before payment); INDEX `(vendor_id, status, created_at DESC)` |
| `order_allocations` | Immutable allocation per vendor and order line, with the commission snapshot (rule, version, flat basis, amount) | PK `id`; UNIQUE `(parent_woo_order_id, order_item_id, vendor_id)`; INDEX `(vendor_order_id)`; INDEX `(vendor_id, created_at)` |
| `allocation_adjustments` | Append-only adjustments: refunds, cancellations, item changes after payment (ORD-4) | PK `id`; UNIQUE `adjustment_uuid`; INDEX `(allocation_id)`; INDEX `(vendor_order_id, created_at)` |
| `order_fulfilments` | Shipment records: one package from one vendor order (ORD-9) | PK `id`; INDEX `(vendor_order_id, location_id, status)` |
| `order_fulfilment_items` | Items and quantities in each shipment | PK `id`; UNIQUE `(fulfilment_id, allocation_id)` |
| `vendor_shipping_profiles` | Vendor ship-from settings and approval version | PK `id`; UNIQUE `vendor_id`; INDEX `(status)` |
| `shipping_zones` / `shipping_methods` | Vendor zones and approved methods and rates | PK `id`; INDEX `(vendor_id, status, priority)` |
| `shipping_zone_locations` | Countries, states and postcode patterns per zone | PK `id`; INDEX `(zone_id)`; INDEX `(country_code, state_code, postcode_pattern)` |
| `seller_tax_profiles` / `tax_registrations` | Vendor tax profile (rate approval status, ship-from for tax) and encrypted registration numbers | PK `id`; UNIQUE `(seller_type, seller_id, country_code, state_code)` |
| `tax_snapshots` | Tax components per allocation, copied from the saved order's line taxes | PK `id`; UNIQUE `(allocation_id, taxable_component, calculation_version)`; INDEX `(vendor_order_id)` |
| `commission_rules` | Commission rules; Core owns the global rule, Pro sources add theirs | PK `id`; INDEX `(source, scope_type, scope_id, status)` |
| `ledger_accounts` | Account per owner, currency and type, with cached `balance_minor` | PK `id`; UNIQUE `(owner_type, owner_id, currency, account_type)` |
| `ledger_entries` | Immutable debit/credit journal (`amount_minor`, `running_balance_minor`) | PK `id`; UNIQUE `entry_uuid`; UNIQUE `(account_id, idempotency_key)`; INDEX `(account_id, posted_at DESC)` |
| `ledger_holds` | Pending reservations against an account, including earnings frozen by open refund requests | PK `id`; UNIQUE `hold_uuid`; INDEX `(account_id, status, expires_at)` |
| `withdrawals` | Manual withdrawals: requested → approved → paid, or rejected | PK `id`; UNIQUE `withdrawal_uuid`; INDEX `(vendor_id, status, created_at DESC)` |
| `reviews` / `review_replies` | Public store reviews and moderated replies | PK `id`; UNIQUE `(store_id, customer_id, vendor_order_id)`; INDEX `(store_id, status, created_at DESC)` |
| `api_clients` | Scoped Application Password clients and IP allow-lists | PK `id`; UNIQUE `client_key_hash`; INDEX `(actor_type, actor_id, status)` |
| `events_outbox` | Transactional events (`lane`, `coalesce_key`, `claimed_by`, `lease_until`) | PK `id`; UNIQUE `event_uuid`; INDEX `idx_outbox_poll (lane, status, available_at, id)`; INDEX `(status, lease_until)`; INDEX `(coalesce_key, status)` |
| `audit_log` | Append-only security and business audit trail | PK `id`; INDEX `(actor_id, created_at DESC)`; INDEX `(resource_type, resource_id, created_at DESC)` |
| `idempotency` | Replay protection: written with status `processing` and a request hash before the command runs, then completed with the stored response (PLAT-7) | PK `id`; UNIQUE `(actor_fingerprint, route, idempotency_key)`; INDEX `(expires_at)` |

  Vendor tax rates have no Mercatus table: they are rows in WooCommerce's `woocommerce_tax_rates` (TAX-12).
- **DATA-3 Pro tables (`wp_mercatus_pro_*`, created when the owning module is activated).**

| Pro module | Tables | Key invariant |
|---|---|---|
| **Shipment Tracking** | `carriers`, `shipment_tracking` | Keyed by Core shipment ID; vendors edit only their own vendor orders (PRO-2). |
| **Commissions** | Rule-source tables (category, membership, vendor, product, cart value, total sales) | Every applied rule and version is snapshotted on the allocation and never recalculated (MON-1). |
| **Payouts** | `payouts`, `payout_items`, `payment_attempts`, `reconciliation_records` | Uses the Core ledger; no second ledger and no editable balances; four-eyes approval support. |
| **Payment Integrations** | `vendor_payment_accounts`, `provider_transfers`, `webhook_events` | Signature and replay checks; split transfers reversed on refunds and cancellations (PRO-3). |
| **Customer Wallet** | `wallet_credit_grants` (promotional credit with expiry) | Customer accounts live in the Core ledger; no top-up; refund credit only with consent (PRO-4). |
| **Refunds** | `refund_cases`, `refund_lines`, `refund_decisions` | References Core allocations and WooCommerce refund IDs; full state history. |
| **Tax Automation** | `tax_provider_requests`, `tax_documents`, `tax_reconciliation_runs` | Never mutates Core `tax_snapshots`. |
| **Variable-Weight Settlement** | `line_measurements`, `line_quantity_adjustments`, `settlement_authorisations`, `customer_settlements` | Immutable target, tolerance and actual measurement; customer approval; receivable tracking (PRO-7). |
| **Memberships** | `plans`, `subscriptions`, `entitlements`, `billing_events` | Entitlements compile into the cached capability and actor manifests. |
| **Multi-Location Fulfilment** | `location_memberships`, `location_delivery_zones`, `location_shipping_profiles`, `inventory_transfers`, `routing_rules` | Extends the default location to many, with geohash routing and inter-location transfers. |
| **Staff & Groups** | `groups`, `group_permissions`, `staff_assignments` | Vendor- and location-scoped permissions; never grants global WordPress roles. |
| **Delivery** | `agents`, `agent_assignments`, `delivery_jobs`, `delivery_events`, `delivery_otps` | Agents see only assigned orders; OTP confirmation completes the vendor order (PRO-6). |
| **Analytics** | `analytics_daily_vendor_rollups`, `analytics_product_rollups`, `analytics_exports` | Filled through the `bulk` lane; never touched during checkout. |
| **Affiliate** | `affiliates`, `referrals`, `attributions`, `affiliate_commissions` | Attribution is immutable once the attribution window locks it. |
| **AI Suite** | `ai_requests`, `ai_usage_quotas`, `ai_approvals` | PII and secrets redacted from prompts; human approval before publishing catalog or moderation actions. |

---

## 14. Testing

- **TEST-1 The 7-layer pyramid.**
  - **Layer 1, architecture & static analysis (`tests/Architecture/`):** PHPStan, PHPCS and Deptrac (ARCH-5) on every commit. Enforced: `Mercatus\Core\{Module}\Domain` and `Application` have no dependency on `WP_REST_Request`, `WC_Product`, `WC_Order`, `$wpdb` or WordPress translation functions; Core never references Pro; Pro imports only `Mercatus\Core\Contracts\*` and `Mercatus\Core\Shared\*`; controllers never import `$wpdb` or repositories; no direct Redis or Memcached client classes; every Command handler and Policy has a matching `*Test.php`.
  - **Layer 2, unit (`tests/Unit/`):** pure PHP 8.2+, no database, no WordPress, under 5 ms per test. Covers money arithmetic and largest-remainder allocation (MON-11); the commission engine with flat basis and coupon, shipping and tax settings (MON-1 to MON-7); state machines for vendor orders, fulfilment, completion triggers, withdrawals and holds; earnings release (MON-9); rate-resolver selection with store tax on and off, approval and fallback (TAX-1 to TAX-5); variable-weight math; policy decisions for all 10 actor types; cache-key and pointer seeding (PLAT-5); ETag generation (PLAT-9); geohash neighbour cells.
  - **Layer 3, contract (`tests/Contract/`):** the generated OpenAPI matches the committed file and every route's requests and responses validate against it; every adapter passes its conformance trait (`CachePortConformanceTrait`, `CoordinationPortConformanceTrait`, `TaxRateResolverConformanceTrait`, `PayoutProviderConformanceTrait`, `CatalogSearchPortConformanceTrait`); every event payload validates against its versioned JSON Schema.
  - **Layer 4, integration (`tests/Integration/`):** MySQL 8.0 and 8.4, MariaDB 10.6, 10.11 and 11.4, WordPress 6.6+, WooCommerce 9.2+ with HPOS; each run with Redis, with Memcached and with no persistent cache. Covers migrations (up, idempotent re-run, checksums, Pro lazy migration); repositories with `EXPLAIN` assertions (no `type = ALL`, no `Using filesort` on hot queries); `CachePort` and `CoordinationPort` with and without a persistent cache; idempotency under concurrent duplicates; ETags stable across requests without a persistent cache; the two-stage split on HPOS (no duplicate stock deductions or emails, children hidden per ORD-7); WooCommerce tax integration (per-item rates, snapshots from saved order taxes, the TAX-8 safety net, displayed prices matching the cart); outbox lease claiming with two parallel workers.
  - **Layer 5, security (`tests/Security/`):** see TEST-2.
  - **Layer 6, scale (`tests/Scale/`):** on the PLAT-10 schedule, restored from a saved dump of `wp mercatus seed:scale` data, with and without a persistent cache. Fails the build when a query ceiling is exceeded; records and trends latency.
  - **Layer 7, end-to-end & JS (`tests/EndToEnd/`, `tests/Js/`):** full API acceptance journeys, browser journeys for checkout, vendor order handling, completion and withdrawals, and JS unit tests for first-party UIs. JS unit tests use Vitest, the runner `@wordpress/scripts` ships by default, and browser journeys use Playwright, both through `@wordpress/scripts` (ARCH-13).
- **TEST-2 Generated security matrix.** The actor × resource × endpoint matrix is generated from the route registry, so every new route is covered automatically. It covers: IDOR/BOLA (vendor A against vendor B's store, products, offers, media, child orders, shipments, shipping zones, tax profile and rates, earnings and withdrawals → 404, SEC-4); privilege escalation and staff scope; mass-assignment fuzzing; missing nonce, idempotent replay and stale `If-Match` (→ 409); PII and secret redaction; rich-text and upload policy (SEC-7); vendors unable to edit products through the WooCommerce REST API (SEC-8).
- **TEST-3 Module test matrix.** Every module ships with these test classes before its ticket can close, and tickets use the same class names. Three cross-cutting tests belong to a layer instead of a module: `DependencyRulesTest` (Layer 1), `OpenApiSchemaConformanceTest` (Layer 3) and `BaselineQueryAndLatencyGateTest` (Layer 6).

| Module | Unit | Integration & contract | Security & scale |
|---|---|---|---|
| **1. Kernel, settings & migrations** | `ModuleManifestValidatorTest`, `VersionCompatibilityTest`, `FeatureManifestCompilerTest`, `SettingsRegistryTest` | `MigrationRunnerTest`, `FeaturesEndpointContractTest` | `HealthEndpointAuthTest`, `ZeroQueryKernelBootTest`, `InactiveModuleZeroFootprintTest` |
| **2. Caching & coordination** | `VersionedCacheKeyResolverTest`, `SoftExpiryStampedeCalculatorTest`, `EtagFromVersionTest` | `CachePortRedisTest`, `CachePortMemcachedTest`, `CachePortNoPersistentCacheTest`, `CoordinationPortNoPersistentCacheTest`, `BatchMultiGetIntegrationTest`, `SingleflightLockConcurrencyTest` | `CacheFlushAuthorizationTest`, `WarmCacheZeroSqlBenchmarkTest` |
| **3. Outbox & worker** | `EventPayloadSerializerTest`, `BulkEventCoalescerTest`, `RetryBackoffPolicyTest` | `OutboxTransactionalWriteTest`, `LeaseClaimParallelWorkerTest`, `CriticalVsBulkLanePriorityTest`, `DomainEventActionBridgeTest` | `CronContextSpoofingTest`, `OutboxBatchThroughputBenchmarkTest` (1,000 events < 2 s) |
| **4. Identity & policy** | `ActorIdentityResolverTest`, `PolicyEngineMatrixTest`, `DashboardMenuCompilerTest` | `CachedActorIdentityInvalidationTest`, `ApiClientScopeIntegrationTest`, `IdempotencyStoreIntegrationTest` | `IdorActorMatrixTest`, `CsrfNonceEnforcementTest`, `RateLimiterTest` |
| **5. Vendor & storefront** | `VendorLifecycleStateMachineTest`, `StoreSlugNormalizerTest`, `StorePolicyTest` | `VendorApplicationWorkflowTest`, `DefaultLocationAutoCreationTest`, `StoreRepositoryTest` | `StoreCrossTenantIsolationTest`, `StorePiiFieldRedactionTest`, `StorefrontEdgeCacheHeaderTest` |
| **6. Catalog & projections** | `TwoTierAttributePolicyTest`, `MaterializedCategoryPathTest`, `EffectivePriceResolverTest`, `CatalogPolicyTest` | `WooProductCrudAdapterTest`, `OfferAvailabilityProjectionSyncTest`, `ProjectionDriftReconcilerTest`, `CatalogFacetIndexQueryTest`, `MediaOwnershipQuotaTest` | `ProductMassAssignmentOwnershipTest`, `MediaCrossVendorDeleteTest`, `HundredThousandProductFacetBenchmarkTest` |
| **7. Shipping & tax** | `TaxRateResolverSelectionTest`, `StoreTaxPolicyTest`, `ShippingZoneMatcherTest`, `ProRataDiscountTaxRoundingTest` | `WooCartItemTaxRatesTest`, `TaxSnapshotFromOrderLinesTest`, `OrderRecalculationTaxSafetyNetTest`, `VendorShippingTaxTestQuoteEndpointTest` | `UnapprovedTaxRatesFallbackTest`, `VendorGlobalWooSettingsIsolationTest`, `DisplayedPriceMatchesCartTaxTest` |
| **8. Orders, status & completion** | `DeterministicOrderAllocationMathTest`, `ParentStatusAggregatorTest`, `AllocationPolicyTest`, `CompletionTriggerTest` | `StageOnePrePaymentSnapshotTest`, `StageTwoChildOrderMaterializationTest`, `IdempotentOrderSplitRetryTest`, `StockSingleDeductionTest`, `ChildOrderExposureTest`, `AdminParentOverrideCascadeTest` | `VendorParentOrderAccessDeniedTest`, `CrossVendorChildOrderAccessDeniedTest`, `MultiVendorCheckoutLatencyBenchmarkTest` |
| **9. Commission, ledger & withdrawals** | `CommissionEngineTest`, `MinorUnitLedgerMathTest`, `EarningsReleaseRuleTest`, `WithdrawalStateMachineTest` | `LedgerJournalOptimisticLockTest`, `CommissionSnapshotImmutabilityTest`, `RefundClawbackNegativeBalanceTest` | `WithdrawalIdorTest`, `ConcurrentWithdrawalDoubleSpendTest`, `LedgerBalancePatchRouteAbsenceTest` |
| **10. Reviews** | `ReviewPolicyTest`, `ReviewModerationStateMachineTest` | `VerifiedReviewEligibilityTest`, `ReviewRepositoryTest` | `CrossStoreReviewModerationBlockTest`, `ReviewMassAssignmentTest` |
| **11. Pro: Shipment Tracking** | `TrackingUrlTemplateTest`, `ShipmentTrackingPolicyTest` | `CsvTrackingImportTest`, `InactiveModuleZeroFootprintTest` | `CrossVendorTrackingUpdateBlockTest` |
| **12. Pro: Payouts & Payment Integrations** | `PayoutScheduleTest`, `SplitShareCalculatorTest`, `FourEyesApprovalPolicyTest` | `PayoutProviderConformanceTest`, `WebhookSignatureReplayTest`, `SplitRefundReversalTest`, `RenewalOrderSplitTest` | `UnentitledProFinanceRouteBlockTest`, `SelfApprovalPreventionTest`, `DuplicatePayoutWebhookReplayTest` |
| **13. Pro: Variable-Weight Settlement** | `WeightToleranceValidatorTest`, `PostWeighFinalPriceMathTest`, `CustomerSettlementReceivableTest` | `InactiveModuleZeroFootprintTest`, `MaxAuthCaptureActualFlowTest`, `LowerActualRefundFlowTest`, `HigherActualReceivableHoldPayoutTest` | `OutOfToleranceUnapprovedChargeBlockTest`, `PostLockMeasurementTamperTest` |
| **14. Pro: Multi-Location, Staff & Advanced Catalog** | `GeohashNeighbourCellsTest`, `LocationRoutingPriorityTest`, `StaffLocationScopePolicyTest` | `MultiLocationOfferAvailabilityTest`, `InventoryTransferTransactionTest`, `ExternalSearchPortConformanceTest` | `StaffCrossLocationMutationBlockTest`, `GeoSearchHundredThousandOfferBenchmarkTest` |
| **15. Pro: Customer Wallet** | `MinorUnitWalletMathTest`, `RefundToWalletConsentTest` | `WalletPartialPaymentAfterTaxTest`, `InactiveModuleZeroFootprintTest` | `WalletTopUpRouteAbsenceTest`, `ConcurrentWalletSpendRaceConditionTest` |

- **TEST-4 Test environment.** One Docker Compose file has a profile for each supported database (MySQL 8.0 and 8.4, MariaDB 10.6, 10.11 and 11.4) and each cache mode (Redis, Memcached, none), so any CI combination can be reproduced locally. GitHub Actions runs the same images as a matrix; which combinations run on every pull request and which run nightly or on `main` is set in the Groundwork decision file. The scale suite runs on the PLAT-10 schedule. MAMP remains fine for manual checks.

---

## 15. Roadmap & Tickets

- **ROAD-1 Delivery rules.** Development proceeds one module at a time, in the order of ROAD-3. Phases group modules into vertical slices: each phase delivers working features end to end, infrastructure arrives when the first feature needs it, and the scale seeder grows module by module. No phase advances until all its implementation and test gates pass in CI. A module larger than about three weeks of work is split into increments that are each tested on their own. The shared kernel (DRY-2) and notifications are not separate modules: each piece is added to `Shared` or to the notifications layer by the first module that needs it.
- **ROAD-2 Module playbook.** Every module follows the same steps on its own branch (ROAD-4):
  1. **Decision file:** `decisions/<module-id>.md` (P-4) covering scope, data, settings, endpoints, events and open questions, approved before any code.
  2. **Schema:** migrations and repositories, with integration tests that include `EXPLAIN` checks.
  3. **Domain:** entities, value objects and state machines, with unit tests.
  4. **Application:** commands, queries and policies, with unit tests.
  5. **API:** endpoints on the base REST controller, regenerated OpenAPI, contract tests and the generated security matrix (TEST-2).
  6. **WordPress and WooCommerce:** adapters and hooks.
  7. **UI:** the module's screens (ARCH-13), with Vitest and Playwright tests.
  8. **Scale:** the module's seeder slice and query ceilings, with and without a persistent cache (PLAT-10).
  9. **Done:** the module's TEST-3 row passes in CI, no guardrail in Section 2 is violated, and its decision file is marked implemented.
- **ROAD-3 Module order.**

| # | Module | Delivers | Needs | Phase |
|---|---|---|---|---|
| 0 | Groundwork | Both repositories (ROAD-4), scaffold, test environment and CI (TEST-4), frozen contracts, OpenAPI generation, threat model, decision-file template | — | 0 |
| 0′ | OPEN-1 spike | Vendor tax rate storage, in a throwaway plugin; can run alongside other work | — | 0 |
| 1 | `core.kernel` | Boot, module registry and manifests, settings registry, migration runner, `/features`, `CachePort`, `CoordinationPort`, outbox and event bridge, audit log | 0 | 1 |
| 2 | `core.identity` | Actor identity, policy engine, `/me`, base REST controller (errors, cursors, ETags, `If-Match`), idempotency, rate limiter, API clients | 1 | 1 |
| 3 | `core.vendor` | Vendor applications and lifecycle, the first emails, the marketplace dashboard shell with role-based menus (ARCH-13) | 2 | 2 |
| 4 | `core.store` | Storefront profile and slug, default fulfilment location with geohash, `/stores` APIs, store page, vendor store settings | 3 | 2 |
| 5a | `core.catalog`: products | Products, offers and inventory through WooCommerce, media ownership, availability projection, drift reconciliation | 4 | 3 |
| 5b | `core.catalog`: discovery | Tier 2 attributes, category index, facets, catalog block | 5a | 3 |
| 6 | `core.shipping` | Vendor zones and methods, a WooCommerce shipping method with one package per vendor, the shipping part of the test quote | 4, 5a | 4 |
| 7 | `core.commission` | Commission engine, global rule, coupon, shipping and tax settings (MON-1 to MON-7) | 1 | 4 |
| 8 | `core.ledger` | Ledger accounts, entries and holds, money arithmetic (MON-8, MON-11) | 1 | 4 |
| 9 | `core.tax`: step 1 | `StoreTaxPolicy`, the WooCommerce-rates resolver and tax snapshots: the store-tax-off path | 6 | 4 |
| 10 | `core.orders` | Three increments: (a) order split and child orders; (b) fulfilment status and shipment records; (c) completion triggers and admin overrides. Completion credits vendor earnings to the ledger | 5a, 6–9 | 4 |
| 11 | `core.tax`: step 2 | Store tax on: vendor rates (OPEN-1 outcome), approval and fallback, tax-inclusive prices, the recalculation safety net, the tax part of the test quote | 0′, 10 | 4 |
| 12 | `core.withdrawals` | Earnings release, manual withdrawals and the vendor earnings screens (MON-9, MON-10) | 8, 10 | 4 |
| 13 | `core.reviews` | Store reviews verified against vendor orders | 10 | 4 |

  Commission and the ledger come before Orders because Stage 1 saves the commission and completion credits the ledger. Tax is split because the store-tax-off path is all Stage 1 needs, while the store-tax-on path depends on OPEN-1 and is best verified against real child orders. Finishing module 13 completes the **Free MVP**.

  After the Free MVP, Phase 5 builds `pro.licensing`, then `pro.shipment-tracking` (PRO-2), then the finance modules: `pro.commissions`, `pro.payouts` with `pro.payment-integrations` (PRO-3, PRO-5), refunds, variable-weight settlement (PRO-7) and tax automation. Phase 6 builds, one at a time: Staff & Groups → Multi-Location Fulfilment → Memberships → Delivery with OTP confirmation (PRO-6) → Analytics → Affiliate → Advanced Catalog (SPMV, bulk importer, external search) → AI Suite. The Customer Wallet's place in Phase 6 is OPEN-4.

  **Phase exit gates:**
  - **Phase 0:** contracts and generated OpenAPI pass lint and schema validation, and OPEN-1 is closed, with its outcome recorded in TAX-12 and DATA-2.
  - **Phase 1:** TEST-3 rows 1–4 pass with and without a persistent cache.
  - **Phase 2:** TEST-3 row 5 passes, including IDOR and warm-cache zero-SQL tests.
  - **Phase 3:** TEST-3 row 6 passes, including the 100,000-product facet ceilings with no `wp_postmeta` or `wp_term_relationships` joins.
  - **Phase 4:** TEST-3 rows 7–10 pass, including multi-vendor checkout reconciliation, withdrawal double-spend and cross-vendor order isolation. **First shippable Free MVP.**
  - **Phase 5:** TEST-3 rows 11–13 pass, including commission snapshot immutability, ledger reconciliation and webhook replay protection.
  - **Phase 6, per module:** decision file, contract, migration, inactive zero-footprint, tenant security matrix and scale tests pass before the next module starts.
- **ROAD-4 Repositories and branching.** Two private repositories: `github.com/sushobhan-pal/mercatus` and `github.com/sushobhan-pal/mercatus-pro`. The free plugin's repository may go public at launch; the Pro repository stays private. `main` is protected. Each module or increment from ROAD-3 gets its own branch and is merged by pull request, with a squash merge, only when CI is green.
- **ROAD-5 First tickets.** These cover Groundwork and Phase 1; later modules get their tickets from their decision files.
  1. **Repositories & scaffold:** both repositories set up per ROAD-4, Composer with Strauss or PHP-Scoper, PHPStan, PHPCS, Deptrac layer rules and `@wordpress/scripts`; `tests/Architecture/DependencyRulesTest.php`.
  2. **Test environment:** Docker Compose profiles and the GitHub Actions matrix (TEST-4).
  3. **Contracts, ADR & generated OpenAPI:** `docs/architecture/decisions/0001-api-first-modular-monolith.md`, `Mercatus\Core\Contracts\*` with conformance traits, OpenAPI generation with `tests/Contract/OpenApiSchemaConformanceTest.php`, and `tests/Security/PolicyMatrixDataProvider.php` generated from the route registry.
  4. **Decision-file template and threat model.**
  5. **Vendor tax rate storage spike:** run OPEN-1 and record the outcome in TAX-12 and DATA-2.
  6. **`core.kernel`: kernel, settings registry & module manifest** (after its decision file): `ModuleManifestValidatorTest`, `SettingsRegistryTest`, `InactiveModuleZeroFootprintTest`.
  7. **`core.kernel`: `CachePort` and `CoordinationPort`:** `CachePortRedisTest`, `CachePortMemcachedTest`, `CachePortNoPersistentCacheTest`, `CoordinationPortNoPersistentCacheTest`, `SingleflightLockConcurrencyTest`.
  8. **`core.kernel`: migration runner, outbox & audit log:** `MigrationRunnerTest`, `LeaseClaimParallelWorkerTest`, `DomainEventActionBridgeTest`.
  9. **Scale seeder skeleton & dump pipeline:** `wp mercatus seed:scale` with per-module seeders; `tests/Scale/BaselineQueryAndLatencyGateTest.php`.
  10. **`core.identity`: actor identity, policy engine & `/me`** (after its decision file): `PolicyEngineMatrixTest`, `CachedActorIdentityInvalidationTest`.
  11. **`core.identity`: base REST controller, API clients, rate limiting & idempotency:** error envelope, cursor pagination, ETags and `If-Match`, Cache-Control, Application Password scopes; `EtagFromVersionTest`, `IdempotencyStoreIntegrationTest`, `RateLimiterTest`, `ApiClientScopeIntegrationTest`.

---

## 16. Open Items

- **OPEN-1 Vendor tax rate storage.** Closed in v2.5 by the Phase 0 spike: vendor rates live in WooCommerce's `woocommerce_tax_rates` under unregistered per-vendor classes (TAX-12, DATA-2). Both options passed every check; this one needed one workaround (Mercatus writes its own rows) where a Mercatus table needed four WooCommerce hooks and its own location matching. The evidence is in `docs/spikes/open-1-vendor-tax-rate-storage.md`.
- **OPEN-2 Partial wallet payment (start of the Pro wallet module).** Apply wallet credit after tax as its own order line, and check taxes, reports and refunds (PRO-4).
- **OPEN-3 COD reverse withdrawal.** When a vendor collects cash on delivery themselves, they owe the marketplace its commission. Negative vendor balances (MON-8) already allow it; the feature is not decided yet.
- **OPEN-4 Customer wallet timing.** The Customer Wallet (PRO-4) has no position in the Phase 6 sequence yet.
- **OPEN-5 Module decision files.** Each file is created before its module is built (P-4).

| Module ID | File | Status | Starting points |
|---|---|---|---|
| `pro.shipment-tracking` | `decisions/pro.shipment-tracking.md` | Not created | Admin carrier list with tracking-URL templates; "Require tracking number to mark shipped" and "Vendors can add custom carriers" settings; one customer email per shipment with its tracking link; tracking grouped by vendor in My Account; mirroring into WooCommerce Fulfillments when that feature is on. |
| `pro.payment-integrations` | `decisions/pro.payment-integrations.md` | Not created | One module per provider or one shared module; vendor onboarding and connection per provider; payout schedules and minimums; who pays gateway fees in split mode; refunds, disputes and chargebacks in each mode; Subscriptions renewals. |
