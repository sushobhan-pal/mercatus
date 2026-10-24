# `core.vendor` — Decision File

**Status:** Draft
**Phase / position:** Phase 2, module 3 in ROAD-3
**Branch:** `core.vendor`
**Depends on:** `core.identity` (module 2)
**Implements master rules:** ROAD-3 row 3, SCOPE-2 (vendor application and approval), SEC-2 and SEC-4 (vendor resources), SEC-6 and SEC-7 (TM-30, TM-33), SEC-8, ARCH-6 and ARCH-8 points 3, 5 and 7, ARCH-12 (emails through `WC_Email`), ARCH-13 (the dashboard shell), API-2 (vendor applications), DATA-2 (`vendors`), DRY-2 (the state machine), TEST-1 Layer 7, TEST-3 row 5 (the vendor half)

Rules for this file (P-4, DRY-4): cite master IDs instead of restating them, never contradict the master document, and give each decision made here a local ID using the module's short code (for example `KER-1`). If this module needs a master rule changed, the master document changes first.

Local IDs use the code `VEN`.

---

## 1. Scope
- **In:**
  - The `vendors` table and the vendor lifecycle on the shared state machine (DRY-2), which this module builds as its first user.
  - Vendor applications: submit, read, list, approve and reject (API-2), with an automatic-approval setting (P-1).
  - Suspending and reinstating a vendor, which bumps their identity at once (SEC-2, G-CACHE-4, TM-8).
  - The `vendor_owner` claim source (IDN-3), the resource-policy interface (IDN-14), and the `/me` section that tells an applicant or vendor where they stand (IDN-12).
  - The first emails, through `WC_Email` (ARCH-12): application received, approved and rejected; vendor suspended and reinstated.
  - The marketplace dashboard shell (ARCH-13):
    - the page it lives on, its Content-Security-Policy (TM-28), and the menu from `/me`;
    - the screens for this module (applications, vendors, the applicant's own status);
    - the screens earlier modules left for it: Modules (KER-14), Settings (`core.kernel` §6), API clients and managers (`core.identity` §6).
  - The endpoints those screens need beyond API-2 (OQ-6, OQ-7).
  - Personal-data export and erasure for vendor data (SEC-7, TM-33).
  - The first end-to-end specs and the Playwright CI job (TEST-1 Layer 7, GW-6).
  - The vendor seeder step and its query ceilings (PLAT-10).
  - Retiring the test-only `pro.testactors` module (IDN-20) once real vendor owners exist.
- **Out:**
  - **`core.store`:** the store profile, its slug, visibility and default location, `/stores`, and the store summary in `/me`. The application's requested store name is handed to it on approval (VEN-5).
  - **Pro:** vendor staff (IDN-16), Verification/KYC and its documents (OQ-4), groups and membership plans that narrow a manager's scope (IDN-15).
  - **Later modules:** every other dashboard screen arrives with its module.
- **Increments (ROAD-1):** ROAD-5's tickets stop at 11, so this module's are its own:
  1. **Vendors and applications:** the table, the lifecycle, the claim source, the application endpoints, suspension, the events and the emails.
  2. **The dashboard shell:** the page, the React app with its menu and screens, the extra endpoints (OQ-6), CSP, and the first Playwright specs.
  3. **Privacy and scale:** export and erasure, the seeder step and its ceilings, and retiring `pro.testactors`.

## 2. Data
- **`vendors` (DATA-2):** one row per WordPress user who applied, created by this module's first migration. Keys as DATA-2.
  - Columns: `user_id`, `status`, `store_name` (the name requested at application), `application` (the applicant's answers as JSON, OQ-3), `reason` (a rejection or suspension reason), `decided_by`, `decided_at`, `created_at`, `updated_at` and `version`.
  - Whether a separate applications table is needed is OQ-1.
- **The dashboard page:** a WordPress page, recorded in a setting (OQ-5).
- **No WordPress role or capability changes.** An approved vendor keeps their WordPress role and gains no WooCommerce capability (SEC-8, G-SEC-7, VEN-6).
- **Seeder slice:** the PLAT-10 count of vendors (2,000 at full scale): users and approved vendor rows, created through this module's commands.

## 3. Settings
Changed by an administrator only:
- `core.vendor.auto_approve`: approve applications automatically. Default: off (VEN-3).
- `core.vendor.terms_page`: the page applicants must accept, if any. Default: none.
- `core.vendor.application_fields`: the extra questions on the application form (OQ-3). Default: none.
- `core.vendor.dashboard_page`: the page the dashboard lives on (OQ-5). Set on activation.

## 4. API
Routes follow API-1 through the base REST controller and the route pipeline (IDN-21).
- `POST /vendor-applications`: `authenticated` (OQ-2), with a tighter per-route rate limit (IDN-19, TM-37). Accepts the requested store name, the standard fields and the answers to the extra questions. A user with a pending or active application gets 409.
- `GET /vendor-applications/{id}`: the applicant, or an administrator or manager with `vendors.applications.review`. Anyone else gets 404 (SEC-4).
- `POST /vendor-applications/{id}/approve` and `/reject`: `admin` with `vendors.applications.review`. A rejection needs a reason, which the applicant's email carries. Repeating either is harmless.
- **If OQ-6 and OQ-7 are accepted, master v2.9 adds to API-2:**
  - `GET /vendor-applications`: the review queue, with cursors and a status filter.
  - `GET /vendors`, plus `POST /vendors/{id}/suspend` and `/reinstate`: `admin` with `vendors.manage`.
  - `GET /settings/{module}` and `PATCH /settings/{module}` (`If-Match`): administrators. A change emits `kernel.settings.updated`, which `core.kernel` §5 promised and nothing emits yet.
  - `GET /managers`, `POST /managers` and `POST /managers/{user_id}/remove`: administrators (IDN-15).
- **Idempotency:** no route here changes money or stock, so none requires `Idempotency-Key`.

## 5. Events and hooks
- **Events emitted (KER-8):**
  - `vendor.application.submitted`, `vendor.application.approved` and `vendor.application.rejected`;
  - `vendor.account.suspended` and `vendor.account.reinstated`;
  - `kernel.settings.updated` and `identity.manager.permissions_changed`, from the settings endpoints (OQ-6).
- **Audit entries:** each event above.
- **Events consumed:** this module's own, to send the emails (VEN-7).
- **WordPress hooks used:**
  - `deleted_user`, to close the vendor row;
  - `template_include`, to render the dashboard page without the theme (OQ-5);
  - `wp_privacy_personal_data_exporters` and `wp_privacy_personal_data_erasers` (VEN-9);
  - `woocommerce_email_classes`, to register the emails.
- **Filters:** none of its own. ARCH-8 point 6 names no vendor decision point.

## 6. UI
- **The shell (ARCH-13):** one full-screen React app built with `@wordpress/scripts`, `@wordpress/components` and `@wordpress/data`, in `ui/`. It loads the actor, permissions, features and menu from `/me` in one call (ARCH-9) and draws only what the menu contains. A hidden item is never the security boundary.
- **Screens in this module:**
  - **Applications** (review queue, approve, reject with a reason);
  - **Vendors** (list, suspend, reinstate);
  - **Apply** and **My application** (applicant, OQ-2, OQ-3);
  - **Settings**: forms generated from the settings registry's definitions, grouped by module;
  - **Modules** (KER-14), **API clients** (IDN-18) and **Managers** (IDN-15).
- **States:** every screen has a loading skeleton, an empty state that says what to do next, and an error state that shows the API-1 message and the request ID, so support can find it.
- **wp-admin:** one menu link that opens the dashboard. Vendors never enter wp-admin; WooCommerce already sends users without `edit_posts` to My Account, and VEN-6 keeps vendors without capabilities.
- **Language:** the app sends `_locale=user` (IDN-21), and its strings load through `wp.i18n`.

## 7. Extension seams
- **Lifecycle:** the shared state machine lets Pro add vendor states (for example verification) without changing the transitions Core defines.
- **Application questions:** `core.vendor.application_fields` holds the admin's questions; Pro Memberships can show different questions per plan, as WCFM does.
- **`/me` sections:** this module ships the contract and the first section, `vendor`; `core.store` adds the store summary.
- **Resource policies:** the `ResourcePolicy` interface, with `VendorPolicy` as its first implementation on `PolicyEngine::vendorResource()`.
- **Dashboard screens:** each module declares its menu items (ARCH-8 point 7) and ships its screens as entry points the shell loads when their item is opened.

## 8. Tests
- **TEST-3 row 5, the vendor half:** `VendorLifecycleStateMachineTest` and `VendorApplicationWorkflowTest`. `core.store` owns the rest of the row.
- **Added by the threat model:**
  - `DashboardContentSecurityPolicyTest` (TM-28): the dashboard page sends a CSP that allows only Mercatus's own scripts, with nonces for inline data.
  - `PersonalDataExportEraseTest` (TM-33): WordPress's exporter and eraser cover vendor data (VEN-9).
  - `SignedPrivateFileUrlTest` (TM-30): built here only if applications accept files; otherwise its owner moves to Pro Verification/KYC (OQ-4).
- **Identity, now with real vendors:** `CachedActorIdentityInvalidationTest` and `IdorActorMatrixTest` use real owners instead of `pro.testactors`. Suspending a vendor bumps their identity in the same request.
- **Extra:**
  - `StateMachineTest` (shared kernel);
  - `VendorClaimSourceTest`;
  - `VendorApplicationEndpointContractTest`;
  - `ApplicationEmailsTest`, proving each event sends its `WC_Email` once.
- **JS (Vitest):** the shell's menu, routing, and empty, loading and error states.
- **End to end (Playwright, TEST-1 Layer 7):** a customer applies, an administrator approves, and the new vendor sees their dashboard menu; a visitor and a customer can't reach the review queue. The Playwright CI job arrives with these specs (GW-6).
- **Threat model:**

  | Threat | Tests |
  |---|---|
  | TM-8 | `CachedActorIdentityInvalidationTest`, `VendorApplicationWorkflowTest` |
  | TM-28 | `DashboardContentSecurityPolicyTest` |
  | TM-30 | `SignedPrivateFileUrlTest` (OQ-4) |
  | TM-33 | `PersonalDataExportEraseTest` |
  | TM-37 | `RateLimiterTest` (the application route's own limit) |

## 9. Scale
- **Seeder slice:** `database/seeders/steps/core.vendor.php` creates the PLAT-10 vendors as approved, deterministically (KER-12).
- **Target ceilings (Mercatus's own queries), fixed during implementation:**
  - A cold identity with the vendor claim source: 1 indexed query (`vendors.user_id`).
  - A warm identity with a persistent cache: 0.
  - `GET /vendor-applications` and `GET /vendors`, one page: 1 query, with no per-row queries or `COUNT`.
  - Approving an application: the transaction's writes plus the critical event's handling, fixed and independent of the vendor count.

## 10. Decisions
- **VEN-1 The lifecycle.**
  - States: `pending`, `active`, `rejected`, `suspended` and `closed`.
  - Transitions: `pending` to `active` or `rejected`; `active` to `suspended` and back; any state to `closed` when the WordPress user is deleted.
  - Every transition is a command that publishes its event and writes its audit entry in one transaction (API-3).
  - Re-applying after a rejection is OQ-1.
- **VEN-2 The shared state machine (DRY-2).** A small, immutable definition of states and allowed transitions, checked before any write. It names its own error code for a refused transition (`mercatus_transition_not_allowed`, 409). Later lifecycles (vendor orders, withdrawals) reuse it.
- **VEN-3 Approval.**
  - Administrators, and managers holding `vendors.applications.review`, decide applications.
  - With `core.vendor.auto_approve` on, a valid application becomes `active` at once, and the approval is audited as the system's.
  - A manager's vendor scope applies (IDN-15); in Free it's all vendors.
- **VEN-4 The claim source.** The source reads the user's `vendors` row by `user_id`, one indexed query on a cold identity. It claims `vendor_owner` for an `active` vendor only. A `suspended`, `pending` or `rejected` user is a `customer`, so tenant routes refuse them (IDN-4).
- **VEN-5 Store hand-off.** On approval, the event carries the requested store name. `core.store` creates the store from it when it's built; until then, the name stays on the vendor row.
- **VEN-6 No WordPress roles or capabilities.** Approval changes no WordPress role and grants no capability (SEC-8). Being a vendor is the identity's claim, never a WordPress capability.
- **VEN-7 Emails (ARCH-12).**
  - Each email is a `WC_Email` class, so administrators edit its text and switch it off in WooCommerce's email settings.
  - Handlers on the `critical` lane send them after commit (API-3); the poller retries a failure.
  - The applicant's emails go to their WordPress email. The application-received email goes to the store's admin email.
- **VEN-8 Permissions.** `vendors.applications.review` covers the queue, approving and rejecting; `vendors.manage` covers the vendor list, suspending and reinstating. Settings and managers stay with administrators.
- **VEN-9 Personal data (SEC-7).**
  - The exporter returns the vendor row and the application's answers.
  - The eraser anonymises the answers, contact details and reasons of `rejected` and `closed` vendors. For `pending`, `active` and `suspended` vendors it reports the data as retained, with the reason, because orders and money still refer to them.
  - The audit log holds no free text (SEC-7), so nothing there needs erasing.
- **VEN-10 Dashboard security.** The dashboard page sends `Content-Security-Policy`: scripts from the site itself only, inline data through per-request nonces (`wp_inline_script_attributes`), no `unsafe-eval`, and `frame-ancestors 'self'`. Vendor content is escaped on output (SEC-7). Other plugins' scripts aren't loaded on the page.

## 11. Open questions
- **OQ-1 Applications and re-application.** DATA-2 lists `vendors` but no applications table, while API-2 names `/vendor-applications`.
  - **(a, recommended)** One `vendors` row per user, created by the application with status `pending`. `/vendor-applications/{id}` addresses that row, and the answers live in its `application` column. A rejected applicant may apply again, which returns the same row to `pending`. The audit log keeps the history. No master change.
  - **(b)** A separate `vendor_applications` table, one row per attempt, which approval turns into a `vendors` row. This keeps every attempt's answers, but needs a DATA-2 change and two tables in step.
  - **(c)** As (a), but a rejection is final unless an administrator reopens it.
- **OQ-2 Who can apply.**
  - **(a, recommended)** Signed-in users only. A visitor first creates an account through WooCommerce's My Account registration, and the Apply screen links there. No Mercatus endpoint creates accounts or handles passwords, and the route's own rate limit throttles spam (TM-37).
  - **(b)** Visitors apply and Mercatus creates the account. Applying is one step, but Mercatus would then handle passwords, email verification and anonymous spam.
- **OQ-3 The application form.**
  - **(a, recommended)** Fixed fields: the store name, a contact phone, a message, and acceptance of the terms page when one is set. On top of those, administrators define extra questions (text, long text, choice, checkbox, each optionally required) in `core.vendor.application_fields`, like WCFM's registration fields.
  - **(b)** Fixed fields only in v1, with the extra questions as a later seam.
- **OQ-4 Documents with applications.** The threat model makes this module the owner of `SignedPrivateFileUrlTest` (TM-30), but SCOPE-2 puts Verification/KYC in Pro.
  - **(a, recommended)** Free applications take no files. The test's owner moves to Pro Verification/KYC, an edit to the threat model in this module's branch.
  - **(b)** Applications may ask for documents. This module then builds storage outside the web root and short-lived signed URLs (SEC-6) for them.
- **OQ-5 Where the dashboard lives.**
  - **(a, recommended)** On activation Mercatus creates a "Marketplace dashboard" page, with the slug `dashboard`, and records it in `core.vendor.dashboard_page`; the administrator can choose another page. On that page Mercatus renders its own minimal document instead of the theme, so the dashboard is full-screen and only Mercatus's scripts load (VEN-10).
  - **(b)** A virtual route such as `/marketplace/`, with no page to manage. Some hosts and SEO plugins handle virtual routes poorly.
  - **(c)** A block inside the theme's normal layout. It looks like the site, but isn't full-screen, and theme and plugin scripts share the page (weaker CSP).
- **OQ-6 Endpoints the dashboard needs beyond API-2.** The review queue, the settings forms and the managers screen have no endpoints in API-2.
  - **(a, recommended)** Master v2.9 adds `GET /vendor-applications`, `GET`/`PATCH /settings/{module}` and the `/managers` endpoints (§4). The settings endpoint finally emits `kernel.settings.updated`.
  - **(b)** Only the review queue now; settings and managers stay WP-CLI-only until a later module.
- **OQ-7 Where suspension lives.** API-2 puts `POST /stores/{id}/suspend` under Stores (`core.store`), but suspension changes the vendor's lifecycle and identity (TM-8).
  - **(a, recommended)** Suspension is a vendor action: `GET /vendors` and `POST /vendors/{id}/suspend` and `/reinstate` come here, and master v2.9 moves the suspend endpoint from Stores to Vendors. A suspended vendor's store goes hidden when `core.store` exists.
  - **(b)** Keep the master as it is. The lifecycle is built here without an endpoint, and suspending arrives with `core.store`.

## 12. Done when
- Any master changes the answers need (v2.9) are merged.
- `VendorLifecycleStateMachineTest`, `VendorApplicationWorkflowTest`, `DashboardContentSecurityPolicyTest` and `PersonalDataExportEraseTest` pass in CI with and without a persistent cache (ROAD-2 step 9). So does `SignedPrivateFileUrlTest`, unless OQ-4 moves it.
- The Playwright job runs the first end-to-end specs in CI, and the shell's Vitest tests pass.
- `openapi/mercatus-v1.yaml` includes this module's routes, and Redocly passes.
- The scale gate has the vendor scenarios with their ceilings fixed, and `pro.testactors` is gone.
- No Section 2 guardrail is violated, and this file is marked Implemented.
