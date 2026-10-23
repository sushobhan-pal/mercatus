# `core.kernel` — Decision File

**Status:** Draft
**Phase / position:** Phase 1, module 1 in ROAD-3
**Branch:** `core.kernel`
**Depends on:** Groundwork (module 0)
**Implements master rules:** ROAD-3 row 1, ROAD-5 tickets 6–9, ARCH-6, ARCH-8 (points 2 and 4), ARCH-10, PLAT-3 to PLAT-6, PLAT-10 (seeder and gate), API-3, DATA-1, DATA-2 (`schema_versions`, `events_outbox`, `audit_log`), DRY-1 (settings registry, migrations), DRY-2 (the kernel's shared pieces), SEC-1 (the worker's system actor)

Local IDs use the code `KER`.

---

## 1. Scope
- **In:**
  - Boot, the requirements check (PLAT-1) and the `mercatus()` facade (ARCH-4).
  - The module registry: manifest validation, dependency order, version compatibility, inactive modules with zero footprint (ARCH-6), and the compiled feature manifest.
  - The typed settings registry (DRY-1).
  - The migration runner and `wp mercatus migrate` (DATA-1).
  - `CachePort` and `CoordinationPort` over `wp_cache_*` (PLAT-3, PLAT-5, PLAT-6).
  - The outbox, its two-lane worker, the event bridge and the audit log (API-3, ARCH-8 point 2).
  - The route registrar (ARCH-8 point 4) and the health report (PLAT-4).
  - The scale seeder skeleton, the dump pipeline and `BaselineQueryAndLatencyGateTest` (ROAD-5 ticket 9, PLAT-10).
  - The shared-kernel pieces these need (DRY-2): Clock, IDs, error codes, cache keys and revisions, domain events, the transaction runner and the repository base.
- **Out:**
  - **`core.identity`:** actor identity, the policy engine, the base REST controller (error envelope, cursors, ETags, `If-Match`), idempotency, the rate limiter and API clients. `EtagFromVersionTest` sits in TEST-3 row 2 but belongs to `core.identity` (ROAD-5 ticket 11).
  - **`core.vendor`:** the dashboard shell, and with it the settings forms.
  - **Later modules:** the money allocator (`core.ledger`), the state machine (`core.vendor`), cursors (`core.identity`) and the projection runner (`core.catalog`). Each is built by the first module that needs it.
  - **When first needed (PLAT-6):** a database-backed `CoordinationPort`.
  - **`pro.licensing`:** licensing and Pro entitlement.

## 2. Data
- **Tables (DATA-2), each created by this module's first migrations:**
  - **`schema_versions`:** applied migrations per module with their checksums, plus the migration lock (KER-5).
  - **`events_outbox`:** with the DATA-2 columns and `idx_outbox_poll`. Each row also gets `attempts`, `last_error_code` and `payload` (JSON).
  - **`audit_log`:** actor type and ID, action, resource type and ID, request ID, a field-level change summary without free-text personal data (SEC-7), and `created_at`.
- **Migrations:** `database/migrations/{module-id}/NNNN_short_description.php`, forward only (KER-5).
- **Options:**
  - `mercatus_kernel` (autoloaded, small): the plugin version, the applied migration version per module, and the module states. It lets boot run without queries.
  - `mercatus_settings_{module-id}`, one per module (KER-4).
- **Seeder slice:** none of its own. The kernel provides `wp mercatus seed:scale` and the step framework (KER-12).

## 3. Settings
The kernel's own settings, changed by an administrator only:
- `kernel.outbox_retention_days`: days processed outbox rows are kept. Default 7.
- `kernel.audit_retention`: see OQ-4.
- `kernel.remove_data_on_uninstall`: see OQ-5.

The registry itself is KER-4.

## 4. API
Routes go through the kernel's route registrar, and each declares the Groundwork route metadata (`'mercatus' => array( 'access' => … )`). How they're protected before `core.identity` exists is OQ-1. Paths follow API-2.
- `GET /features`: `public`. Module IDs and the features they provide; module versions only for administrators.
- `GET /health`: `admin`. Requirements, persistent cache present or absent, rate limiting on or off (PLAT-8), migrations pending or drifted, outbox backlog and failed events, the Action Scheduler heartbeat, and each module's health checks.
- `POST /system/cache/flush-group`: `admin`. Bumps a group's revision (PLAT-5). It changes no money or stock, so it needs no `Idempotency-Key`; repeating it is harmless.

