# `core.kernel` — Decision File

**Status:** Approved (2026-10-23)
**Phase / position:** Phase 1, module 1 in ROAD-3
**Branch:** `core.kernel`
**Depends on:** Groundwork (module 0)
**Implements master rules:** ROAD-3 row 1, ROAD-5 tickets 6–9, ARCH-6, ARCH-8 (points 2, 4 and 8, master v2.7), ARCH-10, PLAT-3 to PLAT-6, PLAT-10 (seeder and gate), API-3, DATA-1, DATA-2 (`schema_versions`, `events_outbox`, `audit_log`), DRY-1 (settings registry, migrations), DRY-2 (the kernel's shared pieces), SEC-1 (the worker's system actor)

Local IDs use the code `KER`.

---

## 1. Scope
- **In:**
  - Boot, the requirements check (PLAT-1) and the `mercatus()` facade (ARCH-4).
  - The module registry: manifest validation, dependency order, version compatibility, inactive modules with zero footprint (ARCH-6), and the compiled feature manifest.
  - Module registration for Pro (KER-13), and switching optional modules on and off, with the API behind the Modules screen (KER-14).
  - Uninstall (KER-16).
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
- `core.kernel.outbox_retention_days`: days processed outbox rows are kept. Default 7.
- `core.kernel.audit_purge_after_years`: delete audit entries older than this many years. Default: off, so entries are kept forever (KER-15).
- `core.kernel.remove_data_on_uninstall`: remove all Mercatus data when the plugin is deleted. Default: off (KER-16).

The registry itself is KER-4.

## 4. API
Routes go through the kernel's route registrar, and each declares the Groundwork route metadata (`'mercatus' => array( 'access' => … )`). Until `core.identity` exists, non-public routes fail closed (KER-11). Paths follow API-2. Ticket 6 builds the services behind these routes; the registrar and the routes follow once the cache (ticket 7) and the outbox (ticket 8) exist, since `/health` and cache flushing need them.
- `GET /features`: `public`. Module IDs and the features they provide; module versions only for administrators.
- `GET /health`: `admin`. Requirements, persistent cache present or absent, rate limiting on or off (PLAT-8), migrations pending or drifted, outbox backlog and failed events, the Action Scheduler heartbeat, and each module's health checks.
- `POST /system/cache/flush-group`: `admin`. Bumps a group's revision (PLAT-5). It changes no money or stock, so it needs no `Idempotency-Key`; repeating it is harmless.
- `GET /modules`: `admin`. Optional modules only, with their state; mandatory modules are never listed (KER-14).
- `POST /modules/{id}/activate` and `POST /modules/{id}/deactivate`: `admin`. Action subresources (API-1). Repeating one is harmless. Switching off a module that another active module depends on is refused with `mercatus_module_required_by`.

## 5. Events and hooks
- **Events emitted:**
  - `kernel.module.activated` and `kernel.module.deactivated` (optional modules, KER-14);
  - `kernel.settings.updated`, with the changed keys only, never values.
- **Event names:** `{module}.{aggregate}.{past_tense}`, for example `vendor.application.approved` (KER-8).
- **Bridge (ARCH-8 point 2):** after commit, every event reaches WordPress through `do_action( 'mercatus_event', $event )` and `do_action( 'mercatus_event_{name with dots as underscores}', $event )`, from one bridge.
- **Module registration:** the `mercatus_register_modules` action (KER-13).
- **Filters:** none. The kernel adds no `mercatus_*` filters.

## 6. UI
Unmet requirements, pending migrations and drifted migration checksums show as wp-admin notices to administrators, linking to the health report. The Modules screen, which lists only optional modules (KER-14), is built with the dashboard shell in `core.vendor` (ARCH-13) on this module's API.

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
- **Before `core.identity`:** `HealthEndpointAuthTest` and `CacheFlushAuthorizationTest` ship here and prove the refusals: anonymous gets 401, and a signed-in non-admin gets 403. `core.identity` adds the cases where an administrator is allowed (KER-11).
- **Extra:** `ModuleActivationTest`. Mandatory modules can't be switched off or listed. Switching off a module that an active module depends on is refused. An optional module switched off leaves no footprint, proved with a fixture module. Reviews and Shipping prove their own zero footprint when they're built.
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
- **Scale suite:** seeding and the dump are KER-12; the schedule is KER-17.

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
- **KER-11 Route registrar (OQ-1).**
  - Modules register routes only through the registrar, which takes the manifest's `routes` class (ARCH-8 point 4).
  - It requires the access metadata (Groundwork §4), loads a route's schema only when that route matches, and sets each route's permission callback from its access level through an `AccessGate`.
  - The kernel's `AccessGate` allows `public` and refuses everything else (401 when signed out, 403 otherwise). `core.identity` replaces it with its policy pipeline (SEC-2). Admin routes are therefore registered now but work once `core.identity` lands.
