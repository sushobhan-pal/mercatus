# `core.vendor` — Decision File

**Status:** Approved (2026-10-24)
**Phase / position:** Phase 2, module 3 in ROAD-3
**Branch:** `core.vendor`
**Depends on:** `core.identity` (module 2)
**Implements master rules:** ROAD-3 row 3, SCOPE-2 (vendor application and approval), SEC-2 and SEC-4 (vendor resources), SEC-7 (TM-33), SEC-8, ARCH-6 and ARCH-8 points 3, 5 and 7, ARCH-12 (accounts through WooCommerce, emails through `WC_Email`), ARCH-13 (the dashboard shell), API-2 (vendor applications), DATA-2 (`vendors`, and `vendor_applications` from master v2.9), DRY-2 (the state machine), PLAT-11 (multilingual), TEST-1 Layer 7, TEST-3 row 5 (the vendor half)

Rules for this file (P-4, DRY-4): cite master IDs instead of restating them, never contradict the master document, and give each decision made here a local ID using the module's short code (for example `KER-1`). If this module needs a master rule changed, the master document changes first.

Local IDs use the code `VEN`.

---

## 1. Scope
- **In:**
  - Vendor registration (VEN-12):
    - one endpoint where a visitor or a signed-in customer applies;
    - visitors get a WooCommerce customer account in the same step.
  - Vendor applications (VEN-11):
    - every attempt kept with its answers and its decision;
    - review, approval with an automatic-approval setting (P-1), and rejection with a reason.
  - The `vendors` table and the vendor lifecycle on the shared state machine (DRY-2), which this module builds as its first user.
  - Suspending and reinstating a vendor, which bumps their identity at once (SEC-2, G-CACHE-4, TM-8).
  - The `vendor_owner` claim source (IDN-3), the resource-policy interface (IDN-14), and the `/me` section that tells an applicant or vendor where they stand (IDN-12).
  - The first emails, through `WC_Email` (ARCH-12): application received, approved and rejected; vendor suspended and reinstated.
  - The marketplace dashboard shell (ARCH-13):
    - the page, its Content-Security-Policy (TM-28), and the menu from `/me`;
    - this module's screens;
    - the screens earlier modules left for it: Modules (KER-14), Settings (`core.kernel` §6), API clients and managers (`core.identity` §6).
  - The endpoints those screens need beyond API-2 (VEN-16, VEN-17).
  - Multilingual support for this module's pages, strings and emails (VEN-15).
  - Personal-data export and erasure for applicants and vendors (SEC-7, TM-33).
  - The first end-to-end specs and the Playwright CI job (TEST-1 Layer 7, GW-6).
  - The vendor seeder step and its query ceilings (PLAT-10).
  - Retiring the test-only `pro.testactors` module (IDN-20) once real vendor owners exist.
- **Out:**
  - **`core.store`:** the store profile, its slug, visibility and default location, `/stores`, and the store summary in `/me`. Approval hands it the requested store name (VEN-5).
  - **Pro:**
    - vendor staff (IDN-16);
    - Verification/KYC, whose documents link to the application attempt (VEN-14);
    - groups and membership plans that narrow a manager's scope (IDN-15).
  - **Later modules:** every other dashboard screen arrives with its module.
- **Increments (ROAD-1):** ROAD-5's tickets stop at 11, so this module's are its own:
  1. **Registration, applications and vendors:** the tables, the lifecycle, the claim source, the endpoints, suspension, the events and the emails.
  2. **The dashboard shell:** the page, the React app with its menu and screens, the settings and managers endpoints, CSP, multilingual support, and the first Playwright specs.
  3. **Privacy and scale:** export and erasure, the seeder step and its ceilings, and retiring `pro.testactors`.

## 2. Data
- **`vendor_applications` (master v2.9 adds it to DATA-2, VEN-11):** one row per application attempt.
  - Columns: `user_id`, `attempt` (1, 2, …), `status` (`pending`, `approved`, `rejected`, `withdrawn`), `store_name`, `answers`, `reason` (why it was rejected), `decided_by`, `decided_at`, `created_at`, `updated_at` and `version`.
  - `answers` is JSON: a snapshot of each question's ID, label, type and answer at submission, so later changes to the form never alter what an applicant answered.
  - Keys: PK `id`; UNIQUE `(user_id, attempt)`; INDEX `(status, created_at)` for the review queue.