## 5. Events and hooks
- **Events emitted:**
  - `kernel.module.activated` and `kernel.module.deactivated` (Pro modules, OQ-3);
  - `kernel.settings.updated`, with the changed keys only, never values.
- **Event names:** `{module}.{aggregate}.{past_tense}`, for example `vendor.application.approved` (KER-8).
- **Bridge (ARCH-8 point 2):** after commit, every event reaches WordPress through `do_action( 'mercatus_event', $event )` and `do_action( 'mercatus_event_{name with dots as underscores}', $event )`, from one bridge.
- **Module registration:** see OQ-2.
- **Filters:** none. The kernel adds no `mercatus_*` filters.

## 6. UI
None. Unmet requirements, pending migrations and drifted migration checksums show as wp-admin notices to administrators, linking to the health report. Mercatus's own screens start with `core.vendor` (ARCH-13).

## 7. Extension seams
- **`CoordinationPort`:** a database-backed implementation can replace the cache-backed one without touching callers (PLAT-6).
- **Pro scale seeders:** if `ModuleManifest` gains an optional `seeders` entry, Pro modules can add scale-seeder steps. Adding an optional trailing argument is a minor version change (ARCH-6); the first Pro module that needs it adds it.
- **Health checks:** a module adds them through its manifest's `healthChecks`; the kernel aggregates them.
- **Actor context:** `ActorContext` is the port the audit log and the worker read the actor from. The kernel's version maps the WordPress user, or the system actor for cron and CLI (SEC-1); `core.identity` replaces it with `ActorIdentity`.

## 8. Tests
- **TEST-3 row 1 (kernel):**
  - `ModuleManifestValidatorTest`, `VersionCompatibilityTest`, `FeatureManifestCompilerTest` and `SettingsRegistryTest`;
  - `MigrationRunnerTest` and `FeaturesEndpointContractTest`;
  - `HealthEndpointAuthTest`, `ZeroQueryKernelBootTest` and `InactiveModuleZeroFootprintTest`.
- **TEST-3 row 2 (caching), except `EtagFromVersionTest`:**
  - `VersionedCacheKeyResolverTest` and `SoftExpiryStampedeCalculatorTest`;
  - `CachePortRedisTest`, `CachePortMemcachedTest`, `CachePortNoPersistentCacheTest`, `CoordinationPortNoPersistentCacheTest`, `BatchMultiGetIntegrationTest` and `SingleflightLockConcurrencyTest`;
  - `CacheFlushAuthorizationTest` and `WarmCacheZeroSqlBenchmarkTest`.
- **TEST-3 row 3 (outbox):**
  - `EventPayloadSerializerTest`, `BulkEventCoalescerTest` and `RetryBackoffPolicyTest`;
  - `OutboxTransactionalWriteTest`, `LeaseClaimParallelWorkerTest`, `CriticalVsBulkLanePriorityTest` and `DomainEventActionBridgeTest`;
  - `CronContextSpoofingTest` and `OutboxBatchThroughputBenchmarkTest` (1,000 events in under 2 s).
- **Scale:** `BaselineQueryAndLatencyGateTest` (TEST-3, Layer 6).
- **Conformance:** the `CachePort` and `CoordinationPort` adapters run Groundwork's conformance traits in the integration suite, under every cache profile.
- **When the auth tests can pass:** `HealthEndpointAuthTest` and `CacheFlushAuthorizationTest` depend on OQ-1.
- **Threat model:** this module owns no tests the threat model adds. The threats its TEST-3 tests cover are:

  | Threat | Tests |
  |---|---|
  | TM-5 | `CacheFlushAuthorizationTest` |
  | TM-34 | `OutboxTransactionalWriteTest` |
  | TM-36 | `SingleflightLockConcurrencyTest`, `BaselineQueryAndLatencyGateTest` |
  | TM-38 | `HealthEndpointAuthTest` |
  | TM-39 | `CronContextSpoofingTest` |
  | TM-40 | `InactiveModuleZeroFootprintTest` |

## 9. Scale
- **Boot:** Mercatus runs no queries of its own (`ZeroQueryKernelBootTest`). Module states and applied migration versions come from the autoloaded `mercatus_kernel` option; settings come from small autoloaded options.
- **Warm cache:** reads through `CachePort` hit no SQL (`WarmCacheZeroSqlBenchmarkTest`).
- **Outbox:** the throughput ceiling above, and claims through `idx_outbox_poll` only.
- **Scale suite:** seeding, the dump and the schedule are KER-12 and OQ-6.