- **KER-12 Scale seeding.**
  - `wp mercatus seed:scale` is deterministic (fixed random seed) and runs in steps; each Core module adds its step (ARCH-3 `database/seeders/`).
  - It creates products through WooCommerce CRUD (G-DB-4), even though that's slow, because the dump is built rarely.
  - The dump (`mysqldump`, compressed) is stored as a GitHub Actions cache entry keyed by a hash of the seeders, and rebuilt when they change.
  - `database/seeders/` is development tooling and is left out of the plugin zip (GW-10).

- **KER-13 Module registration (OQ-2).**
  - Pro, and any later add-on, registers modules on one documented action, `mercatus_register_modules`. The action passes a `ModuleRegistrar` contract (new and additive, so a minor version, ARCH-6), whose `register( ModuleManifest )` the add-on calls.
  - Core fires the action once, during boot, before validating the registry.
  - Master v2.7 adds module registration to ARCH-8 as point 8.
- **KER-14 Optional modules (OQ-3).**
  - In Free, Reviews (`core.reviews`) and Shipping (`core.shipping`) are optional. Every other Free module is always on. Every Pro module is optional.
  - Core marks its optional modules in its own module list; the contract doesn't change.
  - The Modules screen lists optional modules only, Pro modules included, with `pro.licensing` gating Pro's. Mandatory modules never appear.
  - Both optional Free modules are on by default on a new install.
  - Switching a module off keeps its data and leaves zero footprint: no hooks, routes, menu items or queries (ARCH-6). Switching it back on resumes.
  - With Shipping off, Mercatus adds no vendor shipping, and WooCommerce's own shipping settings, if any, apply. `core.orders` and `core.tax` must handle orders without vendor shipping packages, and their decision files cover it.
  - Master v2.7 records the optional modules in ARCH-6 and the Modules endpoints in API-2.
- **KER-15 Audit-log retention (OQ-4).** Entries are kept forever by default. The `core.kernel.audit_purge_after_years` setting deletes older entries in daily batches, and it is off by default. Ledger entries are never purged; they aren't part of the audit log.
- **KER-16 Uninstall (OQ-5).**
  - Deleting the plugin removes nothing unless `core.kernel.remove_data_on_uninstall` is on. This works like WooCommerce's `WC_REMOVE_ALL_DATA`, but as a setting.
  - When it is on, `uninstall.php` drops every `wp_mercatus_*` table, every `mercatus_*` option and Mercatus's Action Scheduler actions.
  - The setting's description warns that vendor balances and order splits go with them.
- **KER-17 CI schedule (OQ-6).**
  - The nightly full matrix runs only when `main` has changed since the last nightly.
  - The scale suite runs weekly, on demand (`workflow_dispatch`), and on pull requests labelled `scale`, instead of nightly.
  - This keeps GitHub Free's minutes in budget: about 37 billed minutes per full run against 2,000 a month. Revisit when the Core repository goes public, since public repositories get free minutes.
  - Master v2.7 changes PLAT-10, TEST-1 Layer 6 and TEST-4, and GW-6 changes to match.
- **KER-18 Contract additions and settings details (ticket 6).**
  - Additive, so a minor version (ARCH-6): `ModuleManifest` gains optional `settings` (a `SettingsProvider`) and `hooks` (`ModuleHooks`). `hooks` is the only place a module attaches WordPress hooks, which is how a switched-off module stays hook-free.
  - New contracts: `ModuleRegistrar` (KER-13), `ModuleHooks`, `SettingsProvider`, `SettingDefinition` and `SettingType`.
  - Setting keys start with the full module ID, for example `core.kernel.outbox_retention_days`, so keys never collide between Core and Pro.
  - A module's settings record is loaded when its settings are registered at boot. The first boot creates it; later boots read it from autoloaded options without a query.
  - Version constraints use Composer's syntax, ignoring pre-release suffixes, through the kernel's own small parser, so Mercatus ships no extra runtime library.
  - Errors are `DomainError` codes in the shared kernel (ARCH-7). PHPCS's exception-escaping sniff is off because Mercatus never prints exception messages.
- **KER-19 Cache adapters (ticket 7).**
  - `WpCachePort` stores every value in a small envelope with an optional soft expiry, so `false` is storable and a miss is unambiguous. Keys carry their group's revision, so `flushGroup()` is a revision bump (PLAT-5).
  - Revision pointers are seeded from the clock in milliseconds. A seconds seed could be overtaken by a burst of bumps within one second, letting an evicted pointer come back below an old value.
  - A per-request memory layer saves repeat round trips. `forgetRequestMemory()` lets long-running workers drop it between jobs.
  - Without coordination, a stale value is recomputed, not served.
  - Locks carry a token, so a request releases only its own lock.
  - `VersionedCacheKeyResolver` and `Revision` build PLAT-5 keys and hash long or unsafe ones.
  - Integration tests for one backend carry a `cache-<mode>` group, and the runner leaves out the other backends' groups.
  - `CacheFlushAuthorizationTest` comes with the cache-flush route in ticket 8.