- **`vendors` (DATA-2):** one row per approved vendor, created by its first approval (VEN-1).
  - Columns: `user_id`, `application_id` (the approved attempt), `status` (`active`, `suspended`, `closed`), `reason` (why it was suspended), `created_at`, `updated_at` and `version`.
  - Keys as DATA-2.
- **The dashboard page:** a WordPress page, recorded in a setting (VEN-13).
- **No WordPress role or capability changes.** An approved vendor keeps their WordPress role and gains no WooCommerce capability (SEC-8, G-SEC-7, VEN-6).
- **Seeder slice:** the PLAT-10 count of vendors (2,000 at full scale): users, approved applications and vendor rows, created through this module's commands.

## 3. Settings
Changed by an administrator only:
- `core.vendor.auto_approve`: approve applications automatically. Default: off (VEN-3).
- `core.vendor.terms_page`: the page applicants must accept, if any. Default: none.
- `core.vendor.application_fields`: the administrator's extra questions (VEN-13). Default: none.
- `core.vendor.dashboard_page`: the page the dashboard lives on (VEN-15). Set on activation.

## 4. API
Routes follow API-1 through the base REST controller and the route pipeline (IDN-21). **Master v2.9 changes API-2 to match** (VEN-12, VEN-16, VEN-17).
- **Registration and applications:**
  - `POST /vendor-registrations`: `public`, with a tight per-route rate limit (IDN-19, TM-37). It replaces API-2's `POST /vendor-applications` (VEN-12). Body: the account fields (for visitors), the requested store name, the standard fields and the answers to the extra questions.
  - `GET /vendor-applications`: the review queue, with cursors and a status filter. `admin` with `vendors.applications.review`.
  - `GET /vendor-applications/{id}`: the attempt, plus the applicant's earlier attempts, each with its answers and decision, and what changed since the previous one (VEN-11). The applicant sees their own; administrators and managers with `vendors.applications.review` see any; anyone else gets 404 (SEC-4).
  - `POST /vendor-applications/{id}/approve` and `/reject`: `admin` with `vendors.applications.review`. A rejection needs a reason. Repeating either is harmless.
- **Vendors (VEN-17):** `GET /vendors`, plus `POST /vendors/{id}/suspend` and `/reinstate`: `admin` with `vendors.manage`. This replaces API-2's `POST /stores/{id}/suspend`.
- **Settings (VEN-16):** `GET /settings/{module}` and `PATCH /settings/{module}` (`If-Match`): administrators. A change emits `kernel.settings.updated`, which `core.kernel` §5 promised, and `identity.manager.permissions_changed` when the managers' permissions change.
- **Managers (VEN-16):** `GET /managers`, `POST /managers` and `POST /managers/{user_id}/remove`: administrators (IDN-15).
- **Idempotency:** no route here changes money or stock, so none requires `Idempotency-Key`; any `POST` accepts one (IDN-9).

## 5. Events and hooks
- **Events emitted (KER-8):**
  - `vendor.application.submitted`, `vendor.application.approved` and `vendor.application.rejected`;
  - `vendor.account.suspended`, `vendor.account.reinstated` and `vendor.account.closed`;
  - `kernel.settings.updated` and `identity.manager.permissions_changed`, from the settings endpoint;
  - `identity.manager.appointed` and `identity.manager.removed`, from the managers endpoints.
- **Audit entries:** each event above. Reasons and answers stay in their tables, because the audit log holds no free text (SEC-7).
- **Events consumed:** this module's own, to send the emails (VEN-7).
- **WordPress and WooCommerce hooks used:**
  - `deleted_user`, to close the vendor;
  - `template_include`, to render the dashboard page without the theme (VEN-15);
  - `woocommerce_registration_errors`, so anti-spam plugins also guard registration (VEN-12);
  - `woocommerce_email_classes`, to register the emails;
  - `wp_privacy_personal_data_exporters` and `wp_privacy_personal_data_erasers` (VEN-9).
- **Filters:** none of its own. ARCH-8 point 6 names no vendor decision point.

## 6. UI
- **The shell (ARCH-13):** one full-screen React app built with `@wordpress/scripts`, `@wordpress/components` and `@wordpress/data`, in `ui/`. It loads the actor, permissions, features and menu from `/me` in one call (ARCH-9) and draws only what the menu contains. A hidden item is never the security boundary.
- **Screens in this module:**
  - **Register / Apply:** the form for visitors and customers, with the account fields for visitors.
  - **My application:** the status, the reason after a rejection, and resubmitting.
  - **Applications:** the review queue. Each application shows its earlier attempts, their reasons and what changed.
  - **Vendors:** the list, suspend and reinstate.
  - **Settings:** forms generated from the settings registry's definitions, grouped by module, including the application questions.
  - **Modules** (KER-14), **API clients** (IDN-18) and **Managers** (IDN-15).
