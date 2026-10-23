# `<module-id>` — Decision File

**Status:** Draft · Approved (date) · Implemented (date)
**Phase / position:** Phase N, module # in ROAD-3
**Branch:** `<module-id>`
**Depends on:** modules this one needs (ROAD-3)
**Implements master rules:** the master-document IDs this module delivers

Rules for this file (P-4, DRY-4): cite master IDs instead of restating them, never contradict the master document, and give each decision made here a local ID using the module's short code (for example `KER-1`). If this module needs a master rule changed, the master document changes first.

---

## 1. Scope
- **In:** what this module delivers.
- **Out:** what it explicitly leaves to other modules or later versions.

## 2. Data
Tables, columns and indexes this module owns or changes (cite DATA-2 / DATA-3 rows), migrations, and seeder slice.

## 3. Settings
Typed settings-registry keys, their defaults and who may change them (P-1).

## 4. API
Routes, the policy guarding each, idempotency and `If-Match` requirements (PLAT-7, PLAT-9), and pagination.

## 5. Events and hooks
Domain events emitted and consumed, and any documented filters at policy decision points (ARCH-8).

## 6. UI
Screens, which shell they mount in (ARCH-13), and empty, loading and error states.

## 7. Extension seams
Seams left for deferred features (P-2).

## 8. Tests
The module's TEST-3 row, plus any extra cases this module needs.

## 9. Scale
Seeder slice and the query ceilings that apply (PLAT-10).

## 10. Decisions
Local decisions, each with an ID (`<CODE>-n`).

## 11. Open questions
Each with a recommendation. A module is not approved while an open question blocks its implementation.

## 12. Done when
The module's exit criteria (ROAD-2 step 9), plus anything specific to this module.