## 10. Decisions
- **KER-1 Boot.**
  - `mercatus.php` hands over to `Mercatus\Core\Kernel\Kernel` on `plugins_loaded`, after WooCommerce has loaded.
  - The kernel first checks PLAT-1: the PHP, WordPress and WooCommerce versions, and that HPOS is on. If anything is unmet it shows an admin notice and loads nothing else.
  - `mercatus()` returns public interfaces only (ARCH-4).
- **KER-2 Module registry.**
  - Each Core module has a class that returns its `ModuleManifest`.
  - The kernel validates every manifest (ARCH-6) and orders modules by dependency.
  - The compiled registry is cached in memory and, when a persistent cache is present, in the object cache, keyed by the plugin versions.
  - Validation fails closed: a module with an invalid manifest, a missing dependency or an incompatible version isn't loaded, and the health report and an admin notice say why.
- **KER-3 Layer placement.**
  - **`src/Kernel/`:** boot, the registry and the settings registry.
  - **`src/Infrastructure/`:** the cache and coordination adapters, the outbox store and worker, and the migration runner's database access.
  - **`src/Shared/`** (published to Pro, semantic-versioned, DRY-2): `Clock`, `Id`, `ErrorCode`, `DomainEvent`, the `EventPublisher` and `TransactionRunner` ports, and cache keys and revisions.
- **KER-4 Settings registry.**
  - Each module defines its settings: key, type, default, sanitizer, JSON Schema, REST visibility, and the capability needed to change it.
  - Values are stored per module in `mercatus_settings_{module-id}`, autoloaded because they're small, and read through typed getters.
  - Writes are validated against the schema.
  - The same definitions will generate the settings API and the dashboard forms (DRY-1).
- **KER-5 Migrations.**
  - Forward only, with no down migrations. Each migration's checksum is recorded in `schema_versions`.
  - Changing an already-applied migration file is reported as drift, and blocks further migrations for that module until resolved.
  - One run at a time: a lock row in `schema_versions` with a 10-minute expiry.
  - Migrations run on plugin activation, on `wp mercatus migrate`, and from an admin notice after an update. Boot detects pending migrations by comparing the autoloaded applied versions, without querying.
  - Schema changes run synchronously; data backfills run as batched Action Scheduler jobs.
  - A Pro module's tables are created when that module is first activated (DATA-3).
- **KER-6 Cache adapters.**
  - A per-request memory layer sits in front of `wp_cache_*`.
  - Keys carry their group's revision.
  - Revision pointers are seeded from the clock with `add`, then incremented (PLAT-5).
  - `remember()` stores a soft expiry inside the payload, with a hard TTL of five times the soft one. When the soft expiry passes, the caller that takes the `CoordinationPort` lock (non-blocking) refreshes the value, and other callers are served the stale value.
  - Only these adapters call `wp_using_ext_object_cache()`, once at boot (PLAT-3).
- **KER-7 Outbox and worker.**
  - Two lanes, `critical` and `bulk`.
  - Events and audit rows are written in the command's transaction (ARCH-10, API-3).
  - Critical handlers run in the same request right after commit. Anything that fails stays in the outbox for the poller.
  - The poller is one recurring Action Scheduler action every minute (never one per event, G-DB-6). `wp mercatus outbox:work` serves sites that run a real cron.
  - Rows are claimed with `claimed_by` and `lease_until`.
  - Retries use exponential backoff with jitter. After 10 attempts a row becomes `failed` and shows in the health report.
  - Bulk events with the same `coalesce_key` are merged.
- **KER-8 Events.**
  - Names follow `{module}.{aggregate}.{past_tense}`.
  - Every event carries the ARCH-8 fields plus a `schema_version` for its payload, and payloads validate against a versioned JSON Schema (TEST-1, Layer 3).
  - The WordPress bridge is a critical-lane subscriber, so it fires only after commit.
- **KER-9 Audit log.** Append-only, written by the transaction runner. Rows record what changed (keys, IDs, the old and new status) but never free text or personal data (SEC-7).
- **KER-10 IDs and clock.**
  - Public identifiers (`event_uuid` and similar) are UUIDv7, which sort by time and keep inserts index-friendly.
  - `Clock` has a system and a frozen implementation; no code calls `time()` directly outside `SystemClock`.
