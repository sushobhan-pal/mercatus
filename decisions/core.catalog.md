# `core.catalog` — Decision File

**Status:** Approved (2026-11-11)
**Phase / position:** Phase 3, module 5a in ROAD-3 (products). Before module 5b (discovery) starts, this file gains its sections and open questions, approved the same way.
**Branch:** `core.catalog`
**Depends on:** `core.store` (module 4)
**Implements master rules:** ROAD-3 row 5a, SCOPE-2 (Catalog: vendor ownership of WooCommerce products, the availability projection, media ownership and quotas, moderation basics), SCOPE-3 (Mercatus's offer block on store pages), CAT-1, CAT-5, ARCH-9, ARCH-10, ARCH-12 (price and stock copies, projection sync, the nightly reconciler), ARCH-13 (the Products, Media and Moderation screens), API-1, API-2 (Vendor products & offers, Media, and the 5a part of Catalog), API-3 (coalesced projection rebuilds), DATA-1, DATA-2 (`catalog_products`, `vendor_offers`, `offer_locations`, `inventory_balances`, `inventory_movements`, `media_ownership`, `offer_availability`), DRY-2 (the projection runner), PLAT-3 (L2 groups `mercatus_offers` and `mercatus_availability`, L3 headers on the public catalog reads), PLAT-5, PLAT-7, PLAT-9, PLAT-10, PLAT-11, SEC-2, SEC-3, SEC-4, SEC-7 (product rich text, the MIME and SVG policy), SEC-8, STO-12, TEST-2, TEST-3 row 6 (the 5a half)

Rules for this file (P-4, DRY-4): cite master IDs instead of restating them, never contradict the master document, and give each decision made here a local ID using the module's short code (for example `KER-1`). If this module needs a master rule changed, the master document changes first.

Local IDs use the code `CTL`, since `CAT` is the master document's own prefix.

---

## 1. Scope
- **In:**
  - **Catalog products and offers (CAT-1, DATA-2):**
    - a catalog product for each WooCommerce product a vendor creates, with its type (CTL-19) and its governance state (CTL-5);
    - the vendor's offer, with its prices (CTL-22) and status; one active offer per product in Free (CAT-1);
    - the offer at the vendor's default location (`offer_locations`), the only location in Free (STO-4).
  - **The marketplace's own products,** which join the catalog as the marketplace's offers (CTL-23).
  - **Inventory:** balances per offer, variation and location as the source of truth for stock, the append-only movement journal, and `PATCH /me/offers/{id}/inventory` (CTL-6).
  - **The WooCommerce product adapter (ARCH-12):** content lives in the WooCommerce product, written through CRUD, and Mercatus writes WooCommerce's price and stock copies (CTL-1, CTL-2).
  - **The vendor product endpoints (API-2):** list, create, read, change and trash, plus publish and unpublish actions, which master v2.12 adds (API-1, CTL-18).
  - **Moderation basics (SCOPE-2):** optional review of new products, and take-downs (CTL-20).
  - **The shared taxonomy:** vendors choose categories, and may create tags and attribute values (CTL-21).
  - **SKUs unique per seller** (CTL-26).
  - **Media (API-2, SEC-7):** image uploads with the MIME and SVG policy, ownership and quotas (CTL-14, CTL-24), and setting the store's logo and banner (STO-12).
  - **The availability projection (CAT-5)** and the shared projection runner (DRY-2), updated at once by a vendor's own edits (ARCH-12, CTL-9).
  - **Outside edits and drift (ARCH-12):** changes made through WooCommerce are adopted, and a nightly reconciler catches what the hooks missed (CTL-11, CTL-12).
  - **Products follow their store:** they're visible and purchasable only while their store is open (CTL-13).
  - **The storefront:**
    - a products block on the store page;
    - `GET /catalog/offers` for one store and `GET /catalog/products/{id}/offers` (CTL-15);
    - a "Sold by" line on WooCommerce's product pages and lists (CTL-16).
  - **`GET /catalog/categories`** for the category picker (CTL-17).
  - **Dashboard screens (ARCH-13):** Products and Media for vendors, and Moderation (CTL-20).
  - **Multilingual support (PLAT-11):** vendors write and translate their own products, and one offer and one stock serve every translation (CTL-25).
  - **The catalog seeder step and its query ceilings (PLAT-10),** and the end-to-end specs.
- **Out:**
  - **5b (discovery):**
    - the category index (`categories`), Tier 2 attributes, `category_attribute_maps`, `catalog_facets` and facet counts (CAT-2 to CAT-4);
    - the category schema and attribute-terms endpoints, the catalog block and the implementation of `CatalogSearchPort`;
    - search, category and attribute filters on `GET /catalog/offers`, and searching near a place (CAT-6).
  - **`core.orders`:** checkout reservations (`inventory_reservations`, ORD-1) and the single stock deduction (`StockSingleDeductionTest`). Until then, WooCommerce's own stock reduction for an order is adopted as an outside change (CTL-11).
  - **`core.shipping` and `core.tax`:** shipping classes, and a product's tax status and class. Products keep WooCommerce's defaults until those modules add the fields.
  - **Later:**
    - downloadable products and PDF uploads, decided together (CTL-19, CTL-24);
    - up-sells and cross-sells, purchase notes, and a vendor's preview of an unpublished product's page;
    - moving a product between the marketplace and a vendor (CTL-23).
  - **Pro:** SPMV, multi-location stock and prices, location promotions, variable-weight selling, the Bookings, Subscriptions and Auctions integrations, the bulk importer, catalog cloning and advanced product rules (SCOPE-2).
- **Increments (ROAD-1):**
  1. **Products, offers and inventory:**
     - the tables, the WooCommerce adapter and `CatalogPolicy`;
     - the vendor endpoints and actions, prices with scheduled sales, tags and attribute values, SKUs, and moderation;
     - the projection runner, with a vendor's own edits updating the projection at once;
     - the Products and Moderation screens.
  2. **Media:** image uploads, the MIME and SVG policy, quotas, product images, the store's logo and banner, and the Media screen.
  3. **Storefront and sync:**
     - adopting outside edits and the marketplace's own products, the nightly reconciler, and products that follow their store;
     - the store page's products block, the public catalog endpoints and the "Sold by" line;
     - multilingual support, with vendors' translations;
     - the seeder step and ceilings, and the end-to-end specs.

## 2. Data
- **`catalog_products` (DATA-2):** one row per product.
  - Columns:
    - `woo_product_id`: the WooCommerce product, in its original language (CTL-25);
    - `scope`: `vendor`, or `marketplace` for the marketplace's own products (CTL-23);
    - `owner_vendor_id`: whose content it is (CTL-1), `0` for the marketplace's own;
    - `product_type`: `simple` or `variable` (CTL-19); DATA-2's other types are Pro's;
    - `status` (CTL-5) and `status_reason`, the latest rejection or take-down reason (CTL-20);
    - `primary_category_id`: the first WooCommerce category chosen (its term ID);
    - `created_at`, `updated_at`, `version` (CTL-7).
  - Keys as DATA-2: PK `id`; UNIQUE `woo_product_id`; INDEX `(scope, status, product_type)`; INDEX `(primary_category_id, status)`.
- **`vendor_offers` (DATA-2):** one row per product and seller, with one active per product in Free.
  - Columns:
    - `catalog_product_id`, and `vendor_id` (`0` for the marketplace's own offers, CTL-23);
    - `status`: `active`, `paused` or `deleted`;
    - the prices (CTL-22): `base_price` (the regular price), `sale_price`, `sale_starts_at` and `sale_ends_at`;
    - `created_at`, `updated_at`, `version`.
  - Keys as DATA-2: PK `id`; UNIQUE `(catalog_product_id, vendor_id)`; INDEX `(vendor_id, status)`.
- **`offer_variations` (new; CTL-22, master v2.12):** a variable product's prices, one row per variation.
  - Columns: `vendor_offer_id`, `variation_id`, the same price columns as the offer, `status`, `version`.
  - Keys: PK `id`; UNIQUE `(vendor_offer_id, variation_id)`.
- **`offer_locations` (DATA-2):** the offer at its seller's default location: `vendor_offer_id`, `location_id`, `status`. Free has no local overrides (CAT-1). Keys as DATA-2.
- **`fulfilment_locations` (core.store's table):** this module adds the marketplace's own default location (`vendor_id` `0`), made from WooCommerce's store address (CTL-23).
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
    - the name and SKU, for the vendor's search and the SKU rule (CTL-26);
    - `card`: JSON with each language's name, link and image, and the store's name and link;
    - `geo_cell`, `latitude`, `longitude`, `updated_at`, `version` (the product's).
  - Keys as DATA-2. Lists and the SKU rule also need their own indexes: a store's offers newest first and by price, a vendor's products newest first, and SKUs by seller. Those are fixed during implementation against the scale data, as STO-17's were.
- **WooCommerce data:**
  - Products, variations, categories, tags, attributes and attachments are WooCommerce's and WordPress's, written only through CRUD and WordPress's own APIs (G-DB-4).
  - Mercatus writes WooCommerce's regular and sale prices, sale dates, stock quantity, stock status, "manage stock" and backorders as copies, through CRUD setters (ARCH-12).
  - Tags and attribute values that vendors create are WordPress terms (CTL-21). A product's translations are WooCommerce products of their own, linked through the multilingual plugin (CTL-25).
- **Seeder slice:** §9.

## 3. Settings
Changed by an administrator only:
- `core.catalog.review_new_products`: whether a vendor's new product waits for review before it's published (CTL-20). Default: off.
- `core.catalog.upload_max_mb`: the largest image a vendor may upload (CTL-24). Default: 5.
- `core.catalog.vendor_media_quota_mb`: each vendor's total media space, generated sizes included (CTL-24). Default: 500.
- `core.catalog.show_sold_by`: whether WooCommerce's product pages and lists show "Sold by" (CTL-16). Default: on.

## 4. API
Routes follow API-1 through the route pipeline (IDN-21). A product's `{id}` is its WooCommerce product ID, or one of its translations' IDs (CTL-3, CTL-25).
- **The vendor (`tenant`):**
  - `GET /me/products`: the vendor's products, newest first, with cursors, a status filter, and a search by the start of the name or by SKU. It reads the projection (CAT-5).
  - `POST /me/products`: creates a draft. `Idempotency-Key` is required, since it sets stock (PLAT-7, G-SEC-3).
  - `GET /me/products/{id}`: every field, translations included, read through WooCommerce CRUD (CAT-5 allows reading one product to edit it), with one ETag (CTL-7).
  - `PATCH /me/products/{id}` (`If-Match`): content, prices, categories, tags, images, attributes, variations and translations.
    - New tags and attribute values are created on the way (CTL-21).
    - `translations` holds the text in each other language (CTL-25).
    - Images must be the vendor's own media (CTL-14).
    - Fields such as `vendor_id`, `owner_vendor_id`, `status`, `scope` and `woo_product_id` are never read from the body (SEC-3, TM-9).
  - `DELETE /me/products/{id}`: moves the product to the trash (CTL-5).
  - `POST /me/products/{id}/publish` and `POST /me/products/{id}/unpublish` (API-1; master v2.12).
  - `PATCH /me/offers/{id}/inventory` (`If-Match`, the product's ETag): sets or adjusts stock per variation (CTL-6).
  - `POST /me/media` (a multipart upload), `GET /me/media` (newest first, with cursors) and `DELETE /me/media/{id}`, where `{id}` is the attachment ID.
  - `PATCH /me/store` (core.store's route) gains `logo_id` and `banner_id`, which must be the vendor's own media (STO-12, CTL-14).
- **Public:**
  - `GET /catalog/offers?store={store}`: one store's purchasable offers, newest first or by price, with cursors (CTL-15).
  - `GET /catalog/products/{id}/offers`: a product's purchasable offers, the marketplace's own included (CTL-23).
  - `GET /catalog/categories?parent_id=X`: one level of the category tree, with a name search (CTL-17).
  - These send PLAT-3's L3 headers and a list ETag (PLAT-9).
- **Administrators, and managers holding `products.moderate` (CTL-20; master v2.12):**
  - `GET /products`, filtered by status and vendor, and `GET /products/{id}`;
  - `POST /products/{id}/approve`, `POST /products/{id}/reject` and `POST /products/{id}/take-down`, the last two with a reason.
- **Not found (SEC-4, TM-3, TM-4):** another vendor's product, offer or media, the marketplace's own products for vendors, and a product the public can't see, are 404.
- **Idempotency:** only `POST /me/products` requires a key; every other `POST` accepts one (IDN-9).

## 5. Events and hooks
- **Events emitted (KER-8):**
  - `catalog.product.created`, `.updated`, `.submitted`, `.published`, `.unpublished`, `.approved`, `.rejected`, `.taken_down`, `.trashed`, `.restored` and `.deleted`;
  - `catalog.inventory.adjusted`;
  - `catalog.media.uploaded` and `catalog.media.deleted`;
  - `catalog.term.created` (CTL-21).
  - Payloads carry IDs, versions and the names of changed fields, never free text such as a rejection reason (SEC-7).
- **Audit entries:** each event above, with outside changes marked as such (CTL-11).
- **Emails (`WC_Email`):** the vendor hears when a product is approved, rejected or taken down (CTL-20).
- **Events consumed:**
  - `store.store.visibility_changed` and `store.store.status_changed`: the store's products follow (CTL-13), on the bulk lane, coalesced per vendor.
  - `store.store.updated`: cards carry the store's name and link, so a change to either rebuilds the store's cards on the bulk lane.
- **Bulk-lane work of its own:**
  - projection rebuilds after outside edits, coalesced per product (`offer_proj:{id}`, API-3);
  - the start and end of scheduled sales (CTL-22);
  - adopting the marketplace's existing products after activation (CTL-23).
- **WordPress and WooCommerce hooks used:**
  - product and variation saves, stock changes, status changes, trashing, restoring and deleting, for outside edits (CTL-11);
  - changes to WooCommerce's store address, for the marketplace's location (CTL-23);
  - `wc_product_has_unique_sku` and `woocommerce_get_product_id_by_sku` (CTL-26);
  - `woocommerce_is_purchasable` and `woocommerce_variation_is_purchasable`, so a store that closes stops selling at once (CTL-13);
  - WooCommerce's template hooks and WordPress's block hooks for "Sold by" (CTL-16);
  - the multilingual plugin's documented API for translations (CTL-25).
- **Filters:** none of its own. ARCH-8 point 6 names no catalog decision point.

## 6. UI
- **Dashboard (ARCH-13):**
  - **Products,** for vendors:
    - the list, with status tabs, search and stock;
    - the product form: details, price and sale, inventory, images, categories, tags, attributes and variations, weight and dimensions;
    - picking or creating tags and attribute values (CTL-21);
    - a language switcher for translations when a multilingual plugin is active (CTL-25);
    - publish and unpublish, and the reason when a product was rejected or taken down.
  - **Media,** for vendors: the library, uploads with progress, deleting, and the space used against the quota.
  - **Store:** the logo and banner pickers arrive (STO-12).
  - **Moderation,** for administrators and managers holding `products.moderate`: pending products, with approve, reject and take down (CTL-20).
  - Every screen has loading, empty and error states (VEN-19).
- **Storefront (ARCH-9):**
  - A `mercatus/store-products` block on the store page, server-rendered from the projection and paged with cursors.
  - The "Sold by" line on WooCommerce's pages (CTL-16). WooCommerce's shop and category pages stay WooCommerce's (SCOPE-3).
- **Language:** CTL-25.

## 7. Extension seams
- **Product types:** `product_type` already allows Pro's booking, subscription and auction types. A product-type contract lets Pro add a type's fields and rules without changing Core.
- **Downloads:** downloadable products and PDF uploads arrive together, with a private upload path (CTL-19, CTL-24).
- **SPMV:** offers are keyed by product and seller, and "one active offer per product" is one rule, which Pro's SPMV replaces (CAT-1). `owner_vendor_id` keeps content ownership apart from selling (CTL-1).
- **Locations:** balances and offer rows are keyed by location, so Pro's Multi-Location adds rows without touching the default one. The effective price takes the location, so Pro can add overrides (CAT-1, CTL-22).
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
  - `VendorRichTextSanitizationTest` (TM-28) gains product descriptions in every language, and the names of tags and attribute values that vendors create.
- **SEC-8 (TEST-2, TM-11):** `WooCommerceProductRestAccessTest`. A vendor can't create, change or delete products through WooCommerce's REST API, or upload through WordPress's media endpoint.
- **Identity:** `IdorActorMatrixTest` gains the product, offer, media and moderation routes (TEST-2).
- **Extra:**
  - `ProductLifecycleTest`: CTL-5's states and the actions between them.
  - `ProductModerationTest` (CTL-20): review on and off, reasons, the vendor's emails, and a taken-down product going back through review.
  - `VendorCreatedTermTest` (CTL-21): new tags and values are plain text, matched against existing ones and audited.
  - `ScheduledSaleProjectionTest` (CTL-22): the projection and WooCommerce's copies switch when a sale starts and ends.
  - `MarketplaceProductAdoptionTest` (CTL-23): existing and new products without a vendor become the marketplace's, at its location.
  - `MultilingualProductTest` and `VendorProductTranslationTest` (CTL-25): with WPML and with Polylang, a product's translations share one offer and one stock, and a vendor's translation creates and links the translated product.
  - `SkuUniquePerSellerTest` (CTL-26): two vendors may share a SKU, one seller can't use it twice, and lookups by SKU prefer the marketplace's product and never guess between vendors.
  - `InventoryMovementJournalTest`: every stock change writes exactly one movement, and a stale `If-Match` changes nothing.
  - `OutsideEditAdoptionTest`: an administrator's price, stock and status changes in WooCommerce, and WooCommerce's stock reduction for an order, update the offer, balance and projection (CTL-11).
  - `ProductVisibilityFollowsStoreTest`: hiding, suspending and reopening a store hides and shows its products, and sales stop at once (CTL-13).
  - `ProductEndpointContractTest`, `MediaEndpointContractTest`, `SoldByLineTest` and `CatalogSeedStepTest`.
- **JS (Vitest):** the Products screen, the product form, and the Media and Moderation screens.
- **End to end (Playwright):** a vendor creates a variable product with images and publishes it. It appears on their store page and on its WooCommerce page with "Sold by", and a buyer can add it to the cart. Suspending the vendor hides it.
- **Threat model:**

  | Threat | Tests |
  |---|---|
  | TM-3, TM-4 | `IdorActorMatrixTest`, `MediaCrossVendorDeleteTest` |
  | TM-5, TM-6 | `IdorActorMatrixTest` (the moderation routes), `ProductModerationTest` |
  | TM-9 | `ProductMassAssignmentOwnershipTest` |
  | TM-11 | `WooCommerceProductRestAccessTest` |
  | TM-28 | `VendorRichTextSanitizationTest` |
  | TM-29 | `UploadMimeSvgPolicyTest`, `MediaOwnershipQuotaTest` |
  | TM-38 | `StorefrontEdgeCacheHeaderTest`, with the public catalog reads added |

## 9. Scale
- **Seeder slice:** `database/seeders/steps/core.catalog.php` creates, through WooCommerce CRUD (KER-12):
  - PLAT-10's 5,000 WooCommerce product categories, which 5b's step then indexes;
  - 100,000 products: 99,000 spread unevenly over the 2,000 seeded vendors, so a few hold thousands, and 1,000 of the marketplace's own (CTL-23); about a fifth are variable, with 3 to 5 variations each, and some SKUs repeat across vendors (CTL-26);
  - one offer per product, with its balances, movements and projection rows, and a few small images per vendor.
  - PLAT-10's 300,000 offers need several sellers per product, which is Pro's SPMV, so Pro's seeder step adds the rest. Master v2.12 says so in PLAT-10.
- **Target ceilings (Mercatus's own queries), fixed during implementation:**
  - One page of a store's products (the block, and `GET /catalog/offers?store=`): 0 warm, 1 cold.
  - `GET /catalog/products/{id}/offers`: 0 warm, 1 cold.
  - One page of `GET /me/products`, and of `GET /products`: 1.
  - Saving a product, its SKU check included: fixed, whatever the number of products. WooCommerce's own queries aren't counted.
  - A WooCommerce product list, with "Sold by" and the purchasability check: 0 warm and 1 cold for the whole page.
  - The reconciler: a fixed number per batch.
  - PLAT-10's `GET /catalog/offers` row (24 items with facets) is 5b's gate.

## 10. Decisions
- **CTL-1 Ownership (SEC-8, G-SEC-7).**
  - A vendor's rights over a product come from Mercatus's rows: the offer for selling it, and `owner_vendor_id` for its content. In Free both name the same seller.
  - `post_author` is set to the vendor's user, for WooCommerce and other plugins, but never decides anything.
  - Vendors gain no WooCommerce capability. They change products only through Mercatus's endpoints, which call WooCommerce CRUD once `CatalogPolicy` allows it.
- **CTL-2 What lives where (ARCH-12).**
  - Content lives in the WooCommerce product: names, descriptions, SKUs, images, categories, tags, attributes, variations, weight and dimensions.
  - Prices live in the offer and stock in the balances. WooCommerce's price and stock fields are copies, which Mercatus writes.
  - Descriptions are vendor rich text, cleaned on save with SEC-7's allow-list (STO-5, TM-28). The allow-list moves from core.store into a shared `RichText` class, so both modules use one list. Names and SKUs are plain text.
- **CTL-3 Product IDs.**
  - In every path and response, a product's `id` is its WooCommerce product ID, and a variation's is its WooCommerce variation ID. A catalog product and its WooCommerce product are one to one (DATA-2's UNIQUE `woo_product_id`), and API clients already hold WooCommerce IDs from the cart and the Store API.
  - The catalog product's own ID stays internal. Offers keep their own IDs, and media use attachment IDs (`media_ownership`'s primary key).
- **CTL-4 `CatalogPolicy` (IDN-14).** The third `ResourcePolicy`, covering products, offers and media.
  - The public reads a product only when buyers may see it (CTL-13).
  - A vendor reads and changes their own products, offers and media. The marketplace's own products aren't any vendor's (CTL-23).
  - Administrators, and managers holding a new `products.moderate` permission, read any product and moderate vendors' products (CTL-20).
  - Anyone else gets 404 (SEC-4).
- **CTL-5 A product's life (API-1).**
  - **Product states:**
    - `draft` → `pending` (only when review applies, CTL-20) → `approved`;
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
- **CTL-7 One version per product (PLAT-9).** Any change to a product, its translations, its offer, its prices or its stock bumps `catalog_products.version`. One ETag therefore covers the product form in every language, and `PATCH /me/offers/{id}/inventory` checks the same one, as STO-17 does for a store and its location.
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
  - It finishes or trashes a WooCommerce product that Mercatus started but didn't finish (CTL-8). Any other WooCommerce product with no catalog product becomes the marketplace's (CTL-23). A catalog product whose WooCommerce product is gone is marked `deleted`.
  - Each batch costs a fixed number of queries, and the health check reports what the last run fixed.
- **CTL-13 Products follow their store (STO-2, STO-13).**
  - A vendor's product is visible and purchasable only while its store is active and public. A store that's still hidden, or suspended or closed, sells nothing.
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
  - The marketplace's own products name the site, without a link (CTL-23).
  - `core.catalog.show_sold_by` turns it off.
  - A list loads its products' stores in one query, together with the purchasability check (CTL-13).
- **CTL-17 Categories in 5a (CAT-3).**
  - Products use WooCommerce's categories and tags.
  - Until 5b builds the category index, `GET /catalog/categories?parent_id=X` reads one level of WooCommerce's categories, with a name search. 5b swaps the source behind the same response.
- **CTL-18 Master v2.12.** These needed the master document changed before approval; master v2.12 made them (merged 2026-11-11):
  - **API-2:**
    - the product actions `POST /me/products/{id}/publish` and `POST /me/products/{id}/unpublish`, since API-1 forbids generic status patching;
    - the moderation endpoints (CTL-20).
  - **DATA-2:** `offer_variations`; **DATA-1:** optional prices may be `NULL` (CTL-22).
  - **CAT-1:** the marketplace's own products use the same model, with the marketplace as seller `0` at its own default location, and their order lines get a child order of their own (CTL-23).
  - **PLAT-3:** `GET /catalog/products/{id}/offers` joins the public reads that send L3 headers (CTL-10).
  - **PLAT-10:** the 300,000 seeded offers come with Pro's SPMV, and Core seeds one offer per product.

- **CTL-19 Product types (OQ-1).**
  - Free sells simple and variable products, each physical or virtual (a virtual product needs no shipping). A variable product has at most 100 variations.
  - Downloadable products come later, together with PDF uploads (CTL-24): their files need a private upload path, which media ownership doesn't have.
  - DATA-2's booking, subscription and auction types stay Pro's.
- **CTL-20 Moderation (OQ-2).**
  - **Review:** `core.catalog.review_new_products`, off by default, sends a vendor's new product to review when they first publish it. Once a product is approved, later edits and republishing need no review.
  - **Moderators:** administrators, and managers holding `products.moderate`.
    - They approve or reject pending products, take down any vendor's product, and approve a taken-down product again.
    - Rejecting and taking down need a reason, which the vendor sees (`status_reason`).
  - A product that was taken down goes back through review when its vendor publishes it again, whatever the setting.
  - The vendor gets an email (`WC_Email`) when a product is approved, rejected or taken down.
  - **Endpoints,** which master v2.12 adds to API-2: `GET /products` (filtered by status and vendor), `GET /products/{id}`, `POST /products/{id}/approve`, `POST /products/{id}/reject` and `POST /products/{id}/take-down`.
  - The marketplace's own products aren't moderated: administrators manage them in WooCommerce (CTL-23).
- **CTL-21 Categories, tags and variation attributes (OQ-3).**
  - Administrators manage categories, and the shared variation attributes (such as Size and Color), in WooCommerce.
  - **Vendors:**
    - choose a product's categories;
    - pick existing tags or create new ones;
    - pick a shared attribute's values or add new ones;
    - may give one product variation attributes of its own.
  - **New tags and values:**
    - plain text, matched against the existing ones (ignoring case) before a new one is created through WordPress's term API;
    - audited with the vendor who created them (`catalog.term.created`); administrators rename, merge or delete them in WooCommerce;
    - created in the product's original language. Translating shared terms is the marketplace's job, in the multilingual plugin (CTL-25).
- **CTL-22 Prices (OQ-4).**
  - A regular price, plus an optional sale price with an optional start and end. The sale price must be lower than the regular price, and the end later than the start.
  - Simple products keep them on the offer; variable products keep them per variation, in `offer_variations`. Master v2.12 adds that table to DATA-2, and lets DATA-1's optional prices (a sale price, or a draft's missing price) be `NULL`.
  - Publishing needs a regular price, which may be zero.
  - The effective price is the sale price while the sale runs, and the regular price otherwise. A variable product's row carries its lowest and highest effective prices. The resolver also takes the location, for Pro's overrides (CAT-1).
  - When a sale starts or ends, a delayed bulk-lane event (`available_at`) rebuilds the product's projection rows. WooCommerce's copies include the sale dates, so WooCommerce's own pages agree.
- **CTL-23 The marketplace's own products (OQ-5).**
  - A WooCommerce product that no vendor created through Mercatus is the marketplace's: a `marketplace` catalog product (`owner_vendor_id` `0`) with the marketplace's offer (`vendor_id` `0`) at the marketplace's default location.
  - That location (`fulfilment_locations`, `vendor_id` `0`) is made from WooCommerce's store address, and follows it when it changes.
  - **Adoption:** when Mercatus is activated, existing products are adopted on the bulk lane in batches, by the reconciler's walk (CTL-12). From then on, new ones are adopted when they're saved (CTL-11).
  - **Management:** administrators manage them in WooCommerce. With no store to follow, they're visible whenever they're published.
  - **Listing:**
    - Mercatus's lists and API include them, and "Sold by" names the site (CTL-16);
    - `GET /catalog/offers?store=` can't list them in 5a, since they have no store; 5b's catalog-wide lists do.
  - **Orders:** their lines get a child order of their own, like any vendor's, so ORD-2's single code path and ORD-3's totals hold; they earn no vendor anything. Master v2.12 records this model in CAT-1. (The OQ-5 option first said these lines would stay in the parent order, which ORD-2 and ORD-3 don't allow; the child order was chosen on 2026-11-11.)
  - Moving a product between the marketplace and a vendor isn't in 5a.
- **CTL-24 Uploads (OQ-6).**
  - **Images only:** JPEG, PNG, GIF and WebP, plus AVIF where the server can process it. SVG is refused (SEC-7, TM-29).
  - The type is read from the file's content, never its name, and the stored file gets that type's extension.
  - Every image is re-encoded without its metadata (camera, GPS location), which also drops anything hidden in the file. An animated GIF keeps only its first frame.
  - **Limits:** `core.catalog.upload_max_mb` (5) per file and `core.catalog.vendor_media_quota_mb` (500) per vendor, generated sizes included.
  - **PDFs** come later, decided together with downloadable products (CTL-19), whose files are their likeliest use. A note for then: WordPress renders a PDF's preview with Ghostscript, which must never run on vendors' files.
- **CTL-25 Product translations (OQ-7).**
  - **One family:** one catalog product, one offer and one stock serve every translation of a product.
    - Mercatus maps each translation to the original, and accepts a translation's WooCommerce ID wherever a product ID goes (CTL-3).
    - It keeps the translations' price and stock copies in step.
  - **Vendors translate their own products in the dashboard.** When a multilingual plugin is active, the product form has a language switcher, and `GET` and `PATCH /me/products/{id}` carry `translations`, keyed by language.
    - **Translated:** the name, short description, description and slug, and each variation's description.
    - **Shared:** prices, stock, SKUs, images, weight and dimensions. Categories, tags and attribute values show their own translations (CTL-21).
  - **Creating translations:** Mercatus creates and links them through the plugin's documented API (WPML with WooCommerce Multilingual, or Polylang), through the `Multilingual` adapter (VEN-15), never as a hard dependency (PLAT-11).
    - Translations made in the plugin's own tools are adopted as outside edits (CTL-11).
  - **A translation follows its original:** the same status, moderation and store (CTL-5, CTL-13). Its text is vendor rich text, cleaned like the original's (TM-28).
  - Cards carry each language's name, link and image, so lists show the visitor's language, falling back to the original.
  - Store profiles keep one language per store (STO-16).
- **CTL-26 SKUs (OQ-8).**
  - A SKU is unique per seller, with the marketplace counting as one seller.
  - Mercatus answers WooCommerce's `wc_product_has_unique_sku` check from the projection, which holds every product's and variation's SKU with its seller. Errors never name the other product.
  - **Lookups by SKU alone** (`wc_get_product_id_by_sku()`, which WooCommerce's CSV importer uses): Mercatus's answer to `woocommerce_get_product_id_by_sku` is the marketplace's own product if there is one, the only match if just one seller uses the SKU, and nothing if several vendors do. An administrator's import therefore never updates a vendor's product by accident.
  - Mercatus's own lookups always know the seller.

## 11. Open questions
None. All eight were answered on 2026-11-11 and are recorded as CTL-19 to CTL-26.

## 12. Done when
- Master v2.12 is merged (CTL-18).
- The 5a half of TEST-3 row 6 passes with and without a persistent cache, with `UploadMimeSvgPolicyTest` and `WooCommerceProductRestAccessTest`.
- `openapi/mercatus-v1.yaml` includes this module's routes, and Redocly passes.
- The scale gate has the catalog scenarios, with their ceilings fixed.
- The end-to-end specs pass in CI, on the latest versions and on WordPress 6.6 with WooCommerce 9.2.
- No Section 2 guardrail is violated, and this file is marked Implemented for 5a. Phase 3's exit gate waits for 5b.
