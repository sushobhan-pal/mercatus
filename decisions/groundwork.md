# `groundwork` — Decision File

**Status:** Implemented (2026-10-23)
**Phase / position:** Phase 0, module 0 in ROAD-3
**Branch:** `groundwork`
**Depends on:** nothing
**Implements master rules:** ROAD-4, ROAD-5 tickets 1–5 (5 is the OPEN-1 spike, ROAD-3 0′), TEST-4, ARCH-3 to ARCH-6, ARCH-13 (tooling only), DRY-1 (OpenAPI generation), and the Phase 0 items of ROAD-3

Local IDs use the code `GW`.

---

## 1. Scope
- **In:** both repositories (ROAD-4); scaffold and tooling for `mercatus` and `mercatus-pro`; the Docker Compose test environment and CI (TEST-4); the Phase 0 contracts as PHP interfaces with conformance traits; OpenAPI generation; ADR 0001; the threat model; the decision-file template (`decisions/_template.md`, already written).
- **Out:** any runtime feature. Kernel boot, settings, migrations and caching are `core.kernel` (module 1). The OPEN-1 spike runs alongside Groundwork in a throwaway plugin, but its outcome is recorded in TAX-12 and DATA-2, not here.

## 2. Data
None. Groundwork creates no tables.

## 3. Settings
None.

## 4. API
No routes. The OpenAPI generator (GW-8) runs against an empty route set until `core.kernel` registers `/features` and `/health`.

Every endpoint registered under `mercatus/v1` carries route metadata next to its methods and callbacks: `'mercatus' => array( 'access' => …, 'resource' => …, 'summary' => … )`. `access` is required and one of `public`, `authenticated`, `tenant` (a resource that belongs to one vendor) or `admin`. The OpenAPI generator and the security matrix both read it through one route reader (`tests/Support/Routes/`), and an endpoint without it fails both. `core.kernel`'s route registrar will own the convention (ARCH-8) and `core.identity` will add named permissions and the remaining SEC-1 actors.

## 5. Events and hooks
None.

## 6. UI
None. `@wordpress/scripts` is installed and configured (GW-4) so `core.vendor` can build the marketplace dashboard shell (ARCH-13).

## 7. Extension seams
None beyond the contracts (GW-7).

## 8. Tests
The three cross-cutting tests from TEST-3: `DependencyRulesTest` (Layer 1), `OpenApiSchemaConformanceTest` (Layer 3) and `BaselineQueryAndLatencyGateTest` (Layer 6), which arrives with the scale seeder in ROAD-5 ticket 9 and then grows with each module. Each Phase 0 contract gets its conformance trait (Layer 3), exercised later by the modules that implement it.

PHPUnit suites: `phpunit.xml.dist` runs the WordPress-free suites `unit`, `architecture`, `contract` and `security`; `phpunit-integration.xml.dist` runs `tests/Integration/` and `OpenApiSchemaConformanceTest` in the Docker environment (GW-5). `tests/Security/PolicyMatrixDataProvider.php` turns each route's access level into denial cases (anonymous → 401; inside one's own tenant → 403; another vendor's resource → 404, SEC-4) for the matrix tests that `core.identity` adds. `ThreatModelCoverageTest` (in the `security` suite) checks the threat model against TEST-3, the master's rule IDs and the module decision files (GW-9).

## 9. Scale
The seeder command skeleton (`wp mercatus seed:scale`) and the nightly dump pipeline are ROAD-5 ticket 9, built in Phase 1 with `core.kernel`; the nightly scale CI job arrives with it (GW-6).