- **KER-11 Route registrar.**
  - Modules register routes only through the registrar, which takes the manifest's `routes` class (ARCH-8 point 4).
  - It requires the access metadata (Groundwork §4), loads a route's schema only when that route matches, and sets each route's permission callback from its access level.
  - What happens to non-public access levels before `core.identity` is OQ-1.
- **KER-12 Scale seeding.**
  - `wp mercatus seed:scale` is deterministic (fixed random seed) and runs in steps; each Core module adds its step (ARCH-3 `database/seeders/`).
  - It creates products through WooCommerce CRUD (G-DB-4), even though that's slow, because the dump is built rarely.
  - The dump (`mysqldump`, compressed) is stored as a GitHub Actions cache entry keyed by a hash of the seeders, and rebuilt when they change.
  - `database/seeders/` is development tooling and is left out of the plugin zip (GW-10).

## 11. Open questions
- **OQ-1 Kernel routes before `core.identity`.** The kernel ships before the policy engine and the base REST controller.
  - **(a, recommended)** The kernel builds the registrar with a fail-closed access gate. `public` routes work now (`GET /features`). Non-public routes are registered but answer 401 or 403 until `core.identity` plugs in its policy pipeline. `HealthEndpointAuthTest` and `CacheFlushAuthorizationTest` pass once that lands, and `FeaturesEndpointContractTest` runs now.
  - **(b)** The kernel builds only the application queries and commands. All three routes, and their tests, move to `core.identity`.
  - **(c)** Interim WordPress capability checks (`manage_woocommerce`) until `core.identity`. This breaks G-SEC-1, so not recommended.
- **OQ-2 How Pro registers its modules.** ARCH-8 lists the published extension points, but none of them is module registration.
  - **(a, recommended)** One documented action, `mercatus_register_modules`, passing a new `ModuleRegistrar` contract (additive, so a minor version). This needs master v2.7 to add "module registration" to ARCH-8.
  - **(b)** A filter returning an array of manifests. Not recommended: G-EXT-2 forbids filters over internal arrays.
  - **(c)** Pro calls `mercatus()->modules()->register()` on `plugins_loaded`. This depends on load order and is easy to break.
- **OQ-3 Can the admin switch off Free modules** (for example Reviews)?
  - **(a, recommended)** No. Free modules are always on in version 1. Activation, deactivation and zero footprint apply to Pro modules, and the kernel tests them with a fixture module.
  - **(b)** Yes, for optional Free modules. This means more combinations to test, and every module needs a safe off state.
- **OQ-4 Audit-log retention.** The audit log is append-only and holds no personal data, so it only grows.
  - **(a, recommended)** Keep entries forever by default, with an admin setting "Delete audit entries older than N years", off by default.
  - **(b)** A fixed retention, for example 8 years, which covers common tax-record rules.
  - **(c)** One year.
- **OQ-5 Uninstall.**
  - **(a, recommended)** Keep all Mercatus data when the plugin is deleted, unless the admin has turned on "Remove all Mercatus data when the plugin is deleted" (off by default), the way WooCommerce's `WC_REMOVE_ALL_DATA` works. Vendor balances and order splits are financial records.
  - **(b)** Always remove.
  - **(c)** Never remove.
- **OQ-6 Actions minutes and the scale schedule.** The first `main` run used about 37 billed minutes (19 jobs, each rounded up to a whole minute). A pull-request run uses about 8. On GitHub Free (2,000 minutes a month for private repositories, GW-12), the nightly full matrix alone uses about 1,100 a month. The nightly scale suite (PLAT-10), run with and without a persistent cache, would push that over the limit.
  - **(a, recommended)** Run the nightly matrix only when `main` changed since the last run. Run the scale suite weekly, on demand, and on pull requests labelled `scale`. This needs master v2.7 to change PLAT-10's "nightly" and an update to GW-6.
  - **(b)** Keep everything nightly as written, and accept running past the free minutes (paid overage, or a paid plan).
  - **(c)** Add a self-hosted runner (for example this Mac or a small server) for the nightly and scale jobs. Its minutes are free, but it has to be maintained.

## 12. Done when
- Every test in §8 passes in CI with and without a persistent cache (Phase 1 gate, ROAD-3), except those that OQ-1's answer moves to `core.identity`.
- `openapi/mercatus-v1.yaml` includes the kernel's routes, and Redocly passes.
- `wp mercatus seed:scale` builds the dump, and `BaselineQueryAndLatencyGateTest` passes on it.
- No Section 2 guardrail is violated, and this file is marked Implemented.
