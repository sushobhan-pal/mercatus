# `groundwork` — Decision File

**Status:** Draft, awaiting approval (2026-10-23)
**Phase / position:** Phase 0, module 0 in ROAD-3
**Branch:** `groundwork`
**Depends on:** nothing
**Implements master rules:** ROAD-4, ROAD-5 tickets 1–4, TEST-4, ARCH-3 to ARCH-6, ARCH-13 (tooling only), DRY-1 (OpenAPI generation), and the Phase 0 items of ROAD-3

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

## 5. Events and hooks
None.

## 6. UI
None. `@wordpress/scripts` is installed and configured (GW-4) so `core.vendor` can build the dashboard shells.

## 7. Extension seams
None beyond the contracts (GW-7).

## 8. Tests
The three cross-cutting tests from TEST-3: `DependencyRulesTest` (Layer 1), `OpenApiSchemaConformanceTest` (Layer 3) and `BaselineQueryAndLatencyGateTest` (Layer 6, created as a skeleton and grown by each module). Each Phase 0 contract gets its conformance trait (Layer 3), exercised later by the modules that implement it.

## 9. Scale
The seeder command skeleton (`wp mercatus seed:scale`) and the nightly dump pipeline are ROAD-5 ticket 9, built in Phase 1 with `core.kernel`. Groundwork only reserves the CI job.

## 10. Decisions
- **GW-1 Repository layout.** Each repository's root is the plugin root. In `mercatus`, the master document, `decisions/`, `archive/` and `docs/` (ADRs, threat model) live in the repository and are excluded from the distributed zip (GW-10). Local clones use the repo-local git identity `sushobhan-pal <sushobhan.pal@gmail.com>` and SSH remotes.
- **GW-2 License.** The `mercatus` repository's `LICENSE` is GPL-3.0; the plugin header says `License: GPL-3.0-or-later` with the matching `License URI`. `mercatus-pro` follows OQ-3.
- **GW-3 PHP tooling.**
  - Composer with PHP platform 8.2.
  - PHPUnit at the highest major version the WordPress integration test bootstrap supports when the scaffold is built, so the unit and integration suites share one version.
  - PHPStan level 8 with WordPress and WooCommerce stubs.
  - PHP_CodeSniffer with WPCS 3 (WordPress-Extra without the file-name and naming sniffs, ARCH-5) plus PHPCompatibilityWP set to PHP 8.2+.
  - Deptrac with layer rules per ARCH-2.
  - Strauss for prefixing third-party libraries (ARCH-4 allows Strauss or PHP-Scoper; Strauss is chosen because it rewrites Composer autoloading for WordPress plugins with little setup).
- **GW-4 JS tooling.** Node 22 LTS, pinned in `.nvmrc`. `@wordpress/scripts` handles build, lint, Jest and Playwright (with `@wordpress/e2e-test-utils-playwright`), per ARCH-13.
- **GW-5 Test environment (TEST-4).** `compose.yaml` defines:
  - a WordPress service whose PHP version is a build argument;
  - one database profile per supported database: `mysql-8.0`, `mysql-8.4`, `mariadb-10.6`, `mariadb-10.11`, `mariadb-11.4`;
  - cache profiles `redis` and `memcached` (none means neither profile), with the matching object-cache drop-in installed (OQ-1);
  - a WP-CLI service that installs the WordPress and WooCommerce versions under test.

  Test commands run inside the WordPress service, so a CI combination is reproduced locally with one `docker compose --profile … up`.
- **GW-6 CI matrix (GitHub Actions).** The master document fixes the database × cache matrix but not PHP versions, so CI also covers PHP 8.2 (the minimum) and the latest stable PHP.
  - **Every pull request:** PHPCS, PHPStan, Deptrac, PHP unit tests and Jest, plus two integration legs:
    - *minimum:* PHP 8.2, WordPress 6.6, WooCommerce 9.2, MySQL 8.0, no persistent cache;
    - *latest:* latest PHP, latest WordPress and WooCommerce, MariaDB 11.4, Redis.
  - **Push to `main`, plus nightly:** the full 5 databases × 3 cache modes on latest PHP, WordPress and WooCommerce; the minimum leg; Playwright end-to-end tests.
  - **Nightly only:** the scale suite (PLAT-10).

  Pull requests run 2 integration legs instead of 15 because Actions minutes on private repositories are limited by the account's plan.
- **GW-7 Contracts.** The ten Phase 0 contracts (`ModuleManifest`, `CachePort`, `CoordinationPort`, `TaxRateResolver`, `StoreTaxPolicy`, `CommissionRuleSource`, `OrderCompletionPolicy`, `ChildOrderExposure`, `PayoutProvider`, `CatalogSearchPort`) are written as PHP interfaces in `src/Contracts/`, with method signatures, documented semantics and a conformance-test trait each. They are frozen at the Phase 0 exit; after that they change only under ARCH-6's versioning rules.
- **GW-8 OpenAPI generation.** A WP-CLI command, `wp mercatus openapi:export`, builds `openapi/mercatus-v1.yaml` from the registered routes' schemas. CI regenerates the file and fails if it differs from the committed one (DRY-1).
- **GW-9 ADR and threat model.** `docs/architecture/decisions/0001-api-first-modular-monolith.md` records the core architecture choice. `docs/security/threat-model.md` lists STRIDE and OWASP API Top 10 threats per actor and surface, each mapped to a test in `tests/Security/`.
- **GW-10 Build and distribution.** A build script runs `npm run build`, then `composer install --no-dev` with Strauss, then `wp dist-archive`, which honours `.distignore`. The zip excludes tests, docs, decisions, archive, `compose.yaml`, `.github/` and other development files.
- **GW-11 `mercatus-pro` scaffold.** Same tooling as GW-3 to GW-5. Its Composer setup resolves `Mercatus\Core\Contracts\*` from the `mercatus` repository for static analysis and tests only, never shipping it (ARCH-4).

## 11. Open questions
- **OQ-1 Memcached drop-in.** Which object-cache drop-in backs the `memcached` profile? *Recommendation:* a maintained drop-in built on the `memcached` PECL extension, chosen while building `compose.yaml` and recorded here before approval of the CI work.
- **OQ-2 Branch protection.** ROAD-4 says `main` is protected, but GitHub only offers branch protection on private repositories with a paid plan (GitHub Pro or above). *Recommendation:* if the account has it, require a pull request and the PR CI checks on `main`. If not, keep the rule by convention until the repository goes public or the plan changes.
- **OQ-3 Pro license.** *Recommendation:* GPL-3.0, like Core. WordPress plugins are GPL-derived, and one license keeps both packages consistent.
- **OQ-4 Latest PHP.** *Recommendation:* "latest stable PHP" means the newest PHP release that WordPress supports at scaffold time (currently 8.5), re-checked whenever the CI matrix is touched.

## 12. Done when
- Both repositories have `main` with the scaffold; CI is green on a pull request, on `main` and nightly.
- The Phase 0 exit gate passes: the contracts and generated OpenAPI pass lint and schema validation (ROAD-3).
- ADR 0001 and the threat model are merged.
- OPEN-1 is closed (via its spike, recorded in TAX-12 and DATA-2).
- This file is marked Implemented.