## 10. Decisions
- **GW-1 Repository layout.** Each repository's root is the plugin root. In `mercatus`, the master document, `decisions/`, `archive/` and `docs/` (ADRs, threat model) live in the repository and are excluded from the distributed zip (GW-10). Local clones use the repo-local git identity `sushobhan-pal <sushobhan.pal@gmail.com>` and SSH remotes.
- **GW-2 License.** The `mercatus` repository's `LICENSE` is GPL-3.0; the plugin header says `License: GPL-3.0-or-later` with the matching `License URI`. `mercatus-pro` uses the same license, header and GPL-3.0 `LICENSE` file.
- **GW-3 PHP tooling.**
  - Composer with PHP platform 8.2.
  - PHPUnit at the highest major version the WordPress integration test bootstrap supports when the scaffold is built, so the unit and integration suites share one version.
  - PHPStan level 8 with WordPress and WooCommerce stubs.
  - PHP_CodeSniffer with WPCS 3 (WordPress-Extra without the file-name and naming sniffs, ARCH-5) plus PHPCompatibilityWP set to PHP 8.2+.
  - Deptrac with layer rules per ARCH-2.
  - Strauss for prefixing third-party libraries (ARCH-4 allows Strauss or PHP-Scoper; Strauss is chosen because it rewrites Composer autoloading for WordPress plugins with little setup).
- **GW-4 JS tooling.** Node 22 LTS, pinned in `.nvmrc`. `@wordpress/scripts` handles build, lint, Vitest unit tests (Vitest 5 with Vite 8, the runner it ships by default) and Playwright (with `@wordpress/e2e-test-utils-playwright`), per ARCH-13 and master v2.3.
- **GW-5 Test environment (TEST-4).** `compose.yaml` defines:
  - a WordPress service whose PHP version is a build argument;
  - one database profile per supported database: `mysql-8.0`, `mysql-8.4`, `mariadb-10.6`, `mariadb-10.11`, `mariadb-11.4`;
  - cache profiles `redis` and `memcached` (none means neither profile). `redis` installs the Redis Object Cache drop-in; `memcached` installs Automattic's `wp-memcached` drop-in with the PECL `memcache` extension (on PHP versions that extension builds for; elsewhere the profile stops with a clear message), loaded through a test-only wrapper that adds `wp_cache_flush_runtime()` (the WordPress test suite calls it between tests and `wp-memcached` 4.0.0 doesn't define it);
  - a WP-CLI service that installs the WordPress and WooCommerce versions under test.

  Test commands run inside the WordPress service, so a CI combination is reproduced locally with one `docker compose --profile … up`.
- **GW-6 CI matrix (GitHub Actions).** The master document fixes the database × cache matrix but not PHP versions, so CI also covers PHP 8.2 (the minimum) and "latest PHP": the newest version that both WordPress and WooCommerce officially support. A non-blocking job also runs the next PHP version, so problems show up early without failing pull requests.
  - **Every pull request:** PHPCS, PHPStan, Deptrac, PHP unit tests and Vitest, plus two integration legs:
    - *minimum:* PHP 8.2, WordPress 6.6, WooCommerce 9.2, MySQL 8.0, no persistent cache;
    - *latest:* latest PHP, latest WordPress and WooCommerce, MariaDB 11.4, Redis.
  - **Push to `main`, plus nightly:** the full 5 databases × 3 cache modes on latest PHP, WordPress and WooCommerce; the minimum leg; Playwright end-to-end tests.
  - **Nightly only:** the scale suite (PLAT-10).

  Pull requests run 2 integration legs instead of 15 because Actions minutes on private repositories are limited by the account's plan. The Playwright job arrives with the first end-to-end specs (`core.vendor`) and the scale job with ROAD-5 ticket 9; until then there is nothing for them to run.
- **GW-7 Contracts.** The ten Phase 0 contracts (`ModuleManifest`, `CachePort`, `CoordinationPort`, `TaxRateResolver`, `StoreTaxPolicy`, `CommissionRuleSource`, `OrderCompletionPolicy`, `ChildOrderExposure`, `PayoutProvider`, `CatalogSearchPort`) are written in `src/Contracts/`, with method signatures and documented semantics. They are frozen at the Phase 0 exit; after that they change only under ARCH-6's versioning rules.
  - `ModuleManifest` is a value object that checks its own shape when constructed (unit-tested); the other nine are interfaces. `CompletionTrigger` is added as the interface ORD-12's triggers implement, so the contracts hold ten interfaces.
  - Each interface has a conformance trait in `tests/Contract/Conformance/`. A test class uses the trait and implements its factory methods; test names carry the contract name, so one class can use several traits. In-memory reference fakes in `tests/Contract/Fakes/` pass every trait and double as test doubles for modules. A fake may be deleted once a module ships a real pure implementation that passes the same trait.
  - Inputs and outputs are immutable value objects that refuse invalid states (for example a tax line in another currency, a commission rule with no basis, a payout of zero). Money is integer minor units: `Money` and `Currency` in `src/Shared/Money/` are the first shared-kernel pieces (DRY-2).
  - Semantics settled here: `CachePort` never stores null and refuses TTLs below 1, `revision()` seeds from the clock (PLAT-5) and `flushGroup()` bumps the group's revision; `CoordinationPort` answers null or false when unavailable (PLAT-6); `OrderCompletionPolicy` returns the first enabled trigger that fires and never completes an unpaid vendor order, while timer triggers pause during an open refund or return (ORD-12); `PayoutProvider::send()` is idempotent per key, returns a decline as a Failed result and throws only when the provider can't be reached; `ChildOrderExposure` hides child orders in the three version 1 contexts and leaves other orders visible (ORD-7).