- **KER-20 Migrations (ticket 8).**
  - New contracts (additive): `Migration` (one `up()` per file), `MigrationContext` (prefixed table names, the site's charset and collation, statements) and `MigrationSource` (the folder a manifest's `migrations` class points to).
  - Files are `NNNN_short_description.php` and return a `Migration`. They should be safe to repeat (`CREATE TABLE IF NOT EXISTS`).
  - The runner creates `schema_versions` itself, then holds a lock row (module `_lock`, version 0) that it treats as abandoned after ten minutes. It checks applied checksums before applying anything new.
  - A failing statement stops that module. Its database error is suppressed, never printed into a page.
  - Boot compares each active module's newest migration file with the applied version kept in `mercatus_kernel`. A module that is behind is rejected with `pending_migrations` (fail closed), and administrators see an "Update database" notice. The notice's link is nonce-checked and needs `manage_woocommerce` until `core.identity` provides policies.
  - Migrations also run on activation, through `wp mercatus migrate`, and when an optional module is switched on (DATA-3's lazy migration).
- **KER-21 Outbox, audit log and bridge (ticket 8).**
  - In the shared kernel: `DomainEvent`, `EventLane`, `ActorRef`, `Uuid` (v7), and the ports `EventPublisher`, `TransactionRunner`, `AuditLog` and `ExecutionContext`.
  - `EventSubscriber` is the contract for the classes a manifest's `eventSubscribers` lists. Event payloads hold no floats or objects, so they serialise exactly.
  - A unit of work buffers events and audit records, and the transaction runner writes them just before the outermost commit. Nested runs use savepoints, and an inner rollback drops only its own records.
  - After commit, the committing request claims its own critical events by UUID and dispatches them. Whatever fails waits for the poller.
  - Claims set `claimed_by` and a 60-second lease. Finishing or releasing requires the same token, so a worker whose lease ran out can't touch rows another worker took over.
  - Bulk events with the same coalesce key collapse into the newest; older ones become `superseded`.
  - Failures retry after 30 s doubling to an hour, plus up to 10% jitter, and are `failed` after 10 attempts.
  - The poller is one recurring Action Scheduler action (`mercatus_outbox_poll`, every minute). It runs as the system actor, and only while Action Scheduler is executing it or from WP-CLI. It also purges handled events after the outbox retention and, when switched on, old audit entries.
  - The system actor exists only inside `runAsSystem()`; request input can't switch it on (TM-39).
  - The integration tests run the transaction runner in savepoint mode, because each test already runs inside the WordPress test suite's transaction.
- **KER-22 Routes, health and module switches (ticket 8).**
  - In the contracts: `RouteProvider`, `RouteRegistrar` and `Access` (`public`, `authenticated`, `tenant`, `admin`), and `HealthCheck`, `HealthResult` and `HealthStatus`. The manifest validator rejects a `routes`, `migrations`, `eventSubscribers` or `healthChecks` class that doesn't implement its contract.
  - The registrar works under `mercatus/v1`. It refuses, and reports, an endpoint with no or an unknown access level, one that brings its own `permission_callback`, one without a callback, and a route that is empty or doesn't start with `/`. It sets the permission callback from the access gate.
  - Until `core.identity` adds its policies, the gate allows `public` endpoints only: visitors get 401 `mercatus_unauthenticated`, signed-in users, administrators included, 403 `mercatus_forbidden` (KER-11).
  - The kernel's routes: `GET /features` (public; module versions only for callers the gate allows as admin), `GET /health`, `GET /modules`, `POST /modules/{id}/activate` and `/deactivate`, and `POST /system/cache/flush-group` (all admin). Domain errors map to statuses: unknown module or setting 404; an always-on module or a dependency conflict 409; an invalid setting 422.
  - `/health` reports the cache backend, loaded and rejected modules, pending migrations, refused routes, the poller (warning after 10 minutes without a run) and the outbox (warning on failed events or a pending event older than 15 minutes), followed by each module's own health checks.
  - Switching a module publishes `kernel.module.activated` or `.deactivated` and writes a `module.activated` or `.deactivated` audit entry in one transaction. A refused switch records nothing.
  - Only the kernel that `Kernel::activate()` booted attaches site hooks (REST routes, the poller, the admin migrate action, WP-CLI), so kernels that tests create don't add listeners.
  - The integration bootstrap runs `Kernel::activate()` at `plugins_loaded` priority 5 instead of at `setup_theme`, so the tables exist before the site's kernel boots at the default priority and registers its routes. The committed OpenAPI file now documents the kernel's routes.

## 11. Open questions
None. All six were answered on 2026-10-23 and are recorded as KER-11 and KER-13 to KER-17.

## 12. Done when
- Master v2.7 is merged.
- Every test in §8 passes in CI with and without a persistent cache (Phase 1 gate, ROAD-3). The admin-allowed cases of the auth tests follow with `core.identity` (KER-11).
- `openapi/mercatus-v1.yaml` includes the kernel's routes, and Redocly passes.
- `wp mercatus seed:scale` builds the dump, and `BaselineQueryAndLatencyGateTest` passes on it.
- No Section 2 guardrail is violated, and this file is marked Implemented.
