# `core.catalog` — Decision File

**Status:** Draft
**Phase / position:** Phase 3, module 5a in ROAD-3 (products). Before module 5b (discovery) starts, this file gains its sections and open questions, approved the same way.
**Branch:** `core.catalog`
**Depends on:** `core.store` (module 4)
**Implements master rules:** ROAD-3 row 5a, SCOPE-2 (Catalog: vendor ownership of WooCommerce products, the availability projection, media ownership and quotas, moderation basics), SCOPE-3 (Mercatus's offer block on store pages), CAT-1, CAT-5, ARCH-9, ARCH-10, ARCH-12 (price and stock copies, projection sync, the nightly reconciler), ARCH-13 (the Products and Media screens), API-1, API-2 (Vendor products & offers, Media, and the 5a part of Catalog), API-3 (coalesced projection rebuilds), DATA-1, DATA-2 (`catalog_products`, `vendor_offers`, `offer_locations`, `inventory_balances`, `inventory_movements`, `media_ownership`, `offer_availability`), DRY-2 (the projection runner), PLAT-3 (L2 groups `mercatus_offers` and `mercatus_availability`, L3 headers on the public catalog reads), PLAT-5, PLAT-7, PLAT-9, PLAT-10, PLAT-11, SEC-2, SEC-3, SEC-4, SEC-7 (product rich text, the MIME and SVG policy), SEC-8, STO-12, TEST-2, TEST-3 row 6 (the 5a half)

Rules for this file (P-4, DRY-4): cite master IDs instead of restating them, never contradict the master document, and give each decision made here a local ID using the module's short code (for example `KER-1`). If this module needs a master rule changed, the master document changes first.

Local IDs use the code `CTL`, since `CAT` is the master document's own prefix.

---

## 1. Scope
- **In:**
  - **Catalog products and offers (CAT-1, DATA-2):**
    - a catalog product for each WooCommerce product a vendor creates, with its type and its governance state (CTL-5);
    - the vendor's offer, with its prices (OQ-4) and status; one active offer per product in Free (CAT-1);
    - the offer at the vendor's default location (`offer_locations`), the only location in Free (STO-4).
  - **Inventory:** balances per offer, variation and location as the source of truth for stock, the append-only movement journal, and `PATCH /me/offers/{id}/inventory` (CTL-6).
  - **The WooCommerce product adapter (ARCH-12):** content lives in the WooCommerce product, written through CRUD, and Mercatus writes WooCommerce's price and stock copies (CTL-1, CTL-2).
  - **The vendor product endpoints (API-2):** list, create, read, change and trash, plus publish and unpublish actions, which master v2.12 adds (API-1, CTL-18).
  - **Moderation basics (SCOPE-2):** OQ-2.
  - **Media (API-2, SEC-7):** uploads with the MIME and SVG policy, ownership and quotas (OQ-6, CTL-14), and setting the store's logo and banner (STO-12).
  - **The availability projection (CAT-5)** and the shared projection runner (DRY-2), updated at once by a vendor's own edits (ARCH-12, CTL-9).
  - **Outside edits and drift (ARCH-12):** changes made through WooCommerce are adopted, and a nightly reconciler catches what the hooks missed (CTL-11, CTL-12).
  - **Products follow their store:** they're visible and purchasable only while their store is open (CTL-13).
  - **The storefront:**
    - a products block on the store page;
    - `GET /catalog/offers` for one store and `GET /catalog/products/{id}/offers` (CTL-15);
    - a "Sold by" line on WooCommerce's product pages and lists (CTL-16).
  - **`GET /catalog/categories`** for the category picker (CTL-17).
  - **Dashboard screens (ARCH-13):** Products and Media for vendors, and the moderation queue (OQ-2).
  - **Multilingual support (PLAT-11):** OQ-7.
  - **The catalog seeder step and its query ceilings (PLAT-10),** and the end-to-end specs.
- **Out:**
  - **5b (discovery):**
    - the category index (`categories`), Tier 2 attributes, `category_attribute_maps`, `catalog_facets` and facet counts (CAT-2 to CAT-4);
    - the category schema and attribute-terms endpoints, the catalog block and the implementation of `CatalogSearchPort`;
    - search, category and attribute filters on `GET /catalog/offers`, and searching near a place (CAT-6).
  - **`core.orders`:** checkout reservations (`inventory_reservations`, ORD-1) and the single stock deduction (`StockSingleDeductionTest`). Until then, WooCommerce's own stock reduction for an order is adopted as an outside change (CTL-11).
  - **`core.shipping` and `core.tax`:** shipping classes, and a product's tax status and class. Products keep WooCommerce's defaults until those modules add the fields.
  - **Later:** downloadable products (OQ-1), up-sells and cross-sells, purchase notes, and a vendor's preview of an unpublished product's page.
  - **Pro:** SPMV, multi-location stock and prices, location promotions, variable-weight selling, the Bookings, Subscriptions and Auctions integrations, the bulk importer, catalog cloning and advanced product rules (SCOPE-2).
- **Increments (ROAD-1):**
  1. **Products, offers and inventory:**
     - the tables, the WooCommerce adapter and `CatalogPolicy`;
     - the vendor endpoints and actions, and moderation;
     - the projection runner, with a vendor's own edits updating the projection at once;
     - the Products screen.
  2. **Media:** uploads, the MIME and SVG policy, quotas, product images, the store's logo and banner, and the Media screen.
  3. **Storefront and sync:**
     - adopting outside edits, the nightly reconciler, and products that follow their store;
     - the store page's products block, the public catalog endpoints and the "Sold by" line;
     - multilingual support;
     - the seeder step and ceilings, and the end-to-end specs.

## 2. Data
- **`catalog_products` (DATA-2):** one row per product.
  - Columns:
    - `woo_product_id`: the WooCommerce product, in its original language (OQ-7);
    - `scope`: `vendor`, or `marketplace` for the marketplace's own products (OQ-5);
    - `owner_vendor_id`: whose content it is (CTL-1), `0` for the marketplace's own;
    - `product_type`: `simple` or `variable` (OQ-1); DATA-2's other types are Pro's;
    - `status` (CTL-5) and `status_reason`, the latest rejection or take-down reason;
    - `primary_category_id`: the first WooCommerce category chosen (its term ID);
    - `created_at`, `updated_at`, `version` (CTL-7).
  - Keys as DATA-2: PK `id`; UNIQUE `woo_product_id`; INDEX `(scope, status, product_type)`; INDEX `(primary_category_id, status)`.
- **`vendor_offers` (DATA-2):** one row per product and vendor, with one active per product in Free.
  - Columns: `catalog_product_id`, `vendor_id`, `status` (`active`, `paused`, `deleted`), the prices (`base_price` and, per OQ-4, the sale price and its dates), `created_at`, `updated_at`, `version`.
  - Keys as DATA-2: PK `id`; UNIQUE `(catalog_product_id, vendor_id)`; INDEX `(vendor_id, status)`.
- **`offer_variations` (new; OQ-4, master v2.12):** a variable product's prices, one row per variation.
  - Columns: `vendor_offer_id`, `variation_id`, the same price columns as the offer, `status`, `version`.
  - Keys: PK `id`; UNIQUE `(vendor_offer_id, variation_id)`.
- **`offer_locations` (DATA-2):** the offer at the vendor's default location: `vendor_offer_id`, `location_id`, `status`. Free has no local overrides (CAT-1). Keys as DATA-2.
- **`inventory_balances` (DATA-2):** one row per offer, variation (`0` for a simple product) and location.
  - Columns: `manage_stock`, `quantity` (`DECIMAL(20,6)`, whole numbers in Free), `stock_status` (`instock`, `outofstock`, `onbackorder`), `backorders` (`no`, `notify`, `yes`), `updated_at`, `version`.
  - Keys as DATA-2: PK `id`; UNIQUE `(vendor_offer_id, variation_id, location_id)`.
- **`inventory_movements` (DATA-2):** append-only, with no free text (SEC-7).
  - Columns: `movement_uuid`, `balance_id`, `delta`, `quantity_after`, `reason` (`initial`, `set`, `adjust`, `order`, `outside`, `reconcile`), `actor_id`, `created_at`.
  - Keys as DATA-2: PK `id`; UNIQUE `movement_uuid`; INDEX `(balance_id, created_at)`.
- **`media_ownership` (DATA-2):** one row per vendor upload.
  - Columns: `attachment_id`, `vendor_id`, `file` (relative to the uploads folder), `mime`, `bytes` (the original plus its generated sizes), `width`, `height`, `created_at`.
  - Keys as DATA-2: PK `attachment_id`; INDEX `(vendor_id, created_at DESC)`.
  - `GET /me/media` reads only this table.
- **`offer_availability` (DATA-2):** the projection every Mercatus list reads (CTL-9).
  - Rows: every offer has one for the product as a whole (`variation_id` `0`), which lists read; a variable product adds one per variation. Rows exist in every state except `deleted`, and `is_purchasable` says whether buyers may buy it.
  - Columns:
    - `vendor_offer_id`, `variation_id`, `location_id`, `catalog_product_id`, `vendor_id`;
    - `status` (the state the vendor sees, CTL-5), `is_purchasable`, `effective_price` and the price range, the stock status and quantity;
    - the name and SKU, for the vendor's search;
    - `card`: JSON with each language's name, link and image, and the store's name and link;
    - `geo_cell`, `latitude`, `longitude`, `updated_at`, `version` (the product's).
  - Keys as DATA-2. Lists also need cursor orders: a store's offers newest first and by price, and a vendor's products newest first. Those indexes are fixed during implementation against the scale data, as STO-17's were.
- **WooCommerce data:**
  - Products, variations, categories, tags and attachments are WooCommerce's and WordPress's, written only through CRUD (G-DB-4).
  - Mercatus writes WooCommerce's regular and sale prices, sale dates, stock quantity, stock status, "manage stock" and backorders as copies, through CRUD setters (ARCH-12).
- **Seeder slice:** §9.

## 3. Settings
Changed by an administrator only:
- `core.catalog.review_new_products`: whether a new product waits for review before it's published (OQ-2). Default: off.
- `core.catalog.upload_max_mb`: the largest file a vendor may upload (OQ-6). Default: 5.
- `core.catalog.vendor_media_quota_mb`: each vendor's total media space, generated sizes included (OQ-6). Default: 500.
- `core.catalog.show_sold_by`: whether WooCommerce's product pages and lists show "Sold by" (CTL-16). Default: on.

## 4. API
Routes follow API-1 through the route pipeline (IDN-21). A product's `{id}` is its WooCommerce product ID (CTL-3).
- **The vendor (`tenant`):**
  - `GET /me/products`: the vendor's products, newest first, with cursors, a status filter, and a search by the start of the name or by SKU. It reads the projection (CAT-5).
  - `POST /me/products`: creates a draft. `Idempotency-Key` is required, since it sets stock (PLAT-7, G-SEC-3).
  - `GET /me/products/{id}`: every field, read through WooCommerce CRUD (CAT-5 allows reading one product to edit it), with one ETag (CTL-7).
  - `PATCH /me/products/{id}` (`If-Match`): content, prices, categories, tags, images, attributes and variations.
    - Images must be the vendor's own media (CTL-14).
    - Fields such as `vendor_id`, `owner_vendor_id`, `status`, `scope` and `woo_product_id` are never read from the body (SEC-3, TM-9).
  - `DELETE /me/products/{id}`: moves the product to the trash (CTL-5).
  - `POST /me/products/{id}/publish` and `POST /me/products/{id}/unpublish` (API-1; master v2.12).
  - `PATCH /me/offers/{id}/inventory` (`If-Match`, the product's ETag): sets or adjusts stock per variation (CTL-6).
  - `POST /me/media` (a multipart upload), `GET /me/media` (newest first, with cursors) and `DELETE /me/media/{id}`, where `{id}` is the attachment ID.
  - `PATCH /me/store` (core.store's route) gains `logo_id` and `banner_id`, which must be the vendor's own media (STO-12, CTL-14).
- **Public:**
  - `GET /catalog/offers?store={store}`: one store's purchasable offers, newest first or by price, with cursors (CTL-15).
  - `GET /catalog/products/{id}/offers`: a product's purchasable offers.
  - `GET /catalog/categories?parent_id=X`: one level of the category tree, with a name search (CTL-17).
  - These send PLAT-3's L3 headers and a list ETag (PLAT-9).
- **Administrators, and managers holding `products.moderate`:** the moderation endpoints, if OQ-2 adds them (master v2.12).
- **Not found (SEC-4, TM-3, TM-4):** another vendor's product, offer or media, and a product the public can't see, are 404.
- **Idempotency:** only `POST /me/products` requires a key; every other `POST` accepts one (IDN-9).

## 5. Events and hooks
- **Events emitted (KER-8):**
  - `catalog.product.created`, `.updated`, `.submitted`, `.published`, `.unpublished`, `.approved`, `.rejected`, `.taken_down`, `.trashed`, `.restored` and `.deleted`;
  - `catalog.inventory.adjusted`;
  - `catalog.media.uploaded` and `catalog.media.deleted`.
  - Payloads carry IDs, versions and the names of changed fields, never free text such as a rejection reason (SEC-7).
- **Audit entries:** each event above, with outside changes marked as such (CTL-11).
- **Events consumed:**
  - `store.store.visibility_changed` and `store.store.status_changed`: the store's products follow (CTL-13), on the bulk lane, coalesced per vendor.
  - `store.store.updated`: cards carry the store's name and link, so a change to either rebuilds the store's cards on the bulk lane.
- **Bulk-lane work of its own:** projection rebuilds after outside edits, coalesced per product (`offer_proj:{id}`, API-3), and the start and end of scheduled sales (OQ-4).
- **WordPress and WooCommerce hooks used:**
  - product and variation saves, stock changes, status changes, trashing, restoring and deleting, for outside edits (CTL-11);
  - `wc_product_has_unique_sku` (OQ-8);
  - `woocommerce_is_purchasable` and `woocommerce_variation_is_purchasable`, so a store that closes stops selling at once (CTL-13);
  - WooCommerce's template hooks and WordPress's block hooks for "Sold by" (CTL-16).
- **Filters:** none of its own. ARCH-8 point 6 names no catalog decision point.

## 6. UI
- **Dashboard (ARCH-13):**
  - **Products,** for vendors:
    - the list, with status tabs, search and stock;
    - the product form: details, price and sale, inventory, images, categories and tags, attributes and variations, weight and dimensions;
    - publish and unpublish, and the reason when a product was rejected or taken down.
  - **Media,** for vendors: the library, uploads with progress, deleting, and the space used against the quota.
  - **Store:** the logo and banner pickers arrive (STO-12).
  - **Moderation,** for administrators and managers holding `products.moderate` (OQ-2).
  - Every screen has loading, empty and error states (VEN-19).
- **Storefront (ARCH-9):**
  - A `mercatus/store-products` block on the store page, server-rendered from the projection and paged with cursors.
  - The "Sold by" line on WooCommerce's pages (CTL-16). WooCommerce's shop and category pages stay WooCommerce's (SCOPE-3).
- **Language:** OQ-7.

## 7. Extension seams
- **Product types:** `product_type` already allows Pro's booking, subscription and auction types. A product-type contract lets Pro add a type's fields and rules without changing Core.
- **SPMV:** offers are keyed by product and vendor, and "one active offer per product" is one rule, which Pro's SPMV replaces (CAT-1). `owner_vendor_id` keeps content ownership apart from selling (CTL-1).
- **Locations:** balances and offer rows are keyed by location, so Pro's Multi-Location adds rows without touching the default one. The effective price takes the location, so Pro can add overrides (CAT-1).
- **Search:** `CatalogSearchPort` (5b, and Pro's external adapters) reads the same projection.
- **Media quotas:** the quota comes from one contract, so Pro's Memberships can give each plan its own.
- **The projection runner:** 5b's facets and Pro's projections reuse it (DRY-2).
- **Store page blocks:** the products block joins core.store's blocks; `core.reviews` adds its own.

## 8. Tests
- **TEST-3 row 6, the 5a half:**
  - unit: `EffectivePriceResolverTest` and `CatalogPolicyTest`;
  - integration: `WooProductCrudAdapterTest`, `OfferAvailabilityProjectionSyncTest`, `ProjectionDriftReconcilerTest` and `MediaOwnershipQuotaTest`;
  - security: `ProductMassAssignmentOwnershipTest` and `MediaCrossVendorDeleteTest`.
  - 5b brings the rest: `TwoTierAttributePolicyTest`, `MaterializedCategoryPathTest`, `CatalogFacetIndexQueryTest` and `HundredThousandProductFacetBenchmarkTest`.
- **Added by the threat model:**
  - `UploadMimeSvgPolicyTest` (TM-29): an SVG, a PHP file named as an image and a polyglot file are refused, and an accepted image is stored without its metadata.
  - `VendorRichTextSanitizationTest` (TM-28) gains product descriptions.
- **SEC-8 (TEST-2, TM-11):** `WooCommerceProductRestAccessTest`. A vendor can't create, change or delete products through WooCommerce's REST API, or upload through WordPress's media endpoint.
- **Identity:** `IdorActorMatrixTest` gains the product, offer and media routes (TEST-2).
- **Extra:**
  - `ProductLifecycleTest`: CTL-5's states and the actions between them.
  - `InventoryMovementJournalTest`: every stock change writes exactly one movement, and a stale `If-Match` changes nothing.
  - `OutsideEditAdoptionTest`: an administrator's price, stock and status changes in WooCommerce, and WooCommerce's stock reduction for an order, update the offer, balance and projection (CTL-11).
  - `ProductVisibilityFollowsStoreTest`: hiding, suspending and reopening a store hides and shows its products, and sales stop at once (CTL-13).
  - `ProductEndpointContractTest` and `MediaEndpointContractTest`.
  - `SoldByLineTest`, `CatalogSeedStepTest`, and a test for each answered open question that needs one (for example `SkuUniquePerVendorTest` and `MultilingualProductTest`).
- **JS (Vitest):** the Products screen, the product form and the Media screen.
- **End to end (Playwright):** a vendor creates a variable product with images and publishes it. It appears on their store page and on its WooCommerce page with "Sold by", and a buyer can add it to the cart. Suspending the vendor hides it.
- **Threat model:**

  | Threat | Tests |
  |---|---|
  | TM-3, TM-4 | `IdorActorMatrixTest`, `MediaCrossVendorDeleteTest` |
  | TM-9 | `ProductMassAssignmentOwnershipTest` |
  | TM-11 | `WooCommerceProductRestAccessTest` |
  | TM-28 | `VendorRichTextSanitizationTest` |
  | TM-29 | `UploadMimeSvgPolicyTest`, `MediaOwnershipQuotaTest` |
  | TM-38 | `StorefrontEdgeCacheHeaderTest`, with the public catalog reads added |

## 9. Scale
- **Seeder slice:** `database/seeders/steps/core.catalog.php` creates, through WooCommerce CRUD (KER-12):
  - PLAT-10's 5,000 WooCommerce product categories, which 5b's step then indexes;
  - 100,000 products spread unevenly over the 2,000 seeded vendors, so a few hold thousands; about a fifth are variable, with 3 to 5 variations each;
  - one offer per product, with its balances, movements and projection rows, and a few small images per vendor.
  - PLAT-10's 300,000 offers need several vendors per product, which is Pro's SPMV, so Pro's seeder step adds the rest. Master v2.12 says so in PLAT-10.
- **Target ceilings (Mercatus's own queries), fixed during implementation:**
  - One page of a store's products (the block, and `GET /catalog/offers?store=`): 0 warm, 1 cold.
  - `GET /catalog/products/{id}/offers`: 0 warm, 1 cold.
  - One page of `GET /me/products`: 1.
  - Saving a product: fixed, whatever the number of products. WooCommerce's own queries aren't counted.
  - A WooCommerce product list, with "Sold by" and the purchasability check: 0 warm and 1 cold for the whole page.
  - The reconciler: a fixed number per batch.
  - PLAT-10's `GET /catalog/offers` row (24 items with facets) is 5b's gate.

## 10. Decisions
- **CTL-1 Ownership (SEC-8, G-SEC-7).**
  - A vendor's rights over a product come from Mercatus's rows: the offer for selling it, and `owner_vendor_id` for its content. In Free both name the same vendor.
  - `post_author` is set to the vendor's user, for WooCommerce and other plugins, but never decides anything.
  - Vendors gain no WooCommerce capability. They change products only through Mercatus's endpoints, which call WooCommerce CRUD once `CatalogPolicy` allows it.
- **CTL-2 What lives where (ARCH-12).**
  - Content lives in the WooCommerce product: names, descriptions, images, categories, tags, attributes, variations, weight and dimensions.
  - Prices live in the offer and stock in the balances. WooCommerce's price and stock fields are copies, which Mercatus writes.
  - Descriptions are vendor rich text, cleaned on save with SEC-7's allow-list (STO-5, TM-28). The allow-list moves from core.store into a shared `RichText` class, so both modules use one list. Names and SKUs are plain text.
- **CTL-3 Product IDs.**
  - In every path and response, a product's `id` is its WooCommerce product ID, and a variation's is its WooCommerce variation ID. A catalog product and its WooCommerce product are one to one (DATA-2's UNIQUE `woo_product_id`), and API clients already hold WooCommerce IDs from the cart and the Store API.
  - The catalog product's own ID stays internal. Offers keep their own IDs, and media use attachment IDs (`media_ownership`'s primary key).
- **CTL-4 `CatalogPolicy` (IDN-14).** The third `ResourcePolicy`, covering products, offers and media.
  - The public reads a product only when buyers may see it (CTL-13).
  - A vendor reads and changes their own products, offers and media.
  - Administrators, and managers holding a new `products.moderate` permission, read any product and moderate it (OQ-2).
  - Anyone else gets 404 (SEC-4).
- **CTL-5 A product's life (API-1).**
  - **Product states:**
    - `draft` → `pending` (only when review applies, OQ-2) → `approved`;
    - `rejected` and `taken_down`, each with a reason the vendor sees;
    - `trashed`, and `deleted`.
  - **The offer** is `active` or `paused`. Publishing activates it, and unpublishing pauses it without another review.
  - Buyers see a product only when it's approved, its offer is active and its store is open (CTL-13).
  - **Trash:**
    - `DELETE` trashes the WooCommerce product. An administrator can restore it from WooCommerce's trash, and it comes back as a draft, as WordPress restores posts.
    - Nothing is removed from Mercatus's tables. A product deleted for good is marked `deleted`, so orders can still point at its offer.
- **CTL-6 Inventory (CAT-1, DATA-1).**
  - One balance per offer, variation and the default location. Quantities are exact decimals, and whole numbers in Free.
  - "Manage stock", stock status and backorders work as they do in WooCommerce, and are copied to it.
  - Every change writes a movement with the new quantity, in the same transaction; a balance never changes without one.
  - `PATCH /me/offers/{id}/inventory` sets a quantity or adjusts it by a delta.
- **CTL-7 One version per product (PLAT-9).** Any change to a product, its offer, its prices or its stock bumps `catalog_products.version`. One ETag therefore covers the product form, and `PATCH /me/offers/{id}/inventory` checks the same one, as STO-17 does for a store and its location.
- **CTL-8 Writes and transactions (ARCH-10, G-BND-6).**
  - WooCommerce writes run first, outside the transaction. Mercatus's rows, the projection rows, the audit entry and the event then commit together.
  - A failure in between leaves a WooCommerce product marked as Mercatus's, which the reconciler finishes or trashes (CTL-12).
- **CTL-9 The availability projection (CAT-5, DRY-2).**
  - One shared projection runner rebuilds a product's rows from Mercatus's tables and the product's WooCommerce content, writes only what changed, and bumps the product's revision (PLAT-5).
  - A vendor's own change rebuilds the rows in the same request (ARCH-12). Outside changes rebuild them on the bulk lane, coalesced per product.
  - The card holds everything a list renders (names, links, the image and its sizes, the store's name and link). Lists therefore never instantiate `WC_Product` or read `wp_postmeta` (ARCH-9, G-DB-1).
- **CTL-10 Caching (PLAT-3, PLAT-5).**
  - Offers, and the map from WooCommerce products to offers, are cached in `mercatus_offers`. Pages of the projection are cached in `mercatus_availability`. Each is keyed by the product's or the store's revision.
  - Public reads send PLAT-3's L3 headers; `/me/*` sends `private, no-cache`.
- **CTL-11 Outside edits are adopted (ARCH-12).**
  - These update the offer, the balance and the projection, with an audit entry and a movement marked `outside` or `order`:
    - an administrator's changes in wp-admin;
    - changes through WooCommerce's REST API or another plugin;
    - WooCommerce reducing stock for an order, until `core.orders` takes that over.
  - Status changes are adopted too: publishing approves and publishes, trashing trashes, and turning a published product into a draft, pending or private takes it down. Mercatus then applies the WooCommerce status its own rules give (CTL-13).
  - Mercatus marks its own CRUD writes, so they aren't adopted back.
- **CTL-12 The nightly reconciler (ARCH-12).**
  - It walks every catalog product in batches by ID and compares WooCommerce's prices, stock and status, and the projection, with Mercatus's rows. It adopts what the hooks missed (CTL-11) and repairs projection rows.
  - It finishes or trashes a WooCommerce product that Mercatus started but didn't finish (CTL-8). Any other WooCommerce product with no catalog product is handled as OQ-5 decides. A catalog product whose WooCommerce product is gone is marked `deleted`.
  - Each batch costs a fixed number of queries, and the health check reports what the last run fixed.
- **CTL-13 Products follow their store (STO-2, STO-13).**
  - A product is visible and purchasable only while its store is active and public. A store that's still hidden, or suspended or closed, sells nothing.
  - The WooCommerce status follows Mercatus's state:

    | Mercatus | WooCommerce |
    |---|---|
    | `draft`, `rejected` | `draft` |
    | `pending` | `pending` |
    | `approved`, offer active, store open | `publish` |
    | `approved` otherwise, `taken_down` | `private` |
    | `trashed` | `trash` |

  - A store change updates its products on the bulk lane, in batches. Until a batch finishes, the purchasability hooks already refuse the store's products, so sales stop at once.
- **CTL-14 Media ownership (SEC-7, STO-12).**
  - Every vendor upload gets its `media_ownership` row in the same request.
  - A product's images, a variation's image, and the store's logo and banner must be the vendor's own media. Otherwise the request fails with 422, without saying whose the attachment is.
  - core.store checks the logo and banner through a `MediaOwnership` contract this module implements, so core.store doesn't depend on core.catalog.
  - Deleting an image also removes it from the vendor's products and store, and refreshes their cards.
- **CTL-15 Public catalog reads in 5a (API-2).**
  - In 5a, `GET /catalog/offers` needs `store`: it lists one store's purchasable offers, newest first or by price. 5b opens it to the whole catalog, with search, filters and facets, and dropping the requirement then breaks no client.
  - `GET /catalog/products/{id}/offers` lists a product's purchasable offers, one in Free.
  - Both read the projection and the cache (CTL-9, CTL-10).
- **CTL-16 "Sold by" (SCOPE-3).**
  - WooCommerce's product pages and product lists show "Sold by {store}", linking to the store page:
    - in classic themes through WooCommerce's template hooks;
    - in block themes through a `mercatus/sold-by` block, which WordPress's block hooks place after the product title.
  - `core.catalog.show_sold_by` turns it off.
  - A list loads its products' stores in one query, together with the purchasability check (CTL-13).
- **CTL-17 Categories in 5a (CAT-3).**
  - Products use WooCommerce's categories and tags.
  - Until 5b builds the category index, `GET /catalog/categories?parent_id=X` reads one level of WooCommerce's categories, with a name search. 5b swaps the source behind the same response.
- **CTL-18 Master v2.12.**
  - API-1 forbids generic status patching, so publishing and unpublishing are action subresources, which API-2 doesn't list yet.
  - Master v2.12 adds them, together with whatever the answers below need: the moderation endpoints (OQ-2), `offer_variations` and optional prices (OQ-4), and the marketplace's own offers (OQ-5).
  - It also records in PLAT-10 that the 300,000 seeded offers come with Pro's SPMV.

## 11. Open questions
- **OQ-1 Product types.** Which kinds of product can vendors sell in Free?
  - **Options:**
    - (a) Simple and variable products, physical or virtual (no shipping), with up to 100 variations per product. Downloadable products come later: their files need a private upload path, which media ownership doesn't have.
    - (b) As (a), plus downloadable products now: vendors upload files to WooCommerce's protected downloads folder, with their own size limit and the MIME policy.
    - (c) Simple products only, with variable products later.
  - **Recommendation:** (a). DATA-2's booking, subscription and auction types stay Pro's.
- **OQ-2 Moderation.** SCOPE-2 promises "moderation basics". What do they include?
  - **Options:**
    - (a) **Review is optional, take-downs are always available:**
      - A setting, off by default, sends new products to review before they're published. Once a product is approved, its later edits don't need review.
      - Administrators, and managers holding `products.moderate`, approve or reject pending products, and take down any product. Rejecting and taking down need a reason, which the vendor sees, and the vendor gets an email (`WC_Email`).
      - A product that was taken down goes back through review.
      - Master v2.12 adds the endpoints to API-2: `GET /products`, `GET /products/{id}`, `POST /products/{id}/approve`, `POST /products/{id}/reject` and `POST /products/{id}/take-down`.
    - (b) As (a), but every new product is always reviewed.
    - (c) No review: administrators only take products down, through the same endpoints.
  - **Recommendation:** (a).
- **OQ-3 Categories, tags and variation attributes.** Who manages the taxonomy that all vendors share?
  - **Options:**
    - (a) Administrators manage categories, tags and the shared variation attributes (such as Size and Color) and their values, in WooCommerce. Vendors choose from them, and may add variation attributes of their own to one product.
    - (b) As (a), but vendors may also create tags.
    - (c) As (b), and vendors may also add values to the shared attributes.
  - **Recommendation:** (a). 5b's filters and facets are built on a clean shared taxonomy, and a vendor's own attributes cover what's unique to one product.
- **OQ-4 Prices.** What can a vendor set, and where is it kept? DATA-2 gives `vendor_offers` one `base_price`, and a variation's price has no home.
  - **Options:**
    - (a) **A regular price, plus an optional sale price with optional start and end dates:**
      - on the offer for a simple product, and per variation in a new `offer_variations` table;
      - the effective price follows the sale's dates, and a delayed bulk-lane event updates the projection when a sale starts or ends;
      - master v2.12 adds the table to DATA-2, and lets DATA-1's optional prices (a sale price, or a draft's missing price) be `NULL`.
    - (b) As (a), without sale dates: a sale price applies until the vendor removes it.
    - (c) Variation prices stay in WooCommerce's variations, and Mercatus reads them; only a simple product's price lives on the offer. This changes ARCH-12, which makes offers the source of truth for price.
  - **Recommendation:** (a). It's what WooCommerce shops expect, and `EffectivePriceResolverTest` covers it.
- **OQ-5 The marketplace's own products.** A site may already sell its own WooCommerce products, and an administrator may add more in wp-admin. Do they join Mercatus's catalog?
  - **Options:**
    - (a) **Yes, as the marketplace's own:**
      - each becomes a `marketplace` catalog product, with an offer from the marketplace (vendor `0`) at a location made from WooCommerce's store address;
      - existing products are adopted on the bulk lane when Mercatus is activated;
      - Mercatus's lists and API then show everything a buyer can buy, and `core.orders` later keeps those lines in the parent order.
    - (b) No: a product without a vendor stays a plain WooCommerce product, which Mercatus ignores. Mercatus's lists and API show vendors' products only.
    - (c) A house store: the marketplace gets its own vendor account and store, and its products belong to that store.
  - **Recommendation:** (a). It costs more work here and in `core.orders`, but buyers and API clients get one catalog.
- **OQ-6 Uploads.** What may vendors upload, and how much?
  - **Options:**
    - (a) **Images only:**
      - JPEG, PNG, GIF and WebP, plus AVIF where the server can process it; SVG is refused.
      - The type is read from the file's content, never its name.
      - Every image is re-encoded without its metadata (camera, location), which also drops anything hidden in the file. An animated GIF keeps only its first frame.
      - 5 MB per file and 500 MB per vendor, generated sizes included; both are settings.
    - (b) As (a), but images are stored as uploaded, metadata included, after the type check.
    - (c) As (a), plus PDFs, for example size guides or manuals.
  - **Recommendation:** (a). Re-encoding is the strongest defence against polyglot files (TM-29), and it stops photos revealing where a vendor lives.
- **OQ-7 Product translations.** PLAT-11 says products follow the multilingual plugin's model, where each translation is a WooCommerce product of its own.
  - **Options:**
    - (a) **Vendors write in one language, and the marketplace translates:**
      - translations are made with the multilingual plugin's own tools (WPML's translation editor with WooCommerce Multilingual, or Polylang);
      - one catalog product, one offer and one stock serve all of a product's translations: Mercatus maps each translation to the original, and keeps their prices and stock in step;
      - the cards carry each language's name, link and image, so lists show the visitor's language, falling back to the original.
    - (b) As (a), and vendors also translate their own products in the dashboard, which creates the translations through the multilingual plugin's API.
    - (c) Each translation is a catalog product and offer of its own, with its own stock.
  - **Recommendation:** (a). (b) can follow as a Pro feature, as translated store profiles do (STO-16). (c) splits one product's stock between languages.
- **OQ-8 SKUs.** WooCommerce requires every SKU to be unique across the site, so one vendor's SKU blocks another vendor's and reveals that it exists.
  - **Options:**
    - (a) Unique per vendor: Mercatus answers WooCommerce's `wc_product_has_unique_sku` check for vendors' products from its own per-vendor lookup. WooCommerce's own lookups by SKU (its REST API's `sku` filter, its CSV importer) then return the first match among vendors.
    - (b) WooCommerce's site-wide rule, with an error that doesn't name the other product.
    - (c) In WooCommerce, each SKU is prefixed with the vendor's ID; vendors see it without the prefix.
  - **Recommendation:** (a). Vendors who resell the same brands share manufacturers' SKUs, and Mercatus's own lookups always know the vendor.

## 12. Done when
- Every open question is answered and recorded here.
- Master v2.12 is merged: the product actions (CTL-18), whatever OQ-2, OQ-4 and OQ-5 need, and the note on SPMV offers in PLAT-10.
- The 5a half of TEST-3 row 6 passes with and without a persistent cache, with `UploadMimeSvgPolicyTest` and `WooCommerceProductRestAccessTest`.
- `openapi/mercatus-v1.yaml` includes this module's routes, and Redocly passes.
- The scale gate has the catalog scenarios, with their ceilings fixed.
- The end-to-end specs pass in CI, on the latest versions and on WordPress 6.6 with WooCommerce 9.2.
- No Section 2 guardrail is violated, and this file is marked Implemented for 5a. Phase 3's exit gate waits for 5b.
