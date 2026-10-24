# `core.identity` — Decision File

**Status:** Implemented (2026-10-24)
**Phase / position:** Phase 1, module 2 in ROAD-3
**Branch:** `core.identity`
**Depends on:** `core.kernel` (module 1)
**Implements master rules:** ROAD-3 row 2, ROAD-5 tickets 10–11, SEC-1, SEC-2, SEC-3 (sort, filter and cursor allow-lists), SEC-4, SEC-5, PLAT-3 (L1 actor cache, L3 HTTP headers), PLAT-7, PLAT-8, PLAT-9, API-1 (error envelope, cursors), API-2 (`GET /me`), ARCH-6 and ARCH-8 points 3, 5 and 7 (permissions and dashboard menus), ARCH-9 (`/me` in one call), ARCH-13 (menu compilation), DRY-2 (cursors, the base REST controller), DATA-2 (`api_clients`, `idempotency`), TEST-2

Rules for this file (P-4, DRY-4): cite master IDs instead of restating them, never contradict the master document, and give each decision made here a local ID using the module's short code (for example `KER-1`). If this module needs a master rule changed, the master document changes first.

Local IDs use the code `IDN`.

---

## 1. Scope
- **In:**
  - `ActorIdentity`: resolving the SEC-1 actors from WordPress's authenticated user, cached per request and across requests under an identity revision (SEC-2, G-CACHE-4).
  - The policy pipeline, replacing the kernel's fail-closed access gate (KER-11): API-client scope or named permission, module entitlement, then access level. Resource policies run in each module's application layer, and this module provides their interface (SEC-2, SEC-4).
  - Named permissions and dashboard menus declared in module manifests (ARCH-6, ARCH-8 points 3 and 7), compiled per actor for `/me` (ARCH-13).
  - `GET /me` (API-2, ARCH-9).
  - The base REST controller in the shared kernel (DRY-2): the error envelope, cursor pagination, ETags and `If-Match`, `Cache-Control`, and sort and filter allow-lists (API-1, PLAT-3, PLAT-9, SEC-3).
  - Idempotency (PLAT-7) and the rate limiter (PLAT-8).
  - API clients: application passwords mapped to `api_clients`, with scopes, expiry, revocation and IP allow-lists (SEC-1).
  - Hand-offs from the kernel (KER-11): the admin-allowed cases of `HealthEndpointAuthTest` and `CacheFlushAuthorizationTest`, and the kernel's wp-admin checks moving onto the identity service.
- **Out:**
  - **`core.vendor`:** the `vendors` table and resolving `vendor_owner` from it, through this module's claim seam (IDN-3); the dashboard shell, and with it the screens for API clients and manager permissions.
  - **`core.store`:** the store summary in `/me`, through this module's section seam (IDN-12).
  - **Each module:** its own resource policies (`StorePolicy`, `WithdrawalPolicy` and so on) and its named permissions.
  - **Pro:** `vendor_staff` resolution and staff scope (Staff & Groups, IDN-16), narrowing a manager's scope to vendor groups or membership plans (IDN-15), `delivery_agent` (Delivery), `affiliate` (Affiliate), and module entitlement for Pro modules (`pro.licensing`).
  - **Later modules (SEC-1):** OAuth2/PKCE. Webhook signature verification is built with the first webhook (Pro payment integrations).
  - **Not in v1 (PLAT-6):** rate limiting without a persistent object cache.

