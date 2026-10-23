# Mercatus threat model

**Status:** Living document, started in Groundwork ticket 4 (2026-10-23).
**Rules:** GW-9, SEC-1 to SEC-8, TEST-2, TEST-3. Mitigations cite master rule IDs instead of restating them (DRY-4).
**Method:** STRIDE per actor and surface, cross-checked against the OWASP API Security Top 10 (2023). Every threat is mapped to the tests that prove its mitigation.

## How this document is kept honest

- Each threat lists the tests that prove its mitigation. A test is either a TEST-3 test, which its module must ship before its ticket closes, or one of the tests this model adds (see the end of this document).
- Each added test has an owner module. When that module's decision file is written, its Tests section must name the test.
- `tests/Security/ThreatModelCoverageTest.php` checks all of this on every commit:
  - every threat names at least one test, and every test is in TEST-3 or in the added list;
  - every added test is used, isn't already in TEST-3, and appears in its owner's decision file once that file exists;
  - every mitigation cites a rule ID that the master document defines;
  - every SEC-1 actor, every surface below, every STRIDE category and every OWASP API Top 10 item appears in at least one threat.
- New routes need no new rows: the generated security matrix covers them automatically (TEST-2, `PolicyMatrixDataProvider`). A module adds rows when it opens a new kind of surface or flow, such as uploads, a webhook or a new actor.

## Assets

- **Money:** ledger balances, holds, commissions, withdrawals, payouts, wallet credit (MON-8, PRO-3 to PRO-5).
- **Orders:** parent and child orders, allocations, tax and commission snapshots (ORD-1 to ORD-12, TAX-5).
- **Personal data:** customer and vendor contact details, addresses, KYC documents, delivery proofs, payout details (SEC-6, SEC-7).
- **Secrets:** payment-provider API keys, tax registration numbers, application passwords (SEC-1, SEC-6).
- **Marketplace integrity:** catalog content, reviews, store settings, the WooCommerce configuration.
- **Availability:** response times and database capacity at marketplace scale (PLAT-7 to PLAT-10).

## Actors

The actors are SEC-1's. Trust runs from none (`anonymous`) to full (`administrator`).

| Actor | Trust | Authenticates with |
|---|---|---|
| `anonymous` | None | Nothing |
| `customer` | Own orders, reviews and wallet | WordPress cookie plus nonce |
| `vendor_owner` | Own store and its resources | WordPress cookie plus nonce, or an application password |
| `vendor_staff` | The part of a store its owner grants | WordPress cookie plus nonce |
| `delivery_agent` | Assigned deliveries only (Pro) | WordPress cookie plus nonce |
| `affiliate` | Own referrals only (Pro) | WordPress cookie plus nonce |
| `marketplace_manager` | Named permissions only | WordPress cookie plus nonce |
| `administrator` | Full; trusted but audited | WordPress cookie plus nonce |
| `integration_client` | Its scopes only | Application password mapped to an API client, with scopes, expiry, revocation and an optional IP allow-list |
| `webhook_provider` | None until its signature is verified | HMAC signature over the raw body, timestamp window, event-ID store |

## Surfaces

| Surface | What crosses the boundary | Guarded by |
|---|---|---|
| **REST API** | Every business action, under `/wp-json/mercatus/v1` | SEC-2 pipeline, JSON Schema (SEC-3), idempotency and ETags (PLAT-7, PLAT-9), route metadata and the generated matrix (TEST-2) |
| **Marketplace dashboard** | The one frontend app all roles share (ARCH-13); renders vendor-supplied content | Output escaping, CSP (SEC-7) |
| **WooCommerce surfaces** | Checkout hooks, My Account, emails, wp-admin, the WooCommerce REST API | No WooCommerce capabilities for vendors (SEC-8), `ChildOrderExposure` (ORD-7) |
| **Webhooks** | Inbound provider callbacks (Pro payment integrations) | SEC-1 webhook verification |
| **Outbound requests** | Geocoding, payment providers, outgoing webhooks, AI | Outbound allow-list (SEC-7) |
| **Uploads and private files** | Media, KYC documents, delivery proofs | MIME and SVG policy, files outside the web root behind signed URLs (SEC-6, SEC-7) |
| **Workers and CLI** | Outbox poller, Action Scheduler, cron, WP-CLI | System actor scoped to the worker (SEC-1) |
| **Data at rest** | Database, object cache, backups | Encryption of secrets (SEC-6), immutable journals (MON-8), audit log (API-3) |
| **Extension points** | Pro modules and third-party plugins | Contracts, shared kernel and published hooks only (ARCH-4, ARCH-8) |

