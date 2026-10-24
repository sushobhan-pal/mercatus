# `core.store` — Decision File

**Status:** Draft
**Phase / position:** Phase 2, module 4 in ROAD-3 (the last module of Phase 2)
**Branch:** `core.store`
**Depends on:** `core.vendor` (module 3)
**Implements master rules:** ROAD-3 row 4, SCOPE-2 (one public storefront per vendor, profile, policies, visibility, store list, one default fulfilment location), SCOPE-3, ARCH-9 (a server-rendered store page), ARCH-13 (the vendor's store settings screen), API-2 (Stores), DATA-2 (`stores`, `fulfilment_locations`), CAT-1 (the default location), CAT-6 (geocoding and `geo_cell`), PLAT-3 (L2 group `mercatus_stores`, L3 public cache headers), PLAT-9, PLAT-10 (`GET /stores/{slug}`), PLAT-11, SEC-2 (`StorePolicy`, field-level visibility), SEC-4, SEC-7 (rich text, the outbound allow-list), TEST-3 row 5 (the store half), the ROAD-3 Phase 2 exit gate

Rules for this file (P-4, DRY-4): cite master IDs instead of restating them, never contradict the master document, and give each decision made here a local ID using the module's short code (for example `KER-1`). If this module needs a master rule changed, the master document changes first.

Local IDs use the code `STO`.

---

## 1. Scope
- **In:**
  - **The store (DATA-2 `stores`):**
    - created for each vendor when they're approved, from the store name they applied with (VEN-5);
    - a normalized, unique slug (OQ-1);
    - the profile (OQ-3), its visibility (OQ-5), and a status that follows the vendor (STO-2).
  - **The default fulfilment location (DATA-2 `fulfilment_locations`, CAT-1):**
    - created with the store, and the only location in Free; location management stays hidden;
    - its address, geocoded on create and update only, with `latitude`, `longitude` and `geo_cell` (CAT-6, OQ-6).
  - **The outbound allow-list (SEC-7, TM-31):** the one way Mercatus code calls another host, first used by geocoding (STO-8).
  - **The Stores endpoints (API-2):** `GET /stores`, `GET /stores/{slug}`, `GET /me/store` and `PATCH /me/store`, plus whatever OQ-7 adds.
  - **The store summary in `/me`**, through the section seam core.vendor built (IDN-12).
  - **`StorePolicy`**, the second `ResourcePolicy` (IDN-14), and field-level visibility for contact details (SEC-2, TM-10).
  - **Vendor rich text** cleaned on save and escaped on output (SEC-7, TM-28).
  - **The public store page and the store list,** server-rendered (ARCH-9, OQ-2).
  - **The dashboard's store settings screen** for vendors (ARCH-13).
  - **Multilingual support** for this module's pages and strings (PLAT-11, OQ-8).
  - **The store seeder step and its query ceilings** (PLAT-10), and the end-to-end specs for the store page.
- **Out:**
  - **`core.catalog` (5a, 5b):** products and offers on the store page, media ownership and quotas (OQ-4), and offer availability by location.
  - **`core.shipping`:** the ship-from address and vendor shipping settings (`vendor_shipping_profiles`). The default location's address is the store's address; shipping decides how it uses it.
  - **`core.reviews`:** store reviews and ratings.
  - **Pro:** multi-location fulfilment, vacation and scheduled availability, advanced SEO, branded storefronts (SCOPE-2).
- **Increments (ROAD-1):**
  1. **Stores and locations:** the tables, creating the store and its default location on approval, the slug, the status that follows the vendor, `StorePolicy`, the endpoints, the `/me` section, rich-text cleaning, contact-detail visibility, caching, and the seeder step.
  2. **Store page and settings:** the public store page and store list, the dashboard's store settings screen, geocoding through the outbound allow-list, multilingual support, and the end-to-end specs.

## 2. Data
- **`stores` (DATA-2):** one row per vendor.
  - Columns:
    - `vendor_id`, `name`, `slug`;
    - the profile fields OQ-3 settles (for example `tagline`, `description`, `contact_email`, `contact_phone`, with a flag per contact field for showing it publicly);
    - `logo_id` and `banner_id` (attachment IDs; see OQ-4);
    - `visibility` (`public`, `hidden`), `status` (`active`, `suspended`, `closed`);
    - `created_at`, `updated_at`, `version`.
  - Keys as DATA-2: PK `id`; UNIQUE `vendor_id`; UNIQUE `slug`; INDEX `(visibility, status)`.
  - The store list needs a cursor order; the index for it (for example `(visibility, status, name, id)`) is fixed during implementation against the scale data.
- **`fulfilment_locations` (DATA-2):** one default row per vendor in Free.
  - Columns:
    - `vendor_id`, `type` (`default` here; Pro adds the others), `is_default`, `status`;
    - the address (`address_1`, `address_2`, `city`, `state`, `postcode`, `country`);
    - `latitude`, `longitude`, `geo_cell` (6 characters, CAT-6);
    - `created_at`, `updated_at`, `version`.
  - Keys as DATA-2: PK `id`; INDEX `(vendor_id, status, is_default)`; INDEX `(geo_cell, status)`.
- **No WooCommerce data changes.** Stores and locations are Mercatus tables; nothing is written to `wp_posts` or user meta (G-DB-4).
- **Seeder slice:** the PLAT-10 vendors' stores come from their approvals; this module's step fills in their profiles and default locations, spread over a few countries so `geo_cell` varies, deterministically (KER-12).

## 3. Settings
Changed by an administrator only:
- `core.store.base`: the first part of a store page's address, for example `store` in `/store/{slug}/` (OQ-2). Default: `store`.
- `core.store.new_store_visibility`: whether a new store is public at once or hidden until its vendor opens it (OQ-5). Default per OQ-5.
- `core.store.vendors_change_slug`: whether vendors may change their slug (OQ-1). Default per OQ-1.
- `core.store.geocoder`: which geocoder runs when a location's address changes, or none (OQ-6). Default per OQ-6.
- `core.store.store_list_page`: the page showing the store list (OQ-2). Set on activation, as the dashboard page is (VEN-19).

## 4. API
Routes follow API-1 through the route pipeline (IDN-21).
- **Public:**
  - `GET /stores`: active, public stores, with cursors and a name search. `Cache-Control: public, max-age=60, stale-while-revalidate=300` and a list ETag (PLAT-3, PLAT-9).
  - `GET /stores/{slug}`: one active, public store, with the same headers and its version ETag. A hidden, suspended or closed store is 404 to the public; its owner, administrators and managers holding `stores.manage` still see it (SEC-4).
  - Contact details appear only when the vendor chose to show them (TM-10).
- **The vendor (`tenant`):**
  - `GET /me/store`: the vendor's own store, every field.
  - `PATCH /me/store` (`If-Match`): the profile, visibility, slug (OQ-1) and the default location's address. Fields such as `vendor_id`, `status` and `geo_cell` are never read from the body (SEC-3).
- **Administrators:** see OQ-7.
- **Idempotency:** nothing here changes money or stock, so no route requires `Idempotency-Key`; any `POST` accepts one (IDN-9).

## 5. Events and hooks
- **Events emitted (KER-8):** `store.store.created`, `store.store.updated`, `store.store.visibility_changed`, `store.store.status_changed`, `store.location.created` and `store.location.updated`. Payloads carry IDs, versions and the names of changed fields, never free text (SEC-7).
- **Audit entries:** each event above.
- **Events consumed:**
  - `vendor.application.approved`: create the store and its default location, once (VEN-5).
  - `vendor.account.suspended`, `vendor.account.reinstated` and `vendor.account.closed`: the store's status follows (STO-2).
- **WordPress and WooCommerce hooks used:**
  - rewrite rules and `template_include` for the store page (OQ-2);
  - `wp_privacy_personal_data_exporters` and `wp_privacy_personal_data_erasers` for the contact details (SEC-7);
  - `pre_http_request`, so the outbound allow-list also applies to Mercatus calls made through WordPress's HTTP API (STO-8).
- **Filters:** none of its own. ARCH-8 point 6 names no store decision point.

## 6. UI
- **Dashboard (ARCH-13):** a "Store" screen for vendors (`vendor_owner`), with the profile, visibility, slug and address. It has loading, empty and error states, as every screen does (VEN-19). Logo and banner are covered by OQ-4.
- **Storefront (ARCH-9):** the store page and the store list, server-rendered as blocks and hydrated from the same serializer as the API (OQ-2). In this module the store page shows the profile; `core.catalog` adds the products.
- **Language:** see OQ-8.

## 7. Extension seams
- **Locations:** `fulfilment_locations` already has `type` and `is_default`, so Pro Multi-Location adds locations without changing the default one (CAT-1).
- **Geocoders:** a `Geocoder` contract, with Core's adapters and room for Pro's or a site's own (OQ-6).
- **Outbound allow-list:** later modules (webhooks, payment providers, AI) add their hosts through the same component instead of calling `wp_remote_*` directly (STO-8).
- **Store page blocks:** `core.catalog` and `core.reviews` add their blocks to the store template.
- **`/me` sections:** the `store` section joins core.vendor's `vendor` section (IDN-12).

## 8. Tests
- **TEST-3 row 5, the store half:** `StoreSlugNormalizerTest`, `StorePolicyTest`, `DefaultLocationAutoCreationTest`, `StoreRepositoryTest`, `StoreCrossTenantIsolationTest`, `StorePiiFieldRedactionTest` and `StorefrontEdgeCacheHeaderTest`.
- **Added by the threat model:**
  - `VendorRichTextSanitizationTest` (TM-28): store descriptions are cleaned on save with the fixed allow-list and escaped on output.
  - `OutboundRequestAllowListTest` (TM-31): a geocoding request to a host off the allow-list, or to a private or loopback address, is refused.
- **Identity, with real stores:** `IdorActorMatrixTest` gets its first tenant routes, so its tripwire for other-store cases is replaced by the real cases (IDN-20).
- **Extra:**
  - `StoreCreatedOnApprovalTest`: approval creates exactly one store and one default location, also when the event is delivered twice.
  - `StoreFollowsVendorStatusTest`: suspending hides the store at once, and reinstating restores it (VEN-17).
  - `StoreEndpointContractTest` and `MeStoreSectionTest`.
  - `MultilingualStorePageTest` (OQ-8).
- **JS (Vitest):** the store settings screen.
- **End to end (Playwright):** a vendor edits their store in the dashboard, and the store page and store list show the change; a suspended vendor's store page is 404.
- **Threat model:**

  | Threat | Tests |
  |---|---|
  | TM-3, TM-4 | `StoreCrossTenantIsolationTest`, `IdorActorMatrixTest` |
  | TM-10 | `StorePiiFieldRedactionTest` |
  | TM-28 | `VendorRichTextSanitizationTest` |
  | TM-31 | `OutboundRequestAllowListTest` |
  | TM-38 | `StorefrontEdgeCacheHeaderTest` |

## 9. Scale
- **Seeder slice:** `database/seeders/steps/core.store.php` fills in the 2,000 seeded vendors' profiles and default locations (KER-12).
- **Target ceilings (Mercatus's own queries), fixed during implementation:**
  - `GET /stores/{slug}`: 0 warm, at most 2 cold (PLAT-10).
  - One page of `GET /stores`: 1 query, with no per-row queries or `COUNT`.
  - `GET /me` with the `store` section: 0 warm, as it is today.
  - Creating a store on approval: fixed, whatever the number of stores.

## 10. Decisions
- **STO-1 Store creation (VEN-5).**
  - A `critical`-lane subscriber to `vendor.application.approved` creates the store and its default location after the approval commits; the poller retries a failure (API-3).
  - It's idempotent on `stores.vendor_id`, so a repeated delivery creates nothing more.
  - The slug comes from the store name the vendor applied with (OQ-1).
- **STO-2 The store follows the vendor.**
  - `status` mirrors the vendor: suspended → `suspended`, reinstated → `active`, closed → `closed` (VEN-17).
  - `visibility` is the vendor's choice (OQ-5). The public sees a store only when it's `active` and `public`.
  - Suspending bumps the store's cache revision in the same request, so the page and the list drop it at once.
- **STO-3 `StorePolicy` (IDN-14).**
  - Reading a public store needs nothing.
  - The owner reads and changes their own store.
  - Administrators, and managers holding `stores.manage`, read any store (and change it, per OQ-7).
  - Anyone else asking for a store they can't see gets 404 (SEC-4).
- **STO-4 One default location (CAT-1).** Each store has exactly one default fulfilment location, created with it. Free never shows location management: the address is part of the store settings, and everything that needs a location resolves to the default one.
- **STO-5 Rich text (SEC-7, G-SEC-5).** The description is cleaned on save with a fixed allow-list (paragraphs, line breaks, emphasis, lists and links with `rel="nofollow ugc"`), and escaped again on output. Names, taglines and policies are plain text.
- **STO-6 Contact details (SEC-2, TM-10).** Contact email and phone are private by default. Each has its own "show on my store page" flag, and the public serializer leaves them out unless it's on. The street address is never public; the store page shows the city, state and country.
- **STO-7 Caching (PLAT-3, PLAT-5).**
  - Each store is cached in `mercatus_stores` under its own revision, which every change to the store or its location bumps.
  - `GET /stores/{slug}` reads the slug-to-ID map and the store from the cache.
  - Public responses send the PLAT-3 L3 headers; the vendor's `/me/store` sends `private, no-cache`.
- **STO-8 Outbound allow-list (SEC-7, TM-31).**
  - One infrastructure component wraps `wp_safe_remote_request()`. It allows only HTTPS to hosts on the list a module registers (the geocoder's, here), refuses private, loopback and link-local addresses after DNS resolution, and sets timeouts.
  - Mercatus code never calls `wp_remote_*` directly; an architecture test enforces it.

## 11. Open questions
- **OQ-1 The slug.** How is it made, and can it change?
  - **Options:**
    - (a) Made from the store name on approval: lower case, transliterated, hyphenated, with a number added when it's taken and a list of reserved words refused. Vendors may change it in their settings (an admin setting can lock that); the old address simply stops working.
    - (b) As (a), but the slug is fixed once made; only an administrator can change it.
    - (c) As (a), and old slugs redirect to the new one, which needs a slug-history table that DATA-2 doesn't have.
  - **Recommendation:** (a). Redirects for old slugs belong to Pro's advanced SEO.
- **OQ-2 The store page and the store list.** Where do they live, and how do they meet the theme?
  - **Options:**
    - (a) The store page lives at `/{base}/{slug}/` (base `store` by default, an admin setting) through Mercatus's rewrite rule. It renders inside the active theme (header, footer, styles) from Mercatus blocks: a block template for block themes and a PHP template for classic themes, which a theme may override. The store list is a "Stores" block on a page Mercatus creates on activation, as it does for the dashboard.
    - (b) Like the dashboard, a full-screen Mercatus document without the theme.
    - (c) A WordPress page per store, created on approval.
  - **Recommendation:** (a). ARCH-9 already settles that store pages are server-rendered blocks; this settles the address and the theme's part.
- **OQ-3 The profile.** Which fields does a Free store have?
  - **Options:**
    - (a) Name, tagline, description (rich text), logo, banner, contact email and phone (each with a "show publicly" flag), the default location's address, and three policies (shipping, returns, privacy) as plain text.
    - (b) As (a), plus social links and opening hours.
    - (c) Only name, description, logo and address; contact details and policies arrive later.
  - **Recommendation:** (a). It covers SCOPE-2's "profile, policies"; social links and hours are easy to add later.
- **OQ-4 Logo and banner before media ownership.** `core.catalog` (5a) builds media ownership and quotas (`media_ownership`, `/me/media`), right after this module. How do logo and banner work until then?
  - **Options:**
    - (a) This module adds the `logo_id` and `banner_id` columns and the store page shows them. Setting them through `PATCH /me/store` arrives with 5a, which checks that the vendor owns the attachment.
    - (b) This module builds a narrow upload just for logo and banner, with SEC-7's MIME and SVG policy, which 5a later folds into media ownership.
    - (c) Media ownership moves from `core.catalog` into this module (a ROAD-3 change).
  - **Recommendation:** (a). Ownership checks are written once, in 5a, and nothing is uploaded without them.
- **OQ-5 New stores and visibility.** Is a new store public as soon as its vendor is approved?
  - **Options:**
    - (a) Public at once. Vendors can hide it.
    - (b) Hidden until its vendor opens it from the store settings, so the store list doesn't fill with empty stores. An admin setting chooses between (a) and (b), with (b) as the default.
    - (c) Hidden until the profile is complete (for example a description and an address), then public automatically.
  - **Recommendation:** (b).
- **OQ-6 Geocoding.** CAT-6 geocodes an address when a location is created or updated. Which geocoder does Free use?
  - **Options:**
    - (a) A `Geocoder` contract with two Core adapters: OpenStreetMap's Nominatim (no key; within its usage policy, since it only runs when an address changes) and Google's Geocoding API (the administrator's key, encrypted per SEC-6). Geocoding is off until the administrator chooses one; without it, `latitude`, `longitude` and `geo_cell` stay empty and nothing depends on them yet.
    - (b) Only the contract and no adapter; sites or Pro add one.
    - (c) No geocoding; the vendor drops a pin on a map instead.
  - **Recommendation:** (a). Searching stores near a place isn't in this module either way; it arrives with location discovery in `core.catalog`.
- **OQ-7 Administrators and stores.** API-2's Stores row has no administrator endpoints, so today an administrator can't fix a store's name, description or slug, or hide it without suspending the vendor.
  - **Options:**
    - (a) Add `GET /stores/{id}` and `PATCH /stores/{id}` for administrators and managers holding `stores.manage`, with the same fields as `PATCH /me/store` plus visibility, and a hidden-store filter on `GET /stores` for them. Master v2.10 adds them to API-2.
    - (b) No administrator endpoints: suspending the vendor is the only lever.
    - (c) Administrators edit a store by signing in as the vendor.
  - **Recommendation:** (a).
- **OQ-8 Store content in other languages.** PLAT-11 says vendor content follows the multilingual plugin's own model, but stores live in a Mercatus table, which WPML and Polylang don't translate.
  - **Options:**
    - (a) One language per store in Free: the store page's own labels, the store list page and the base slug are translated; the vendor's own text is shown as written.
    - (b) As (a), plus the store's name, tagline, description and policies registered as translatable strings, so an administrator can translate them in WPML or Polylang.
    - (c) Columns per language in `stores`.
  - **Recommendation:** (a). Vendors writing in several languages is a branded-storefront feature (Pro).

## 12. Done when
- Any master change the answers need is merged first (OQ-7 needs master v2.10).
- TEST-3 row 5 passes in full with and without a persistent cache, including the IDOR and warm-cache zero-SQL tests: the ROAD-3 Phase 2 exit gate.
- `VendorRichTextSanitizationTest` and `OutboundRequestAllowListTest` pass.
- `openapi/mercatus-v1.yaml` includes this module's routes, and Redocly passes.
- The scale gate has the store scenarios with their ceilings fixed, and `GET /stores/{slug}` meets PLAT-10.
- The store page's end-to-end specs pass in CI.
- No Section 2 guardrail is violated, and this file is marked Implemented.