## 2. Data
- **Tables (DATA-2), created by this module's migrations:**
  - **`api_clients`:** one row per registered client: an integration, or an app that users sign in to (IDN-17). Columns: `client_key_hash` (SHA-256 of the client's application ID), `name`, owning `actor_type` and `actor_id`, `scopes` (JSON), `ip_allow_list` (JSON), `password_lifetime_days`, `status` (`active` or `revoked`), `last_used_at`, `created_by`, `created_at`, `updated_at` and `version`. Keys as DATA-2.
  - **`idempotency`:** `actor_fingerprint`, `route`, `idempotency_key`, `request_hash` (SHA-256), `status` (`processing` or `completed`), `response_status`, `response_body` (JSON), `created_at`, `expires_at` and `version`. Keys as DATA-2.
- **No table for identities.** WordPress users, `vendors` (`core.vendor`) and Pro's `staff_assignments` are the sources. The cache holds the resolved identity (IDN-2).
- **User meta:** `mercatus_marketplace_manager`, the appointment flag (IDN-15). It's small and loads with the user.
- **Seeder slice:** none. Users arrive with `core.vendor`'s step and later ones.

## 3. Settings
Changed by an administrator only:
- `core.identity.manager_permissions`: the named permissions every marketplace manager holds (IDN-15). Default: none.
- `core.identity.idempotency_retention_hours`: how long completed idempotency rows are kept. Default 24 (IDN-9).
- Rate limits, per minute (IDN-19): `core.identity.rate_limit_visitor_reads` 120, `…_visitor_writes` 20, `…_user_reads` 600, `…_user_writes` 120, `…_client_reads` 1200, `…_client_writes` 300.
- `core.identity.trusted_proxy_header`: the header carrying the visitor's IP address behind a proxy or CDN, for example `X-Forwarded-For`. Default: empty, so the connection's address is used (IDN-10).

## 4. API
Routes go through the kernel's registrar with the route metadata (Groundwork §4), which gains `permission`, `idempotency` and `rate_limit` (IDN-4, IDN-9, IDN-10).
- `GET /me`: `authenticated`. Returns the actor (type, user ID, display name, store ID if any), its permissions, the feature manifest (with module versions for administrators), the compiled dashboard menu, and sections that other modules add (IDN-12). Sent with `Cache-Control: private, no-cache` and an ETag, so repeat loads get a 304.
- **API clients (IDN-18):** master v2.8 adds these to API-2:
  - `GET /api-clients`, `POST /api-clients`, `PATCH /api-clients/{id}` (`If-Match`) and `POST /api-clients/{id}/revoke`: `admin` with the `identity.api_clients.manage` permission.
  - `POST /api-clients/{id}/passwords`: creates an integration's application password and returns it once.
  - `GET /me/app-passwords` and `POST /me/app-passwords/{uuid}/revoke`: `authenticated`. Vendors never enter wp-admin (ARCH-13), so this is where they see and revoke their app sign-ins.
- **Idempotency:** none of these routes change money or stock, so none requires `Idempotency-Key`.
- **Pagination:** `GET /api-clients` uses cursors, the base controller's only list style (IDN-7).

## 5. Events and hooks
- **Events emitted:**
  - `identity.api_client.registered`, `identity.api_client.updated` and `identity.api_client.revoked`;
  - `identity.api_client.password_created` (never the password);
  - `identity.manager.appointed`, `identity.manager.removed` and `identity.manager.permissions_changed` (IDN-15).
- **Audit entries:** each event above. Refused requests aren't audited, so a flood can't turn into audit-log writes.
- **Events consumed:** none.
- **WordPress hooks used:**
  - `application_password_did_authenticate`, to learn which password authenticated the request (IDN-11);
  - `set_user_role`, `add_user_role`, `remove_user_role` and `deleted_user`, to bump the identity revision (IDN-2).
- **Filters:** none. ARCH-8 point 6 lists no identity decision points.

## 6. UI
None in this module. The dashboard shell arrives with `core.vendor` (ARCH-13) and builds the API-client and manager-permission screens on this module's API. `/me` gives the shell its actor, menu and features in one call (ARCH-9).

## 7. Extension seams
- **Claim sources (IDN-3):** modules tell the resolver who a user is to them. `core.vendor` adds `vendor_owner`, and Pro adds `vendor_staff`, `delivery_agent` and `affiliate`.
- **Permissions and menus:** a manifest's `permissions` and `dashboardMenu` classes (ARCH-6). The validator rejects classes that don't implement their contract, as KER-22 does for the others.
- **`/me` sections (ARCH-8 point 5):** `core.store` adds the store summary.
- **Resource policies:** one interface for every module's policy (SEC-2 step 5).
- **Module entitlement (SEC-2 step 4):** Core treats an active module as entitled; `pro.licensing` replaces that check for Pro modules.
- **Manager scope (IDN-15):** Pro narrows a manager's scope from all vendors to vendor groups or membership plans, through a claim source.
- **Per-manager permissions (IDN-15):** the shared set can gain per-user overrides later, when a real need appears, without changing callers.
- **Authentication sources:** an OAuth2 module (SEC-1) adds a source; the pipeline after authentication doesn't change.

## 8. Tests
- **TEST-3 row 4 (identity and policy):**
  - `ActorIdentityResolverTest`, `PolicyEngineMatrixTest` and `DashboardMenuCompilerTest`;
  - `CachedActorIdentityInvalidationTest`, `ApiClientScopeIntegrationTest` and `IdempotencyStoreIntegrationTest`;
  - `IdorActorMatrixTest`, `CsrfNonceEnforcementTest` and `RateLimiterTest`.
- **From TEST-3 row 2 (ROAD-5 ticket 11):** `EtagFromVersionTest`.
- **Added by the threat model:** `SortAndFilterAllowListTest` (TM-35). Sort, filter and cursor parameters accept only allow-listed columns and typed values.
- **From the kernel (KER-11):** `HealthEndpointAuthTest` and `CacheFlushAuthorizationTest` gain the cases where an administrator, and a manager holding the permission, is allowed. `FeaturesEndpointContractTest` gains the case where an administrator sees module versions.
- **Generated matrix (TEST-2):** `PolicyMatrixDataProvider` gains `marketplace_manager` with and without the route's permission, `integration_client` inside and outside its scopes, and `vendor_staff` once a claim source provides it.
- **Manager scope:** `PolicyEngineMatrixTest` proves, with a fixture claim source, that a manager whose scope excludes a vendor gets 404 for that vendor's resources (IDN-15).
- **Extra:** `MeEndpointContractTest` checks `/me` against its schema. Unit tests cover the error envelope and the cursor codec.
- **Threat model:**

  | Threat | Tests |
  |---|---|
  | TM-1 | `CsrfNonceEnforcementTest` |
  | TM-2 | `ApiClientScopeIntegrationTest`, `IdorActorMatrixTest` |
  | TM-5 | `PolicyEngineMatrixTest`, `IdorActorMatrixTest` |
  | TM-6 | `PolicyEngineMatrixTest`, `DashboardMenuCompilerTest` |
  | TM-8 | `CachedActorIdentityInvalidationTest`, `ActorIdentityResolverTest` |
  | TM-17 | `IdempotencyStoreIntegrationTest` |
  | TM-18 | `EtagFromVersionTest` |
  | TM-35 | `SortAndFilterAllowListTest` |
  | TM-37 | `RateLimiterTest` |

## 9. Scale
- **Seeder slice:** none (§2).
- **Scenarios added to `BaselineQueryAndLatencyGateTest`:** resolving the identity warm and cold, `GET /me`, the overhead of an idempotent `POST`, the rate limiter, and a 304 from `If-None-Match`.
- **Target ceilings (Mercatus's own queries), fixed during implementation:**
  - A warm identity with a persistent cache: 0.
  - A cold identity: one indexed query per claim source. There's none until `core.vendor`.
  - `GET /me`: the identity plus 0.
  - An idempotent `POST`: 2 more than the command (insert the row, then complete it).
  - The rate limiter: 0 queries and one counter increment.
  - A 304 with a persistent cache: 0.

## 10. Decisions
- **IDN-1 One identity per request.**
  - `ActorIdentity` is immutable. It holds the SEC-1 type, the user ID, the store (tenant) ID if any, the named permissions, a manager's vendor scope (IDN-15), the API client if any, and the identity revision it was resolved at.
  - The type is the highest that applies: `administrator`, then `marketplace_manager`, `vendor_owner`, `vendor_staff`, `delivery_agent`, `affiliate`, `customer`.
  - Store membership and permissions are kept apart from the type, so an administrator who also owns a store can still use the store's routes.
  - A user belongs to at most one store in v1.
- **IDN-2 Resolution and caching.**
  - WordPress authenticates (cookie plus nonce, or an application password); Mercatus never parses credentials itself.
  - The resolver starts from the authenticated user, asks every claim source, and caches the result per request (L1) and, with a persistent cache, in `mercatus_actors` under the revision pointer `actor:{user_id}` (PLAT-5) for 300 seconds.
  - The revision is bumped:
    - by a module's command right after commit, when it suspends a vendor or removes staff, through the `IdentityRevisions` port (G-CACHE-4);
    - by a role change or user deletion in WordPress (§5);
    - by a manager appointment or removal.
  - A change to the shared manager permissions bumps one global identity revision.
- **IDN-3 Claim sources.** A new contract that modules implement to say who a user is to them, for example "owner of store 12". The resolver combines the claims under IDN-1. Pro's sources also narrow a manager's scope (IDN-15). Until `core.vendor` lands, no source exists, so everyone signed in is a `customer`, an `administrator` or a `marketplace_manager`. Tests use fixture sources.
- **IDN-4 Access levels and named permissions.**
  - Route metadata keeps `access` and gains an optional `permission`: a named permission that some module declares in its manifest's `permissions` class. The registrar refuses unknown permission names.
  - `admin`: administrators always; marketplace managers when they hold the route's permission. An `admin` route without a permission is administrators only.
  - `tenant`: the store's owner; staff when their scope grants the route's permission (Pro).
  - `authenticated`: anyone signed in, who must also hold the route's permission if it names one.
  - Refusals follow SEC-4: 401 when signed out, 404 for another store's resource, 403 otherwise.
- **IDN-5 The policy pipeline.** `PolicyAccessGate` replaces `FailClosedAccessGate` (KER-11) and runs SEC-2's steps up to the access level. Resource policies, field visibility and state-machine validation (SEC-2 steps 5–7) run in the application layer. No controller calls `current_user_can()` (G-SEC-1); only the resolver reads WordPress capabilities, once per resolution.
- **IDN-6 Error envelope.**
  - The base controller turns every `DomainError` into the API-1 envelope, through the kernel's `ErrorResponse`, which moves into the shared kernel (DRY-2).
  - `message` is in the requesting user's language.
  - `request_id` is the execution context's request ID and is also sent as an `X-Request-Id` header.
  - Unexpected errors return 500 `mercatus_internal_error`, never with details or a stack trace.
- **IDN-7 Cursor pagination.**
  - A cursor is opaque base64url JSON holding the last item's sort values and the direction.
  - Each list route declares an allow-list of sort columns with their types. Cursor values are checked against it and bound with `$wpdb->prepare()` (SEC-3).
  - Cursors aren't signed: tampering with one can only move within rows the actor may already see.
  - `per_page`: default 24, maximum 100. `has_more` comes from fetching one extra row; there are no `COUNT` queries.
- **IDN-8 ETags, `If-Match` and cache headers.**
  - An item's ETag is its `version` (PLAT-9). A list's ETag hashes the page's IDs and versions.
  - `If-None-Match` returns 304.
  - A `PATCH` or `PUT` without `If-Match` gets 428 `mercatus_if_match_required`; a stale one gets 409 `mercatus_version_conflict`.
  - `Cache-Control` follows PLAT-3 L3 by access level.
- **IDN-9 Idempotency.**
  - A route declares `idempotency: required` for PLAT-7's `POST`s; the registrar enforces it, and any `POST` accepts a key.
  - Keys are 1–255 printable ASCII characters.
  - The fingerprint is the actor's type and ID plus the API client, if any. The request hash covers the method, the route and the canonical JSON body.
  - The row is inserted as `processing` before the command runs (PLAT-7).
  - Domain errors (4xx) are stored like successes. An unexpected failure deletes the row, so the client can retry.
  - A replay returns the stored response with `Idempotent-Replayed: true`. A key still processing gets 409 `mercatus_idempotency_in_progress`; a different payload gets 422 `mercatus_idempotency_mismatch`.
  - A daily Action Scheduler action deletes expired rows in batches.
- **IDN-10 Rate limiting.**
  - The limits, buckets and defaults are IDN-19's. Counters are `CoordinationPort` increments (PLAT-6).
  - Responses carry `RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset`; a refusal is 429 with `Retry-After`.
  - Visitors are counted by IP address: the connection's own unless `core.identity.trusted_proxy_header` is set, because a spoofable header must never be trusted by default.
  - The system actor and administrators aren't limited.
  - Without a persistent cache nothing is limited, and `/health` says so (PLAT-4).
- **IDN-11 API clients.**
  - When WordPress authenticates an application password, Mercatus finds the client from the password's application ID and checks its status, the password's lifetime and the IP allow-list. The client's scopes then cap the user's permissions.
  - Scopes use the same vocabulary as permissions (DRY-1), plus `read` (safe methods) and `write` (everything else) for routes that name no permission.
  - Cookie requests have no client.
  - Passwords that don't map to a client are refused (IDN-17).
- **IDN-12 `/me`.** It's built from the identity, the kernel's feature manifest, the compiled menu and section providers, which other modules register. The menu shows an item only when the actor holds its permission and its module is active. A hidden item is never the security boundary (ARCH-13).
- **IDN-13 Execution context.** An identity-backed execution context replaces the kernel's `WordPressExecutionContext`. Events and audit entries then record SEC-1 actor types instead of `user`. The system actor is unchanged (TM-39).
- **IDN-14 Contracts and the shared kernel.**
  - **New contracts, additive (minor versions, ARCH-6):** the actor type and identity, claim sources, permission and menu providers, `/me` section providers, the resource policy interface, and `IdentityRevisions`.
  - **New in the shared kernel (DRY-2):** the base REST controller, the cursor codec and the ETag helpers.

- **IDN-15 Administrators and marketplace managers (OQ-1).**
  - `administrator` means WordPress users with `manage_options`.
  - `marketplace_manager` means users an administrator appoints in Mercatus. The appointment is user meta, not a WordPress role, so it grants no WordPress or WooCommerce capabilities. WooCommerce Shop Managers get no Mercatus access unless appointed.
  - All managers share one set of named permissions, ticked on a settings screen (`core.identity.manager_permissions`).
  - Each manager has a vendor scope. In Free it is always all vendors; Pro narrows it to vendor groups or membership plans. Resource policies and list queries apply the scope, and a vendor outside it answers 404, so a scoped manager can't learn it exists (SEC-4).
  - Per-manager permission overrides stay a seam, for when a real need appears.
- **IDN-16 Vendor staff are Pro-only (OQ-2).** `vendor_staff` exists only while Pro Staff & Groups is active. Core defines the actor type and resolves staff through a claim source (IDN-3); Free vendors work alone. Master v2.8 adds `vendor_staff` to SEC-1's Pro-only actors.
- **IDN-17 Application passwords from outside Mercatus (OQ-3).**
  - Mercatus accepts an application password only when its application ID belongs to a client an administrator registered: an integration or an app.
  - The password then gets that client's scopes, capped by the user's own permissions, and the client's password lifetime.
  - Any other password gets 401 `mercatus_api_client_unregistered` on Mercatus routes.
  - Mobile apps sign in through WordPress's own authorize-application flow, with the app's registered ID.
- **IDN-18 Managing API clients (OQ-4).**
  - Administrators use the REST endpoints in §4. Everyone sees and revokes their own app sign-ins through `/me/app-passwords`, since vendors never enter wp-admin.
  - `wp mercatus api-client` WP-CLI commands run the same application commands, so integrations can be set up before the dashboard shell exists.
  - Master v2.8 adds the endpoints to API-2.
- **IDN-19 Rate limits (OQ-5).**
  - A fixed one-minute window per actor and bucket: one counter increment per request.
  - The actor is the user, the API client, or the IP address for visitors (IDN-10).
  - There are two buckets: reads (`GET`, `HEAD`) and writes (everything else).
  - Defaults per minute: visitors 120 reads and 20 writes; signed-in users 600 and 120; API clients 1,200 and 300 (§3).
  - Routes that attract abuse, such as vendor applications and reviews, declare a tighter limit in their metadata.

- **IDN-20 Actor identity, policy engine and `/me` (ticket 10).**
  - **Code:** `src/Identity/` (Domain, Application, Infrastructure, Api). New contracts in `Contracts\Identity`: `ActorType`, `ActorIdentity`, `ActorClaim`, `VendorScope`, `ClaimSource`, `PermissionDefinition`, `PermissionProvider`, `MenuItem`, `DashboardMenuProvider`, `CurrentActor` and `IdentityRevisions`. New in `Contracts\Settings`: `SettingsReader`, which the kernel's settings registry implements. `ModuleManifest` gains `claimSources`, and the validator checks it, `permissions` and `dashboardMenu` (INVALID_CLASS).
  - **Deferred contracts:** `/me` section providers and the resource-policy interface (IDN-14) arrive with their first users, `core.store` and `core.vendor`, so no contract is frozen before something needs it. Adding them then is additive.
  - **Policy engine:** `PolicyEngine::access()` decides routes and menu items, and `PolicyEngine::vendorResource()` decides vendor resources (SEC-4, IDN-15). `PolicyAccessGate` replaces `FailClosedAccessGate`; the gate now takes the route's permission and can say whether a permission exists.
  - **Module entitlement (SEC-2 step 4):** a switched-off module registers no routes, so Core has nothing to check yet. `pro.licensing` adds the check.
  - **Kernel permissions:** `kernel.health.view`, `kernel.cache.flush` and `kernel.modules.manage`. `/features` shows module versions to administrators only. The kernel's migrate action and migration notice check the identity's administrator. Notices that can show before modules load keep WordPress's own checks.
  - **Store conflicts:** a user with conflicting store claims gets no store, and the owner or staff type doesn't apply.
  - **Manager permissions:** stored as comma-separated text, since the settings registry has no list type. The identity cache key carries a hash of the setting, so a change takes effect at once without the global revision IDN-2 described.
  - **`identity.manager.permissions_changed`:** emitted by the settings write path once it exists. The kernel's `kernel.settings.updated` (core.kernel §5) isn't emitted yet either: nothing writes settings outside tests.
  - **Appointing managers:** `ManagerAppointments`, plus `wp mercatus manager appoint|remove|list`. A REST endpoint and screen need API-2 entries, which come with the dashboard shell (`core.vendor`).
  - **`/me`:** sends `Cache-Control: private, no-cache` now; its ETag comes with ticket 11's base controller.
  - **Integration tests:**
    - The bootstrap registers a test-only `pro.testactors` module, whose claim source makes vendor owners and staff from user meta until `core.vendor` exists.
    - The site kernel's transaction runner uses savepoints in tests: a real `COMMIT` ended the test's transaction and leaked rows into later tests.
    - `IdorActorMatrixTest` calls each route's own permission callback. Its 404 cases (another store's resource) need tenant routes and resources, and a tripwire fails once those routes exist.
  - **Found and fixed:** `WpCachePort::bumpRevision()` now adds the pointer before incrementing it. An evicted pointer no longer restarts at 1 in Redis (PLAT-5).
  - **Scale ceilings:** `identity.resolve` (a fresh resolver, as a new request builds it) and `me.read` are both 0 Mercatus queries, with and without a persistent cache. A cold identity's one query per claim source arrives with `core.vendor`'s source.

- **IDN-21 Base REST controller, idempotency, rate limiting and API clients (ticket 11).**
  - **Shared kernel (DRY-2):** `Shared\Http` gains `ApiError`, `ETag`, `Cursor`, `ListDefinition`, `ListRequest`, `Page` and `Schema`.
  - **No controller base class:** routes stay endpoint arrays. Every endpoint runs through the kernel's `RoutePipeline`: the rate limit, the gate, If-Match on `PATCH` and `PUT`, and idempotency on `POST`. An escaped domain error becomes a 400; anything unexpected a 500, logged to the WooCommerce log.
  - **The envelope:** `ResponseFinisher` hooks `rest_request_after_callbacks`, because `rest_post_dispatch` doesn't run for internal dispatches. Messages follow WordPress's own locale switch, so the dashboard sends `_locale=user`.
  - **Error maps (IDN-6):** what moved into the shared kernel is the mechanism, `ApiError`. Each module keeps its own map of codes to statuses and messages (ARCH-7); the kernel's `ErrorResponse` is its map.
  - **Lists:** cursor queries are keyset over allow-listed columns. The first list sorts by ID only; a general keyset builder comes with the first list that sorts by another column.
  - **Idempotency:** the claim returns its row ID, so an idempotent `POST` costs 2 queries. The outbox poller purges expired rows every minute, in bounded batches, instead of IDN-9's daily action, so there's no extra scheduled action. A replayed error carries `replayed: true` in its details. Keys belong to the request's subject: client, user or visitor IP.
  - **Rate limiting:** a route's own limit counts in a second, per-route counter. Responses carry `RateLimit-Limit`, `-Remaining` and `-Reset`. The `identity.rate_limiting` health check warns when there's no persistent cache.
  - **API clients, storage:** `api_clients` keeps the hash of each application ID (DATA-2). An integration's application ID also lives in its user's meta (`mercatus_api_client_app_{id}`), so its later passwords reuse it. The kind follows `actor_type`: `user` for an integration, `app` for an app.
  - **API clients, requests:** WordPress's `application_password_did_authenticate` feeds `ApiClientAuthentication`. Refusals are `mercatus_api_client_unregistered`, `_revoked` and `_expired` (401), `_ip_denied` (403) and `mercatus_api_client_scope` (403). `ActorIdentity` gains `clientScopes`, and `PolicyEngine::scope()` decides them.
  - **Layers:** the endpoints reach the client commands through an Application port, `ApiClientDirectory`. The permission and menu providers live in the API layer, because they translate labels (ARCH-7).
  - **Integration tests:** WordPress records an application-password sign-in in `$wp_rest_application_password_uuid` and then skips the cookie nonce check, so tests that simulate a sign-in clear it.
  - **Scale ceilings:** with and without a persistent cache, `me.not_modified` and `ratelimit.hit` are 0 Mercatus queries (with a cache, the limiter is one counter increment) and `idempotency.post` is 2.

## 11. Open questions
None. All five were answered on 2026-10-24 and are recorded as IDN-15 to IDN-19.

## 12. Done when
- Master v2.8 is merged (IDN-16, IDN-18).
- The TEST-3 row 4 tests, `EtagFromVersionTest` and `SortAndFilterAllowListTest` pass in CI with and without a persistent cache (ROAD-2 step 9). So do the admin-allowed cases of the kernel's auth tests.
- `openapi/mercatus-v1.yaml` includes `/me` and the API-client routes, and Redocly passes.
- The scale gate has the identity scenarios, with their ceilings fixed.
- No Section 2 guardrail is violated, and this file is marked Implemented.