## OWASP API Security Top 10 (2023)

API1 Broken Object Level Authorization · API2 Broken Authentication · API3 Broken Object Property Level Authorization · API4 Unrestricted Resource Consumption · API5 Broken Function Level Authorization · API6 Unrestricted Access to Sensitive Business Flows · API7 Server Side Request Forgery · API8 Security Misconfiguration · API9 Improper Inventory Management · API10 Unsafe Consumption of APIs.

## Threats

STRIDE: S spoofing, T tampering, R repudiation, I information disclosure, D denial of service, E elevation of privilege.

| ID | STRIDE | OWASP | Actor | Surface | Threat | Mitigation | Tests |
|---|---|---|---|---|---|---|---|
| TM-1 | S | API2 | `anonymous` | REST API | Another site makes a signed-in user's browser send a state-changing request (CSRF). | SEC-1 (cookie plus nonce) | `CsrfNonceEnforcementTest` |
| TM-2 | S, E | API2, API5 | `integration_client` | REST API | A leaked, expired or revoked application password still works, or works outside its scopes or IP allow-list. | SEC-1, SEC-2 (scope check) | `ApiClientScopeIntegrationTest`, `IdorActorMatrixTest` |
| TM-3 | I, T | API1 | `vendor_owner`, `vendor_staff` | REST API | A vendor reads or changes another vendor's store, products, offers, media, child orders, shipments, zones, tax rates, earnings or withdrawals by changing an ID. | SEC-2 (resource policies), SEC-4, TEST-2 | `IdorActorMatrixTest`, `StoreCrossTenantIsolationTest`, `CrossVendorChildOrderAccessDeniedTest`, `MediaCrossVendorDeleteTest`, `WithdrawalIdorTest` |
| TM-4 | I | API1 | `vendor_owner`, `customer` | REST API | Different answers for "not yours" and "doesn't exist" let an actor enumerate other tenants' orders, withdrawals or unpublished offers. | SEC-4, G-SEC-4 | `IdorActorMatrixTest`, `StoreCrossTenantIsolationTest` |
| TM-5 | E | API5 | `customer`, `vendor_owner` | REST API | A non-admin runs an admin action: approving withdrawals or tax rates, suspending a store, completing or cancelling a parent order, flushing a cache group. | SEC-2, API-1 (action subresources) | `IdorActorMatrixTest`, `PolicyEngineMatrixTest`, `CacheFlushAuthorizationTest` |
| TM-6 | E | API5 | `marketplace_manager` | REST API, Marketplace dashboard | A manager acts outside their named permissions, for example approving withdrawals or changing commission settings. | SEC-2 (named permissions), ARCH-13 | `PolicyEngineMatrixTest`, `DashboardMenuCompilerTest`, `IdorActorMatrixTest` |
| TM-7 | E | API1, API5 | `vendor_staff` | REST API | Staff act outside the scope their owner granted, or at locations they aren't assigned to. | SEC-2, TEST-2 (staff scope) | `IdorActorMatrixTest`, `StaffCrossLocationMutationBlockTest` |
| TM-8 | E | API5 | `vendor_owner`, `vendor_staff` | REST API | A suspended vendor or removed staff member keeps access through a cached identity. | SEC-2 (identity revision), G-CACHE-4 | `CachedActorIdentityInvalidationTest`, `ActorIdentityResolverTest` |
| TM-9 | T, E | API3 | `vendor_owner`, `customer` | REST API | A request body sets fields the actor may not set, such as `vendor_id`, `approved_by`, `commission_amount`, `tax_amount`, `balance_minor` or a status (mass assignment). | SEC-3, TEST-2 | `ProductMassAssignmentOwnershipTest`, `ReviewMassAssignmentTest`, `IdorActorMatrixTest` |
| TM-10 | I | API3 | `anonymous`, `customer`, `vendor_owner` | REST API | A response exposes another party's private fields: vendor contact details, KYC or payout data, secrets. | SEC-2 (field-level visibility), SEC-6 | `StorePiiFieldRedactionTest`, `IdorActorMatrixTest` |
| TM-11 | E | API5, API8 | `vendor_owner`, `vendor_staff` | WooCommerce surfaces | A vendor uses WooCommerce capabilities to bypass Mercatus policies in wp-admin or the WooCommerce REST API, or changes marketplace-wide WooCommerce settings. | SEC-8, G-SEC-7, ARCH-12 | `VendorGlobalWooSettingsIsolationTest`, `IdorActorMatrixTest` |
| TM-12 | I | API1 | `vendor_owner` | WooCommerce surfaces, REST API | A vendor opens the parent order and sees other vendors' items or the customer's whole order. | ORD-7 | `VendorParentOrderAccessDeniedTest`, `ChildOrderExposureTest` |
| TM-13 | I | — | `customer` | WooCommerce surfaces | Child orders show up in My Account, customer emails or Analytics, duplicating orders or exposing one vendor's details out of context. | ORD-7 (`ChildOrderExposure`) | `ChildOrderExposureTest`, `CrossVendorChildOrderAccessDeniedTest` |
| TM-14 | T | API1, API6 | `customer` | REST API | A customer confirms receipt of someone else's vendor order to trigger completion and release earnings early. | ORD-12, SEC-4 | `IdorActorMatrixTest`, `CompletionTriggerTest` |
| TM-15 | T | API6 | `vendor_owner` | REST API | Parallel withdrawal requests spend the same balance twice. | MON-8 (optimistic `version`), MON-10, PLAT-7 | `ConcurrentWithdrawalDoubleSpendTest`, `LedgerJournalOptimisticLockTest` |
| TM-16 | T | API3, API5 | `vendor_owner`, `marketplace_manager` | REST API | A balance is edited directly instead of through journal entries. | MON-8, G-MONEY-4 | `LedgerBalancePatchRouteAbsenceTest` |
| TM-17 | T | API6 | `vendor_owner`, `integration_client` | REST API | A retried or replayed financial `POST` runs twice: two withdrawals, payouts or refunds. | PLAT-7, G-SEC-3 | `IdempotencyStoreIntegrationTest`, `IdorActorMatrixTest` |
| TM-18 | T | API6 | `vendor_owner`, `marketplace_manager` | REST API | Two editors overwrite each other's changes. | PLAT-9 (`If-Match`) | `EtagFromVersionTest`, `IdorActorMatrixTest` |
| TM-19 | T | API6 | `marketplace_manager`, `administrator` | Data at rest | Commission or tax snapshots are recalculated after a rule changes, rewriting past vendor earnings. | MON-1, TAX-5, G-MONEY-3, G-MONEY-6 | `CommissionSnapshotImmutabilityTest`, `TaxSnapshotFromOrderLinesTest`, `OrderRecalculationTaxSafetyNetTest` |
| TM-20 | T | API6 | `customer` | WooCommerce surfaces | An unpaid order (such as an on-hold bank transfer) or a duplicated payment hook creates child orders and vendor earnings, or a Subscriptions renewal skips the split. | ORD-1, ORD-2, PRO-3 | `IdempotentOrderSplitRetryTest`, `StageTwoChildOrderMaterializationTest`, `RenewalOrderSplitTest` |
| TM-21 | T, R | API6 | `vendor_owner` | REST API | A vendor withdraws earnings before a refund, so the marketplace can't claw them back. | MON-9, ORD-11, MON-8 | `EarningsReleaseRuleTest`, `RefundClawbackNegativeBalanceTest` |
| TM-22 | E, R | API6 | `administrator`, `marketplace_manager` | REST API | One person both creates and approves their own payout or withdrawal. | PRO-5 | `SelfApprovalPreventionTest`, `FourEyesApprovalPolicyTest` |
| TM-23 | S, T | API2, API10 | `webhook_provider` | Webhooks | A forged or replayed provider webhook marks payouts paid or triggers transfers. | SEC-1 (webhook verification), PRO-3 | `WebhookSignatureReplayTest`, `DuplicatePayoutWebhookReplayTest` |
| TM-24 | T | API10 | `webhook_provider` | Outbound requests, Webhooks | Mercatus trusts a provider's answer blindly: a wrong amount or status, or a refund that doesn't reverse the vendor's split share. | PRO-3, ORD-11 | `SplitRefundReversalTest`, `PayoutProviderConformanceTest` |
| TM-25 | T | API6 | `customer` | REST API | A customer tops up wallet credit, or spends the same credit twice in parallel. | PRO-4 | `WalletTopUpRouteAbsenceTest`, `ConcurrentWalletSpendRaceConditionTest` |
| TM-26 | T | API6 | `vendor_owner` | REST API | A vendor records an inflated weight, changes it after the lock, or charges outside the approved tolerance. | PRO-7 | `OutOfToleranceUnapprovedChargeBlockTest`, `PostLockMeasurementTamperTest` |
| TM-27 | T | API6 | `vendor_owner` | REST API, WooCommerce surfaces | A vendor's own tax rates apply without approval, or a vendor without approved rates sells untaxed. | TAX-3, TAX-4 | `UnapprovedTaxRatesFallbackTest`, `StoreTaxPolicyTest` |
| TM-28 | T, E | API8 | `vendor_owner`, `vendor_staff` | Marketplace dashboard | Script stored in vendor rich text (store or product descriptions, review replies) runs in another user's session, including an administrator's, because every role shares one dashboard. | SEC-7 (clean on save, escape on output, CSP), G-SEC-5 | `VendorRichTextSanitizationTest`, `DashboardContentSecurityPolicyTest` |
| TM-29 | T, E | API8 | `vendor_owner` | Uploads and private files | An upload carries script or code: an SVG with script, a PHP file disguised as an image, a polyglot file. | SEC-7 (MIME and SVG policy) | `UploadMimeSvgPolicyTest`, `MediaOwnershipQuotaTest` |
| TM-30 | I | API1 | `anonymous`, `vendor_owner` | Uploads and private files | KYC documents or delivery proofs are downloaded by guessing their URLs. | SEC-6 (outside the web root, short-lived signed URLs) | `SignedPrivateFileUrlTest` |
| TM-31 | I | API7 | `vendor_owner` | Outbound requests | A vendor-supplied URL (tracking-link template, image import, webhook endpoint, geocoding input) makes the server call internal addresses, or shows customers a `javascript:` link. | SEC-7 (outbound allow-list) | `OutboundRequestAllowListTest`, `TrackingUrlTemplateTest` |
| TM-32 | I | — | anyone holding a database copy | Data at rest | A database dump or backup leaks payment-provider keys or tax registration numbers. | SEC-6, G-SEC-6 | `SecretBoxEncryptionTest` |
| TM-33 | I, R | — | `customer`, `vendor_owner` | Data at rest | Personal data can't be exported or erased, or sits as free text in append-only tables. | SEC-7 (GDPR export and erase) | `PersonalDataExportEraseTest` |
| TM-34 | R | — | `administrator`, `marketplace_manager`, `vendor_owner` | Data at rest | Someone denies having made a money or status change. | API-3 (audit log written in the command's transaction) | `OutboxTransactionalWriteTest` |
| TM-35 | T, I | API8 | `anonymous` | REST API | SQL injection through sort, filter or cursor parameters. | SEC-3, G-SEC-2 | `SortAndFilterAllowListTest`, `CatalogFacetIndexQueryTest` |
| TM-36 | D | API4 | `anonymous` | REST API | Expensive requests (huge pages, deep offsets, unbounded facet queries) or a cache stampede exhaust the database. | API-1 (cursor pagination), PLAT-3, PLAT-10, G-DB-3 | `HundredThousandProductFacetBenchmarkTest`, `SingleflightLockConcurrencyTest`, `BaselineQueryAndLatencyGateTest` |
| TM-37 | D | API4, API6 | `anonymous`, `customer` | REST API | Request floods: credential stuffing, spam vendor applications or reviews. | PLAT-8 (see AR-1) | `RateLimiterTest` |
| TM-38 | I | API8 | `anonymous` | REST API | The health endpoint reveals versions and configuration, or cache headers let a CDN serve one user's private response to another. | PLAT-3 (HTTP cache headers), PLAT-4 | `HealthEndpointAuthTest`, `StorefrontEdgeCacheHeaderTest` |
| TM-39 | S, E | — | `anonymous` | Workers and CLI | An HTTP request poses as cron or a worker to run jobs as the system actor. | SEC-1 (system actor scoped to the worker) | `CronContextSpoofingTest` |
| TM-40 | E | API9 | `anonymous` | REST API | An undocumented route, or an inactive module's route or hook, escapes policies and documentation. | ARCH-6, ARCH-8 (registrar only), DRY-1 | `OpenApiSchemaConformanceTest`, `InactiveModuleZeroFootprintTest`, `IdorActorMatrixTest` |
| TM-41 | E | API5 | `vendor_owner` | REST API | A Pro route keeps working after its licence lapses or its module is switched off. | SEC-2 (module entitlement check) | `UnentitledProFinanceRouteBlockTest`, `InactiveModuleZeroFootprintTest` |
| TM-42 | E, T | — | Pro or third-party code | Extension points | Extension code reaches Core internals or tables and skips Core's policies. | G-BND-4, ARCH-4, ARCH-8 | `DependencyRulesTest` |
| TM-43 | T | API1, API5 | `vendor_owner`, `customer` | REST API | A vendor moderates or replies to another store's reviews, or a customer reviews a store they never bought from. | SEC-2, SEC-4 | `CrossStoreReviewModerationBlockTest`, `VerifiedReviewEligibilityTest` |
| TM-44 | T | API1 | `vendor_owner` | REST API | A vendor changes tracking details on another vendor's shipment. | PRO-2, SEC-4 | `CrossVendorTrackingUpdateBlockTest` |
| TM-45 | I, E | API1 | `delivery_agent` | REST API | A delivery agent opens orders or customer addresses that aren't assigned to them. | PRO-6, SEC-2 | `DeliveryAgentAssignmentScopeTest` |
| TM-46 | S | API2, API6 | `delivery_agent`, `customer` | REST API | A delivery OTP is guessed by brute force; without a persistent cache there is no rate limiting to stop it. | PRO-6, PLAT-6 (the attempt limit lives in the database) | `DeliveryOtpAttemptLimitTest` |
| TM-47 | I, T | API1, API6 | `affiliate` | REST API | An affiliate sees orders or customers beyond their own referrals, or refers their own purchases. | SEC-1, SEC-2 | `AffiliateAttributionScopeTest` |

## Accepted risks

- **AR-1 No rate limiting without a persistent object cache** (PLAT-8). Floods (TM-37) must then be stopped at the page cache, CDN or firewall, and the health check says so. Anything guessable, such as an OTP (TM-46), keeps its own attempt limit in the database instead of relying on rate limits.
- **AR-2 Administrators are fully trusted.** A WordPress administrator can run arbitrary code, so Mercatus aims for accountability rather than prevention: the audit log (TM-34) and, in Pro, four-eyes approval of payouts (TM-22).
- **AR-3 Other plugins share the PHP process.** They can bypass anything; Mercatus guarantees only its own extension points (TM-42).
- **AR-4 WordPress, WooCommerce and gateway vulnerabilities are out of scope.** Mercatus limits its exposure by never granting vendors WooCommerce capabilities (TM-11).
- **AR-5 No OAuth2 in version 1** (SEC-1). Apps use application passwords with scopes, expiry and revocation (TM-2).

## Tests this model adds

Tests the TEST-3 matrix doesn't yet name. The owner is the first module that builds the risky feature; its decision file adopts the test. If the test later moves into TEST-3, it moves out of this table.

| Test | Owner | Threats |
|---|---|---|
| `VendorRichTextSanitizationTest` | core.store | TM-28 |
| `DashboardContentSecurityPolicyTest` | core.vendor | TM-28 |
| `UploadMimeSvgPolicyTest` | core.catalog | TM-29 |
| `SignedPrivateFileUrlTest` | core.vendor | TM-30 |
| `OutboundRequestAllowListTest` | core.store | TM-31 |
| `SecretBoxEncryptionTest` | core.tax | TM-32 |
| `PersonalDataExportEraseTest` | core.vendor | TM-33 |
| `SortAndFilterAllowListTest` | core.identity | TM-35 |
| `DeliveryAgentAssignmentScopeTest` | Delivery (PRO-6) | TM-45 |
| `DeliveryOtpAttemptLimitTest` | Delivery (PRO-6) | TM-46 |
| `AffiliateAttributionScopeTest` | Affiliate (Phase 6) | TM-47 |