- **GW-8 OpenAPI generation.** `OpenApiSchemaConformanceTest` (TEST-3, Layer 3) is a snapshot test. It boots WordPress in the integration environment, builds an OpenAPI 3.1 document from the registered `mercatus/v1` routes (their argument schemas, response item schemas and route metadata, §4), and fails if the result differs from the committed `openapi/mercatus-v1.yaml` (DRY-1). `composer openapi:update` runs the same test with `MERCATUS_UPDATE_OPENAPI=1`, which rewrites the file first. The generator is test tooling under `tests/`, so nothing extra ships in the plugin. Collections (routes with a `cursor` argument) get the API-1 envelope; 4XX and 5XX responses get the API-1 error body; non-public routes declare the cookie-plus-nonce and application-password schemes. CI lints the file with Redocly (`npm run lint:openapi`, `recommended` rules), which is the Phase 0 exit check for the generated OpenAPI (ROAD-3).
- **GW-9 ADR and threat model.** `docs/architecture/decisions/0001-api-first-modular-monolith.md` records the core architecture choice. `docs/security/threat-model.md` lists STRIDE and OWASP API Top 10 threats per actor and surface, each mapped to the tests that prove its mitigation: TEST-3 tests (mostly the Layer 5 tests in `tests/Security/`), or tests the model adds where TEST-3 has a gap, each assigned to the module whose decision file must adopt it. `tests/Security/ThreatModelCoverageTest.php` keeps the mapping true (unknown tests, undefined rule IDs, unadopted tests, and actors, surfaces, STRIDE categories or OWASP items without a threat all fail).
- **GW-10 Build and distribution.** A build script runs `npm run build`, then `composer install --no-dev` with Strauss, then `wp dist-archive`, which honours `.distignore`. The zip excludes tests, docs, decisions, archive, `compose.yaml`, `.github/` and other development files.
- **GW-11 `mercatus-pro` scaffold.** Same tooling as GW-3 to GW-5. Its Composer setup resolves Core from the `mercatus` repository (a path repository at `../mercatus`) for static analysis and tests only, never shipping it (ARCH-4). Pro code may use `Mercatus\Core\Contracts\*` and the shared kernel `Mercatus\Core\Shared\*` (master v2.4); Deptrac forbids everything else in Core. Pro's tests reuse Core's test support through `autoload-dev`: the architecture scanner, the conformance traits and the fakes. Pro's CI checks Core out next to Pro (the branch with the same name if it exists, otherwise `main`) using the repository secret `MERCATUS_READ_TOKEN`, a fine-grained token with read-only Contents access to `sushobhan-pal/mercatus`.
- **GW-12 Branch protection by convention.** The account is on GitHub Free, which offers no branch protection on private repositories. ROAD-4's protected `main` is therefore kept by convention: no direct pushes, and changes reach `main` only through pull requests with green CI. Technical protection is switched on when the free plugin's repository goes public or the plan changes.

## 11. Open questions
None.

## 12. Done when
- Both repositories have `main` with the scaffold; CI is green on a pull request, on `main` and nightly.
- The Phase 0 exit gate passes: the contracts and generated OpenAPI pass lint and schema validation (ROAD-3).
- ADR 0001 and the threat model are merged.
- OPEN-1 is closed (via its spike, recorded in TAX-12 and DATA-2).
- This file is marked Implemented.