- **States:** every screen has a loading skeleton, an empty state that says what to do next, and an error state that shows the API-1 message and the request ID.
- **wp-admin:** one menu link that opens the dashboard. Vendors never enter wp-admin; WooCommerce already sends users without `edit_posts` to My Account, and VEN-6 keeps vendors without capabilities.
- **Language:** the app sends `_locale=user` (IDN-21), and its strings load through `wp.i18n` (VEN-15).

## 7. Extension seams
- **Lifecycle:** the shared state machine lets Pro add states (for example verification) without changing Core's transitions.
- **Application questions:** Pro Memberships can show different questions per plan, as WCFM does.
- **KYC documents:** Pro Verification/KYC adds document questions and stores files outside the web root, linked to `vendor_applications.id` (VEN-14).
- **`/me` sections:** this module ships the contract and the first section, `vendor`; `core.store` adds the store summary.
- **Resource policies:** the `ResourcePolicy` interface, with `VendorPolicy` as its first implementation on `PolicyEngine::vendorResource()`.
- **Dashboard screens:** each module declares its menu items (ARCH-8 point 7) and ships its screens as entry points the shell loads when their item is opened.

## 8. Tests
- **TEST-3 row 5, the vendor half:** `VendorLifecycleStateMachineTest` and `VendorApplicationWorkflowTest`. `core.store` owns the rest of the row.
- **Added by the threat model:**
  - `DashboardContentSecurityPolicyTest` (TM-28): the dashboard page sends a CSP that allows only Mercatus's own scripts, with nonces for inline data.
  - `PersonalDataExportEraseTest` (TM-33): WordPress's exporter and eraser cover applications and vendors (VEN-9).
  - `SignedPrivateFileUrlTest` (TM-30) now belongs to Pro Verification/KYC (VEN-14), and the threat model's table says so.
- **Identity, now with real vendors:** `CachedActorIdentityInvalidationTest` and `IdorActorMatrixTest` use real owners instead of `pro.testactors`. Suspending a vendor bumps their identity in the same request.
- **Extra:**
  - `StateMachineTest` (shared kernel);
  - `VendorRegistrationTest`: visitors get a WooCommerce account; administrators, managers and other higher types are refused (VEN-12);
  - `ApplicationHistoryTest`: resubmissions keep every attempt, and the review shows the earlier reasons and the changes;
  - `VendorClaimSourceTest`, `VendorApplicationEndpointContractTest` and `ApplicationEmailsTest`;
  - `MultilingualDashboardPageTest`: a translated dashboard page is still the dashboard (VEN-15).
- **JS (Vitest):** the shell's menu, routing, and empty, loading and error states.
- **End to end (Playwright, TEST-1 Layer 7):**
  - A visitor registers and applies, an administrator rejects with a reason, the applicant resubmits, and the administrator approves; the new vendor then sees their dashboard menu.
  - An administrator trying to apply gets the error.
  - The Playwright CI job arrives with these specs (GW-6).
