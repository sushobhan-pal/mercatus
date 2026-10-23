# ADR 0001: API-first modular monolith on WordPress and WooCommerce

**Status:** Accepted (2026-10-23)
**Deciders:** product owner (sushobhan-pal)
**Rules:** P-3, P-5, P-6, P-7, ARCH-1 to ARCH-6, ARCH-8, ARCH-9, ARCH-12, ARCH-13, DRY-1, DRY-2, PLAT-2, TEST-1

## Context

Mercatus is a multivendor marketplace for WooCommerce that has to serve many business models and countries (P-1), stay fast with hundreds of thousands of products with or without a persistent object cache (P-7, PLAT-2), and let a paid plugin, Mercatus Pro, add features without forking the free one. It starts clean, with no runtime tie to WCFM (P-5).

Three shapes were possible:

1. **A classic WordPress plugin:** hooks, admin pages and AJAX handlers that call WooCommerce and `$wpdb` directly. Quick to start, but business rules end up spread across controllers, every UI needs its own endpoints, and nothing stops Pro from depending on Core's internals.
2. **Separate services** (a marketplace API next to WordPress). Clean boundaries, but every site needs a second runtime, and checkout, tax and payment gateways would have to be rebuilt outside WooCommerce, against ARCH-12.
3. **A modular monolith inside WordPress, API-first.** One deployable plugin pair, with modules and layers enforced by tooling, and one REST API used by every client.

## Decision

Mercatus is an **API-first modular monolith** that runs inside WordPress and WooCommerce.

- **API-first (P-6).** Every business action is a permission-checked command or query, exposed through the versioned `mercatus/v1` REST API. The marketplace dashboard (ARCH-13), blocks and third-party clients use that same API. Server-side callers, such as blocks, WooCommerce hooks and CLI commands, call the same commands in process, never through HTTP loopback (ARCH-9).
- **Modules and layers (ARCH-1, ARCH-2).** Each module is `{Module}/{Domain,Application,Infrastructure,Api}`. Dependencies point inward: Domain and Application know nothing about WordPress, HTTP or `$wpdb`. Deptrac and `DependencyRulesTest` enforce this on every commit (TEST-1, Layer 1).
- **WooCommerce stays the commerce engine (ARCH-12).** Cart, checkout, payment gateways, product types and tax arithmetic remain WooCommerce's. Mercatus adds marketplace concerns around them, with HPOS mandatory.
- **One published surface for extensions (ARCH-4, ARCH-8).** Pro and third parties build on `Mercatus\Core\Contracts\*`: interfaces and value objects that follow semantic versioning and are tested by conformance traits. Pro may also use the shared kernel, `Mercatus\Core\Shared\*`, which is published and versioned the same way (master v2.4). Everything else in Core is internal.
- **One implementation per concern (P-3, DRY-1, DRY-2).** Money, IDs, cursors, cache keys and the base REST controller live once in the shared kernel. API shapes are defined once in PHP. The OpenAPI file is generated from the registered routes, and a snapshot test fails on drift (GW-8).
- **Optional infrastructure behind ports (PLAT-2).** Caching and cross-request coordination go through `CachePort` and `CoordinationPort`. Only their adapters know whether Redis or Memcached exists; correctness never depends on them.

## Consequences

- **Good:**
  - One install and one database transaction model.
  - WooCommerce's ecosystem (gateways, Subscriptions, Bookings, tax) keeps working.
  - The free and paid plugins share one API and one set of contracts.
  - Boundaries are checked by tools rather than by review alone.
- **Costs:**
  - More structure up front: a module needs ports, commands and contract tests before its first screen.
  - Contracts are frozen at the Phase 0 exit, and from then on they and the shared kernel change only under ARCH-6's deprecation rules.
- **Risks we accept:**
  - Running inside WordPress means sharing its request lifecycle and its plugins' hooks. Mercatus keeps those dependencies at the Infrastructure and Api edges and measures its own query and latency budget (PLAT-10).
- **Revisit if:**
  - a marketplace outgrows a single WordPress database and needs an external search engine or queue beyond the existing ports (`CatalogSearchPort`, the outbox in API-3); or
  - WooCommerce stops being viable as the checkout engine.