- **Threat model:**

  | Threat | Tests |
  |---|---|
  | TM-8 | `CachedActorIdentityInvalidationTest`, `VendorApplicationWorkflowTest` |
  | TM-28 | `DashboardContentSecurityPolicyTest` |
  | TM-33 | `PersonalDataExportEraseTest` |
  | TM-37 | `RateLimiterTest` (the registration route's own limit) |

## 9. Scale
- **Seeder slice:** `database/seeders/steps/core.vendor.php` creates the PLAT-10 vendors, approved, deterministically (KER-12).
- **Target ceilings (Mercatus's own queries), fixed during implementation:**
  - A cold identity with the vendor claim source: 1 indexed query (`vendors.user_id`).
  - A warm identity with a persistent cache: 0.
  - One page of `GET /vendor-applications` or `GET /vendors`: 1 query, with no per-row queries or `COUNT`.
  - `GET /vendor-applications/{id}` with its history: 2 queries (the attempt, then the applicant's earlier attempts).
  - Approving an application: fixed, whatever the number of vendors.

## 10. Decisions
- **VEN-1 Lifecycles.**
  - An application moves from `pending` to `approved` or `rejected`; the applicant may withdraw a `pending` one.
  - A vendor exists from its first approval. It moves from `active` to `suspended` and back, and to `closed` when its WordPress user is deleted.
  - Every transition is a command that publishes its event and writes its audit entry in one transaction (API-3).
- **VEN-2 The shared state machine (DRY-2).** A small, immutable definition of states and allowed transitions, checked before any write. A refused transition is `mercatus_transition_not_allowed` (409). Later lifecycles (vendor orders, withdrawals) reuse it.
- **VEN-3 Approval.**
  - Administrators, and managers holding `vendors.applications.review`, decide applications.
  - With `core.vendor.auto_approve` on, a valid application is approved at once, and the approval is audited as the system's.
  - A manager's vendor scope applies (IDN-15); in Free it's all vendors.
- **VEN-4 The claim source.** It reads the user's `vendors` row by `user_id`: one indexed query on a cold identity. It claims `vendor_owner` for an `active` vendor only. Applicants and suspended or closed vendors are `customer`s, so tenant routes refuse them (IDN-4).
- **VEN-5 Store hand-off.** The approval event carries the requested store name. `core.store` creates the store from it when it's built; until then, the name stays on the approved application.
- **VEN-6 No WordPress roles or capabilities.** Approval changes no WordPress role and grants no capability (SEC-8). Being a vendor is the identity's claim, never a WordPress capability.
- **VEN-7 Emails (ARCH-12).**
  - Each email is a `WC_Email` class, so administrators edit its text and switch it off in WooCommerce's email settings.
  - Handlers on the `critical` lane send them after commit (API-3); the poller retries a failure.
  - The applicant's emails go to their WordPress email, in their language (VEN-15). The application-received email goes to the store's admin email.
- **VEN-8 Permissions.** `vendors.applications.review` covers the queue, approving and rejecting; `vendors.manage` covers the vendor list, suspending and reinstating. Settings and managers stay with administrators.
- **VEN-9 Personal data (SEC-7).**
  - The exporter returns the user's application attempts and vendor row.
  - The eraser anonymises the answers, store names and reasons of `rejected` and `withdrawn` attempts. While the user is a vendor, their approved attempt and vendor row are reported as retained, with the reason, because orders and money refer to them.
  - The audit log holds no free text (SEC-7), so nothing there needs erasing.
- **VEN-10 Dashboard security.**
  - The dashboard page sends `Content-Security-Policy`: scripts from the site itself only, inline data through per-request nonces (`wp_inline_script_attributes`), no `unsafe-eval`, and `frame-ancestors 'self'`.
  - Vendor content is escaped on output (SEC-7). Other plugins' scripts aren't loaded on the page.
- **VEN-11 Application history (OQ-1).**
  - Every attempt is a row in `vendor_applications`, and resubmitting creates the next attempt. The earlier rows, with their answers, rejection reasons and decisions, are never overwritten.
  - The review shows the current attempt next to the earlier ones, with their reasons and the answers that changed.
  - A single row was weighed and refused. Each attempt's answers, reason and decision, and Pro's KYC documents, would have to live in one growing JSON blob, with nothing to link documents to an attempt. The audit log can't hold this history either, because it carries no free text.
  - `vendors` holds approved vendors only, so the claim source and the vendor list stay one-table lookups.
  - Master v2.9 adds `vendor_applications` to DATA-2.
- **VEN-12 Registration (OQ-2).**
  - `POST /vendor-registrations` takes visitors and signed-in users.
  - **Visitors:** it creates their account through WooCommerce's `wc_create_new_customer()`, so WooCommerce's password settings, its new-account email and the anti-spam plugins on `woocommerce_registration_errors` all apply. It then signs them in and returns a fresh REST nonce.
    - An email that already has an account gets 409, asking the person to sign in, as WooCommerce's own registration does.
  - **Signed-in users:** only `customer`s may apply. Administrators, marketplace managers and every other type ranked higher (IDN-1) get 403 `mercatus_vendor_application_not_allowed`, with a message saying why.
    - An existing vendor gets 409 `mercatus_already_vendor`, and a pending application 409 `mercatus_application_pending`.
  - Master v2.9 replaces API-2's `POST /vendor-applications` with this endpoint.
- **VEN-13 The application form (OQ-3).**
  - The fixed fields: email and name (visitors), the store name, a contact phone, a message, and acceptance of the terms page when one is set.
  - Administrators add extra questions in `core.vendor.application_fields`: text, long text, choice or checkbox, each optionally required.
  - Answers are validated against the questions in force at submission and saved with their labels (VEN-11).
- **VEN-14 Documents (OQ-4).** Free applications take no files. Pro Verification/KYC adds document questions later, linked to the attempt by `vendor_applications.id`. `SignedPrivateFileUrlTest` moved to that module in the threat model.
- **VEN-15 The dashboard page and languages (OQ-5).**
  - On activation Mercatus creates a "Marketplace dashboard" page (slug `dashboard`) and records it in `core.vendor.dashboard_page`; the administrator can choose another.
  - On that page Mercatus renders its own minimal document instead of the theme, so the dashboard is full-screen and only Mercatus's scripts load (VEN-10).
  - **Multilingual (PLAT-11), through WPML's and Polylang's documented APIs:**
    - Any translation of the dashboard page counts as the dashboard (WPML's `wpml_object_id`, Polylang's `pll_get_post`).
    - Strings the administrator enters, such as the questions and their choices, register with WPML String Translation (`wpml_register_single_string`) and are shown translated.
    - The app's strings load through `wp.i18n`, and emails switch to the recipient's language.
  - PLAT-11 (master v2.9) makes multilingual support a rule for every module; this is core.vendor's part of it.
- **VEN-16 Settings and managers endpoints (OQ-6).** Master v2.9 adds `GET /vendor-applications`, `GET`/`PATCH /settings/{module}` and the `/managers` endpoints to API-2 (§4). The settings endpoint emits `kernel.settings.updated` and, when the managers' permissions change, `identity.manager.permissions_changed`.
- **VEN-17 Suspension is a vendor action (OQ-7).**
  - `GET /vendors`, `POST /vendors/{id}/suspend` and `POST /vendors/{id}/reinstate` come with this module; master v2.9 moves the suspend endpoint from Stores to Vendors.
  - Suspending bumps the vendor's identity in the same request (TM-8). Their store goes hidden once `core.store` exists.

- **VEN-18 Registration, applications and vendors (increment 1).**
  - **Code:** `src/Vendor/` (Domain, Application, Infrastructure, Api), with migrations in `database/migrations/core.vendor/`.
  - **Shared kernel and contracts:** `StateMachine` joins the shared kernel (DRY-2). `AccessDecision` moves into `Contracts\Identity`, so Pro can implement the new `ResourcePolicy`. `MeSectionProvider` and the manifest's `meSections` are new. `ClaimSource::claims()` is marked impure, because claims read the database.
  - **Layers:** the endpoints reach the commands through the Application port `VendorDirectory`, as with API clients (IDN-21). Permission names live in `VendorAccess` (Application), and the translated labels in the API layer (ARCH-7).
  - **Withdrawing:** an applicant can't withdraw through the API yet, because API-2 has no endpoint for it. `withdrawn` is reached when the user is deleted.
  - **Reviewers see the applicant:** queue items and the application include the applicant's name and email, loaded for a whole page with one `cache_users()`.
  - **Emails:** five `WC_Email` classes with HTML and plain templates in `templates/emails/`, which themes can override like WooCommerce's. `VendorEmailSubscriber` sends them on the `critical` lane and reads reasons from the tables. PHPCS now checks `templates/`.
  - **No dashboard link yet:** the approval email doesn't link to the dashboard; increment 2 adds the page.
  - **`/me` stays at 0 queries (PLAT-10):** the scale gate showed the vendor section costing 2 queries on every `/me`, for everyone. Each user's standing is now cached under a per-user revision that every change bumps. Warm it costs no queries; cold, 2.
  - **Integration tests:** the bootstrap registers the site kernel's savepoint runner while the kernel starts, because module hooks now build services that hold a transaction runner at boot. Doing it later let a real `COMMIT` leak rows between tests.

## 11. Open questions
None. All seven were answered on 2026-10-24 and are recorded as VEN-11 to VEN-17.

## 12. Done when
- Master v2.9 is merged: `vendor_applications` in DATA-2, the API-2 changes (VEN-12, VEN-16, VEN-17) and the multilingual rule (VEN-15).
- `VendorLifecycleStateMachineTest`, `VendorApplicationWorkflowTest`, `DashboardContentSecurityPolicyTest` and `PersonalDataExportEraseTest` pass in CI with and without a persistent cache (ROAD-2 step 9).
- The Playwright job runs the first end-to-end specs in CI, and the shell's Vitest tests pass.
- `openapi/mercatus-v1.yaml` includes this module's routes, and Redocly passes.
- The scale gate has the vendor scenarios with their ceilings fixed, and `pro.testactors` is gone.
- No Section 2 guardrail is violated, and this file is marked Implemented.
