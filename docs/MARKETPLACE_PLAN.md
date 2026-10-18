# Waimart Multi-Vendor Upgrade — Phase 1: Inspection, Impact Analysis and Plan

**Date:** 18 October 2026 · **Status:** Phases 1–6 complete (see the "Phase N results" sections at the end); awaiting approval to start Phase 7

This document is for the client and for whoever builds or reviews the upgrade. It records the current system as inspected, what a multi-vendor marketplace changes, the target design, and the phased plan. No code or data has been changed in Phase 1.

---

## 1. Inspection findings

### 1.1 Environment
Windows 11 · XAMPP · PHP 8.2.12 · MariaDB 10.4.32 · Apache with `mod_rewrite` · Composer · Node + Edge (browser tests). The project is at `C:\xampp\htdocs\ecommerce`. It is **not** a git repository, so source backups must be file copies (see Phase 2).

### 1.2 Baseline test status (before any change)
`vendor/bin/phpunit`: **263 tests, 1,683 assertions, all passing** (Unit 38, Integration 142, Feature 47, Http 36). The browser checks — checkout E2E, accessibility, responsive and submitter — passed on the same day. This is the regression baseline: every later phase must keep it green.

### 1.3 Data currently in the `waimart` database
47 tables, migrations `001`–`009` applied.

| Data | Rows |
|---|---|
| Products / variants / inventory rows | 56 / 67 / 67 (demo catalogue) |
| Orders | 3: 1 pending COD confirmation, 2 expired. All from browser tests. |
| Order items / reservations / stock movements | 3 / 3 / 70 |
| Payments, refunds, returns, reviews, COD records | 0 |
| Users | 1: the suspended test account. **No administrator has been created yet.** |
| Categories / brands / zones / pickup points | 51 / 10 / 4 zones / 6 points |

The data is small and mostly demo, but the migrations will be written and tested as if it were production data: nothing is deleted, and every existing row gets a valid owner or mapping.

### 1.4 Current architecture (relevant parts)
- **Stack:** framework-free PHP with a front controller. There are 40 routes, each guarded by `auth`, `staff`, `can:<permission>`, `throttle` and CSRF middleware. Services hold all business rules, repositories hold SQL, and views are PHP templates. About 15,000 lines across 184 files.
- **Users and permissions:** one `users` table. Staff are users with `is_staff = 1` plus roles (`user_roles` → `role_permissions`). Customers are users with `is_staff = 0`. Permissions apply **only** to staff, so a non-staff user can never reach `/admin` (Kernel `staff` middleware).
- **Catalogue:** `products` → `product_variants` (global unique `sku`, one default variant) → `inventory` (`on_hand`, `reserved`, DB CHECKs). There is **no owner column on products**.
- **Orders (single merchant):**
  - **One row per purchase:** `orders` holds payment method and status, one order status machine (`OrderWorkflow`), and one delivery fee.
  - **One fulfilment record per order:** `order_fulfilment` (keyed by `order_id`) holds the destination, the pickup collection code, and the ready / dispatched / delivered / collected timestamps.
- **Inventory:** reservations are per `order_item`. They are created atomically at placement and released/expired/consumed exactly once. Consumption happens at **order-level** `dispatched` (delivery) or `collected` (pickup).
- **Payments:** manual M-Pesa submissions verified by finance staff, plus COD cash records. Both are **per order**: `payment_submissions`, `payment_verifications`, `cod_collections` (UNIQUE order).
- **Returns and refunds:** `return_requests` allows one open return **per order**. `refunds` belong to an order.

### 1.5 The key structural constraint
Everything after payment is modelled **per order**: order status, fulfilment, collection, stock consumption, COD collection, returns. A marketplace needs these **per seller within an order**. That is the centre of the upgrade.

Everything **before** fulfilment can stay as it is, with additions:
- cart, pricing, checkout transaction and idempotency key;
- reservations, which are already per item;
- payment submission and verification, which stay per parent order;
- customer accounts and order access.

---

## 2. Change-impact analysis

### 2.1 Unchanged (reused as they are)
- **Platform features:** security core (router, CSRF, sessions, rate limiter, crypto, audit logger), customer accounts, guest access and tracking tokens, notifications, settings, banners, delivery zones and pickup points, expiry worker.
- **Order and payment data:** reservation ledger and `stock_movements`, payment submission and verification, late-payment resolution.
- **Front end:** branding, theme, layout and responsive design.

### 2.2 Extended
| Area | Change |
|---|---|
| `products` | + `seller_id` (FK, NOT NULL after back-fill) and `moderation_status` (draft / pending / published / rejected / archived). Public visibility = active **and** published **and** seller active. |
| Catalogue queries | `CatalogueRepository`, `ProductListing` and `ProductRepository` add the seller visibility rule and store attribution. This is one shared WHERE fragment, so it can't drift between pages. |
| `order_items` | + `seller_id`, `seller_order_id`, plus commission snapshots: `commission_rate`, `commission_amount`, `seller_net_amount`. |
| `orders` | Unchanged columns. The parent's fulfilment statuses become a **roll-up** of its seller orders. |
| `OrderService::place` | In the same transaction, also creates one seller order per seller, allocates discounts and fees, and snapshots commission. |
| `OrderWorkflow` | Parent transitions (payment / confirmation / expiry / cancel) unchanged. Fulfilment transitions move to a new `SellerOrderWorkflow`; the parent status is derived from it. |
| `ReservationService` | + `consumeForSellerOrder()` and `releaseForSellerOrder()`: same guarded exactly-once logic, scoped to one seller's items. |
| `CollectionService` | One collection code per parent order (unchanged for the customer). Readiness and collection are recorded **per seller order**. |
| `CodService` | Records COD cash per parent order (as now) and allocates it to seller orders in the ledger. |
| `return_requests`, `refunds` | + `seller_order_id`. The open-return rule becomes per seller order, so returning Seller A's items never touches Seller B. |
| Delivery pricing | Decision: **one zone fee per order**, as today, kept by the platform and never part of seller earnings. The policy is still a setting, with "per seller group" available later. |
| Admin | Seller, product-moderation, commission, payout, ledger and dispute screens, plus new permissions. Existing screens show the seller column and filter. |
| Storefront | "Sell on Waimart" link, store pages `/store/{slug}`, "Sold by" on product cards, product pages, cart and order pages. |

### 2.3 New tables (migrations 010+)
| Table | Purpose |
|---|---|
| `sellers` | Store and business profile: name, unique `slug`, logo, description, public contacts, county/town, `status` (pending / active / suspended / closed), `is_platform`, commission override, payout eligibility settings |
| `seller_users` | Membership: which users belong to which seller, with seller role `owner` / `manager` / `staff`. Kept separate from staff roles, so a seller can never hold admin permissions. |
| `seller_applications` + `seller_application_history` | Application form data, status (draft / submitted / under review / approved / rejected), rejection reason, resubmission flag, reviewer and timestamps |
| `seller_documents` | Private uploads (configurable required types), stored **outside the web root** in `storage/private/seller-docs`, with random names, MIME/size checks, and downloads through an authorised controller |
| `seller_payout_methods` | Payout details (M-Pesa number / bank), **encrypted at rest** with the existing AES-GCM `Crypto`, shown masked |
| `seller_orders` | Seller suborder: `order_id` + `seller_id` (UNIQUE), number (e.g. `WM-7K3F9Q2X-1`), status, merchandise subtotal, discount share, delivery-fee share, commission, net, per-seller fulfilment fields (accepted, dispatched + tracking ref + note, delivered, ready at pickup, collected) |
| `seller_order_history` | Every seller-order transition, with actor |
| `commission_rules` | Default / category / seller rates, with effective dates. Precedence: **seller override > category > default**. The rate in force is snapshotted on each order item, so later rate changes never alter history. |
| `seller_ledger_entries` | Immutable money ledger (§3.4). Unique keys stop the same posting being recorded twice. |
| `seller_payouts` + `seller_payout_history` | Payout requests: requested → approved → paid, or rejected / cancelled. Records method, reference, amount paid, and actors. |
| `seller_disputes` + `seller_dispute_messages` | Disputes on a seller order, handled by admin; an open dispute blocks payout eligibility |

### 2.4 Historical data migration (preserving records)
1. **Platform store:** create **"Waimart Official Store"** (`is_platform = 1`, active, commission 0%) and assign all 56 existing products to it. None are left without an owner, and none go to a third-party seller.
2. **Existing orders:** each gets one seller order for the platform store. Its status and fulfilment timestamps are copied from `orders` / `order_fulfilment`, and its items are linked to it.
3. **Commission snapshots:** set to 0 / "pre-marketplace" for historical items. No ledger entries are back-filled for platform-store sales, so existing reports and totals don't change.
4. **Staff:** stay staff. If the shop owner also sells as the platform store, they become `owner` of it through `seller_users`, which grants no admin rights.
5. **Safety:** each migration runs after a `bin/backup.php` dump. Each is tested on a copy (restore into `waimart_test`, migrate, compare row counts and checksums of untouched tables) before running on `waimart`.

---

## 3. Target design (summary)

### 3.1 Authorisation model
- **Three roles:** admin = `staff` middleware + `can:` permissions (as now). Seller = new `seller` middleware: signed in, active membership of an **active** seller, and the seller-role check. Customer = `auth`.
- **Seller context:** the seller comes from server-side membership, **never from request input**. Every seller repository query is written as `… WHERE seller_id = :currentSeller`, and the IDs in URLs are always looked up within that scope. Another seller's record therefore returns **404**, which reveals no more than a missing record.
- **Route checks:** the route sweep test (`RouteSecurityTest`) is extended so every `/seller/*` route must carry `seller`. A new IDOR test matrix tries Seller B's products, orders, items, payouts, documents and store settings as Seller A, through URL, body and hidden fields.
- **Suspended sellers:** can sign in read-only (orders and money stay visible). They can't publish, edit prices or stock, accept new orders or request payouts. Their products disappear from the storefront and their cart lines become unavailable.

### 3.2 Multi-seller checkout
- **Placement:** one cart, one checkout, one payment, one parent order (unchanged idempotency key). Then, **inside the same transaction:**
  - lock and recheck stock (as now);
  - create the parent order;
  - create one seller order per seller and link the items;
  - reserve stock (as now);
  - snapshot commission;
  - allocate the delivery fee and discount per policy.

  A failure anywhere rolls back everything. The C9 concurrency suites are rerun with multi-seller carts.
- **Customer view:** one order page with a section per seller ("Sold by …"), each with its own progress.

### 3.3 Fulfilment (decision: **Waimart delivers**)
Sellers prepare and hand over their items; **Waimart staff** do everything after handover, for both delivery and pickup.

- **Seller order statuses:**
  - **seller steps:** `pending_acceptance` → `accepted`, or `rejected` (releases that seller's stock and creates a refund obligation if already paid) → `processing` → `packed` (ready for handover);
  - **Waimart steps:** `handed_over` (Waimart staff confirm receipt of the parcel). Then:
    - **delivery:** → `dispatched` → `out_for_delivery` → `delivered`;
    - **pickup:** → `ready_for_collection` → `collected`, confirmed with the customer's code at the desk as now;
  - `cancelled`.
- **Who can do what:** sellers can never mark handover, dispatch, delivery, readiness or collection; those need staff permissions.
- **When stock leaves the seller's inventory:**
  - **third-party sellers:** consumed at `handed_over`, since that's when the goods leave the seller;
  - **Official Store:** consumed at `dispatched` (delivery) or `collected` (pickup), as now, so existing behaviour and tests are unchanged.
- **Parent roll-up:** the parent becomes `delivered` / `collected` only when **all** non-cancelled seller orders have. Otherwise it shows `processing`, and the page shows each group's progress.
- **Partial collection and delivery:** a pickup customer may collect groups as they become ready, and Waimart may deliver groups separately or together.

### 3.4 Money model (ledger)
**Buckets and entries.** `seller_ledger_entries` holds immutable, signed amounts in buckets `pending`, `available`, `reserved` and `paid`. Balances are sums, never stored numbers.

| Event | Entries |
|---|---|
| Payment verified (or COD cash recorded) | `sale_accrued` +gross (pending), `commission` −commission (pending) |
| Earnings become eligible (policy met) | `release` −net (pending) / +net (available) |
| Seller cancels/rejects, refund, return | `refund_adjustment` / `commission_reversal` against the right bucket; corrections reference the original entry |
| Payout requested | −amount available / +amount reserved, under a seller-row lock; the request is refused if it exceeds `available` |
| Payout rejected or cancelled | Reverse of the request |
| Payout paid (admin records method, reference, amount) | −reserved / +paid |

**Safeguards.** Unique keys on (seller order, entry type) and (payout, entry type) prevent duplicate commission or payout postings. Concurrent payout requests are tested with real parallel processes.

**Eligibility.** The default (conservative, as the spec asks) is:
- payment verified, **and** the seller order delivered or collected;
- **and** a configurable holding period has passed (default = the return window, 7 days);
- **and** no open return or dispute.

Each condition is a setting. A worker run (next to the expiry worker) moves eligible earnings from pending to available.

**Reports.** Verified customer payments, seller earnings accrued, eligible, requested, paid, refund obligations, marketplace commission, and outstanding seller liability. Each is a sum of ledger rows and links to them. Gross sales are never presented as Waimart's revenue.

---

## 4. Implementation phases (the spec's §16)

| # | Phase | Main deliverables | Tests added (beyond the full regression run) |
|---|---|---|---|
| 1 | Inspection & plan | This document | Baseline run (done) |
| 2 | Backups, migrations | Source snapshot + DB backup. Migrations 010+: sellers, membership, applications, ownership back-fill, seller orders + back-fill, commission/ledger/payout/dispute tables. Rollback notes. | Migration on a restored copy; no row loss; untouched tables' checksums equal; constraint tests |
| 3 | Seller accounts | "Sell on Waimart", application form with private documents, admin review (approve / reject with reason and resubmission / suspend / reactivate), seller sign-in, `seller` middleware, seller profile, notification preferences | Application lifecycle, document upload security, privilege-escalation attempts, route sweep |
| 4 | Product ownership | Seller product and stock management (reusing `CatalogueAdminService` / `InventoryService`, scoped), moderation setting and queue, ownership immutability | IDOR on products, variants, stock and images; moderation visibility; suspended-seller products hidden |
| 5 | Stores & attribution | `/store/{slug}` pages, unique slug handling, "Sold by" across the storefront, store rating from verified reviews | Store page privacy (no documents, payout data or addresses), slug collisions |
| 6 | Multi-seller checkout | Seller orders in the placement transaction, fee policy and allocation, customer order page with groups, seller notifications | Multi-seller placement, rollback mid-way, idempotent retry, concurrent last-unit across sellers |
| 7 | Seller fulfilment | Seller order queue and workflow, scoped consumption, pickup per group, parent roll-up, admin oversight | Partial dispatch, roll-up rules, seller can't touch another group, exactly-once consumption per group |
| 8 | Commission, ledger, payouts | Commission rules UI, ledger postings, eligibility worker, seller finance page, payout requests, admin payout desk, CSV exports | Commission snapshots immutable, no duplicate postings, concurrent payout requests, payout ≤ available, reconciliation |
| 9 | Returns, disputes, reports | Per-seller-order returns and refunds with ledger adjustments, disputes, seller/commission/liability/reconciliation reports, audit coverage | Partial return/cancel isolation, refund-after-payout handling, report totals = ledger sums |
| 10 | Full verification | Regression, security (IDOR matrix), concurrency, financial reconciliation, accessibility and responsive sweeps on all new pages | — |
| 11 | Docs & final report | Updated INSTALL / OPERATIONS / BACKUP_RESTORE / ARCHITECTURE, seller guide, final implementation report | — |

As before, I'll stop after each phase for your approval.

---

## 5. Existing features that must keep working (regression checklist)

Each line is already covered by automated tests. These suites must stay green after every phase.

| Feature | Covered by |
|---|---|
| Home, branding, search, categories, product pages and variants | `StorefrontPagesTest`, `ProductListingTest`, `CatalogueRepositoryTest`, browser `a11y` / `responsive` |
| Registration, login, password reset, account | `CustomerFlowTest`, `AccountServiceTest`, `SecurityHardeningTest` |
| Guest and registered checkout, cart rules | `OrderFlowTest`, `CartServiceTest`, `CheckoutPrepareTest`, browser `checkout-flow` |
| Delivery zones and pickup points | `CheckoutPrepareTest`, `FulfilmentAdminTest`, `PickupHttpTest` |
| Stock reservation, expiry, concurrency (C9 1–12) | `OrderPlacementTest`, `ReservationLifecycleTest`, `ConcurrencyTest`, `HeavyConcurrencyTest` |
| Manual M-Pesa verification, late payment, COD | `PaymentFlowTest`, `PaymentHttpTest` |
| Order tracking and privacy | `OrderFlowTest`, `PickupHttpTest` |
| Cancellations, returns, refunds, reviews | `ReturnsRefundsReviewsTest`, `CustomerServiceHttpTest`, `OrderLifecycleHttpTest` |
| Admin catalogue, orders, permissions, audit, reports | `AdminCatalogueTest`, `AdminPhase10*Test`, `RouteSecurityTest` |
| Email notifications / outbox | `CustomerServiceHttpTest`, `AccountServiceTest` |
| Backup / restore | `BackupRestoreTest` |
| Security headers, private files, uploads | `RoutingAndSecurityTest`, `SecurityHardeningTest` |

---

## 6. Risks and how they're handled

| Risk | Mitigation |
|---|---|
| Breaking the working single-store flow | The parent order, payment and reservation logic stay. New behaviour is added beside them. The full suite runs after every phase. |
| Seller-to-seller data leakage (IDOR) | Seller context comes only from server-side membership. Every seller query is scoped by `seller_id`. An automated IDOR matrix and route sweep run on every test run. |
| Money errors (double commission, double payout, overdraw) | Immutable ledger, balances computed as sums, unique keys per posting, row locks on payout requests, parallel-process tests |
| Historical reports changing after migration | Back-filled seller orders carry 0 commission and no ledger entries. Report totals are compared before and after migration. |
| Privacy of seller documents and payout details | Documents stored outside the web root and served only to authorised admins and the owning seller. Payout details encrypted. |
| Cash on delivery with third-party sellers | Decision: COD is offered only for pickup orders and for orders made up entirely of Official Store items, so Waimart holds all money before paying sellers. Because Waimart's own staff deliver, COD on third-party home deliveries could safely be enabled later with one setting. |

---

## 7. Business decisions (answered 18 October 2026)

All four are stored as settings, so each can be changed later in Admin → Settings without code changes.

| # | Question | Decision |
|---|---|---|
| 1 | Who delivers home-delivery orders? | **Waimart delivers.** Sellers prepare and hand over their items; Waimart staff dispatch and deliver (§3.3). |
| 2 | Delivery fee for multi-seller orders | **One zone fee per order**, kept by the platform. Never part of seller earnings. |
| 3 | Cash on delivery | **Pickup orders and Official Store–only orders.** Third-party home delivery is M-Pesa only. |
| 4 | Commission | **10%, provisional** until you confirm the business rate. Seller overrides supported; category rates available but off. |

Further defaults, stated so you can object:
- **New products:** need admin approval (moderation on).
- **Platform store name:** "Waimart Official Store".
- **Coupons:** platform-funded, so sellers aren't charged for Waimart promotions; commission is calculated on the price before the coupon. Admin flash sales include a third-party product only if its seller has opted in.
- **Minimum payout:** KES 500.
- **Holding period:** 7 days after delivery or collection.
- **Required seller documents:** a configurable list — ID for individuals; business registration certificate for registered businesses, initially optional.

---

## Phase 2 results (18 October 2026)

**Backups taken before any change:**
- Source code snapshot: `C:\xampp\backups\waimart\source-20261018-033226-pre-marketplace\` (342 files; excludes `vendor/` and `node_modules/`, which Composer/npm reinstall, plus logs).
- Database, uploads and `config.local.php`: `C:\xampp\backups\waimart\waimart-20261018-033227-pre-marketplace.sql` (SHA-256 `977B014B…1D09E86`) plus its `-files` folder. These are copied outside `storage/backups`, so the 14-backup rotation can never delete them.
- A further dump was taken just before migrating `waimart`: `storage/backups/waimart-20261018-034048.sql`.

**Migrations applied** (to `waimart_test` first, then `waimart`):

| Migration | Contents |
|---|---|
| `010_marketplace_sellers` | `sellers` (Official Store = id 1, at most one platform store), `seller_users`, `seller_applications` + history (one open application per user), `seller_documents`, `seller_payout_methods` (encrypted, one active per seller); 7 admin permissions granted to manager/finance/support; 10 marketplace settings recording the §7 decisions |
| `011_product_ownership` | `products.seller_id` (NOT NULL, FK; all 56 existing products → Official Store) and moderation columns (existing products `published`) |
| `012_seller_orders` | `seller_orders` + history (one per seller per order), `order_items.seller_id` (NOT NULL; back-filled) + `seller_order_id` + commission snapshot columns, `seller_order_id` on returns and refunds |
| `013_marketplace_finance` | `commission_rules` (default 10% provisional; Official Store 0%; one active rule per target), `seller_ledger_entries` (append-only, exactly-once `posting_key`, non-zero amounts), `seller_payouts` (one open per seller; "paid" needs exact amount + unique reference) + history, `seller_disputes` (one open per seller order) + messages |

**Code changes (minimal, so existing features keep working):**
- New helper `app/Services/Sellers.php` looks up the Official Store.
- The admin product creation (`CatalogueAdminService`), the demo seed and order placement (`OrderService`, which snapshots the item's seller) set the new mandatory columns.
- The test fixtures (`DbTestCase`) keep the migration-seeded Official Store and commission rules between tests.

**Verification:**
- **Drill on a copy:** the pre-upgrade backup was restored into `waimart_test` and migrated. All 47 original tables kept their row counts. The only additions were the new permissions, settings and migration records.
- **Data comparison:** 38 untouched tables were identical by `CHECKSUM TABLE`. In the altered tables, the original columns hashed identically row by row.
- **Defect found and fixed:** the first drill showed the ownership back-fill restamped `products.updated_at` with the migration time. The migration now preserves it, and the rerun matched exactly, including timestamps.
- **Main database:** the same checks passed on `waimart` against the backup taken just before migrating. All 56 products and all order items are attributed to the Official Store.
- **Rollback:** `database/rollback/rollback_marketplace.sql` (see BACKUP_RESTORE.md). Tested on the copy, it restored the identical schema (`SHOW CREATE TABLE` for all 47 tables) and identical data. Its guard refused to run once a third-party seller had a product, before dropping anything.
- **Tests:** new `MarketplaceSchemaTest` (10 tests: platform store uniqueness, slug rules, mandatory seller on products and order items, one seller per user, one open application, commission scope/rate rules, one seller order per seller per order, exactly-once ledger postings, payout constraints, one active payout method, one open dispute).
- **Full suite:** **273 tests, 1,726 assertions, all passing**, before and after migrating `waimart`.
- **Live site after migration:** the browser checkout E2E placed WM-C8N854UW, attributed to the Official Store. The accessibility check passed.

**Not yet visible to users:** nothing in the storefront or admin has changed. Seller-facing features start in Phase 3.

---

## Phase 3 results (18 October 2026): seller accounts, applications, approval, Seller Centre

**For applicants (storefront):**
- **Entry points:** a "Sell on Waimart" link in the top bar, mobile menu and footer leads to `/sell`. That page explains the process and lists the documents the admin requires; `/sell/terms` summarises the seller terms.
- **Step 1, details (`/sell/apply`, sign-in required):**
  - store name — may not contain "Waimart";
  - store address, a slug that is normalised, unique and not reserved (`admin`, `waimart*`, …);
  - business type, with registered name for businesses and an optional registration number;
  - description, categories, county/town, private business address, and contact person.
  - The details are saved as a **draft**.
- **Step 2 (`/sell/application`):**
  - **documents:** PDF/JPEG/PNG up to 5 MB, checked by content (MIME sniff, PDF header, image decode, script markers), stored under random names in `storage/private/seller-docs` outside the web root, and downloaded only as attachments;
  - **payout details:** M-Pesa or bank, **encrypted** (AES-256-GCM) and shown only masked;
  - **terms:** acceptance recorded with version and time;
  - then submission.
- **Statuses** shown to the applicant: draft, submitted, under review, approved or rejected. A rejection shows the reason. If resubmission is allowed, the applicant reopens the same application, which keeps its history. If not, no new application is possible.
- **Emails:** sent on submission, approval and rejection. A copy goes to the store email, if set, for new applications.

**For staff (admin, new "Marketplace" menu):**
- **Seller applications:** a status queue. Each application shows its details and documents; every download is recorded in the audit log. Actions: mark under review, approve (staff may correct the store name or address), or reject (reason plus "allow resubmission").
  - Staff **cannot review their own application**.
  - Approval creates the store, makes the applicant its **owner** (never staff), carries over the encrypted payout details, and links the documents.
- **Sellers:** list and detail pages. Actions: suspend (reason sent to the seller), reactivate, verified badge. The Official Store is protected from these actions.
- **Settings:** a "Seller onboarding documents" section sets, for each document type, whether it is required for everyone, for businesses only, for individuals only, optional, or not requested. Changes are audited.
- **Permissions:**
  - `sellers.view` (manager, finance, support) lets staff look;
  - `sellers.manage` (manager) lets them decide.
  - The route sweep test enforces this on every route.

**For sellers (Seller Centre `/seller`, same look as the admin):**
- **Pages:**
  - dashboard with store status and a real setup checklist;
  - store profile: description, logo, location, public contacts plus a "show on store page" choice. Name and address can only be changed by staff;
  - notification preferences;
  - password, through the existing account page.
- **Access control:**
  - The `seller` middleware gives access to signed-in **members of a seller**. The seller is resolved only from server-side membership (`SellerContext`), never from the request.
  - A **suspended** store is read-only: pages load, but any change returns 403.
  - The `seller_role` check limits profile edits to owners and managers.
  - Sellers are not staff, so `/admin` returns 403.
  - Sign-in, sign-out and password reset reuse the existing secure, rate-limited flows.

**Changed existing code:**
- **Core:** `Kernel` (two middlewares), `Auth::reset`, `Response::back` (payout fields are never kept in the session).
- **Admin and services:** `SettingsService`, `SettingsController` and its view; `ImageService` (`stores` folder for logos).
- **Storefront views:** the header, footer and admin layout. The admin sidebar now highlights only the most specific link.
- **Backup and restore:** both now include `storage/private` (seller documents).
- **Browser scripts:** a configurable login path.

**Tests:**
- `SellerOnboardingTest` (13): lifecycle, validation, store-address rules, resubmission rules, staff self-review, approval re-checks, upload attacks (PHP disguised as PDF, PHP inside a PDF, wrong type, more than 5 MB, path traversal), applicant isolation, suspension, `SellerContext`, and document policy.
- `SellerOnboardingHttpTest` (8): end to end over HTTP with tampered `user_id`, `status`, `seller_id` and `is_staff` fields ignored; cross-applicant document access returns 404; staff permission levels; suspended store read-only; seller roles; no way to edit another store through form fields; admin document settings.
- `RouteSecurityTest`: 3 new sweeps.
- Private paths: Apache returns 403.
- **Full suite: 297 tests, 1,959 assertions, all passing.**
- **Browser:**
  - WCAG 2.1 AA, 0 serious/critical issues on 12 new pages (public, applicant, seller and admin);
  - responsive checks: no overflow or console errors at phone, tablet and desktop widths;
  - screenshots reviewed.
  - The temporary browser-test accounts and data were removed afterwards.

**Deferred to the phase that needs them:**
- Hiding a suspended seller's products from the storefront arrives with seller products in Phase 4; today only the Official Store has products.
- The public `/store/{slug}` pages arrive in Phase 5.
- Inviting extra team members (manager/staff) to a store isn't built yet; the schema and roles support it.

---

## Phase 4 results (18 October 2026): seller products, moderation, visibility

**One visibility rule for the whole storefront:**
- **The rule:** a product is listed and purchasable only when it is active, **published** (approved), **and its seller is active**.
- **Where it lives:** `CatalogueRepository::SELLER_JOIN` / `PUBLIC_PRODUCT` / `PUBLIC_EXPR` define it once. It is used by home-page sections, category/brand/search listings and their counts, the product page, related products, wishlist cards, the cart, add-to-cart and checkout (which re-reads the cart inside the order transaction).
- **Effects:**
  - Drafts, pending, rejected and archived products never appear.
  - Suspending a seller hides all their products at once, and their cart lines become "no longer sold", which blocks checkout.
  - Reactivation restores them.
- **Existing records:** the 56 existing Official Store products were already `published` (migration 011) and are unaffected.

**Seller Centre → Products (`/seller/products`):**
- **Pages:**
  - list with status tabs (draft, waiting for approval, published, rejected, archived), low-stock warnings and the rejection reason;
  - create (saved as a draft);
  - edit: details, options/prices, stock, photos;
  - submit, archive and restore.
- **Shared code:** the existing admin catalogue service and field templates are reused, so seller products follow exactly the same validation as admin products.
- **Ownership:** every action first loads the product **within the current seller**. Another seller's product, or a mix of one's own product id with someone else's variant or image id, gives 404.
- **Platform-controlled fields:** a seller can never set the owner, the "featured" flag, the URL slug or the moderation state; these are taken from the existing record whatever the request contains.
- **Categories and brands:** sellers may only choose active ones.
- **Seller SKUs:** stored as `S{sellerId}-{code}` and shown to the seller without the prefix. Two sellers can use the same code, and a seller cannot discover another seller's codes. Staff cannot create SKUs in that namespace.
- **Stock:** add, adjust or count, using the existing inventory service. Units reserved for orders can't be removed, and every change goes to the stock ledger with **actor type `seller`** (migration 014, additive).
- **Roles:** owners and managers edit products. Every store member, including staff, can update stock. Suspended stores are read-only.

**Moderation:**
- **Setting:** "Require approval before seller products go live" (Admin → Settings → Marketplace), on by default. When off, a submitted product is published immediately.
- **Submission requirements:** at least one photo, a description and an active default option.
- **Review after edits:** with moderation on, changing a published product's name, category, brand, descriptions, specifications or photos sends it back for approval. Price, stock and option availability changes apply immediately.
- **Admin → Marketplace → Product approvals (`products.moderate`, managers):**
  - queue of waiting products with approve; reject with a reason the seller sees;
  - "take down" for a published product;
  - the seller is emailed in each case, and every decision is audited.
- **Admin product list and editor:** now show the seller and approval status, with filters by seller and approval.

**Tests:**
- `SellerProductsTest` (12): lifecycle and visibility across the product page, listings and cards; moderation on and off; which edits trigger re-review; rejection, takedown and resubmission; suspension hiding products and blocking the cart; 10 cross-seller attacks, all returning 404; tampered platform fields; SKU namespaces; stock rules and ledger attribution; categories limited to live ones; archive and restore; Official Store unaffected.
- `SellerProductsHttpTest` (5): end to end with approval; every seller product route rejects another seller's ids, with A's data verified unchanged byte for byte; staff role can update stock but not edit; suspended store hidden from product page and search; moderation permission.
- `RouteSecurityTest`: 2 new sweeps (seller product changes need owner/manager; moderation needs `products.moderate`).
- **Full suite: 318 tests, 2,163 assertions, all passing.**
- **Browser:** 0 serious/critical accessibility issues on 9 seller, admin and public pages; responsive checks passed; screenshots reviewed. Temporary fixtures removed.

**Environment note:** one full run in this phase failed because Windows ran out of virtual memory ("paging file is too small"): a race-test worker couldn't start PHP cleanly. After memory recovered, the full suite passed unchanged, so it was not a code fault. Low free memory on this PC can affect the 16-process race tests.

**Defect found and fixed (from Phase 2):** the rollback instructions in `BACKUP_RESTORE.md` had been corrupted when they were added. A scripting escape turned the backslashes in Windows paths into control characters, so `C:\xampp` became a line break and `\rollback` a carriage return. The section has been rewritten, and every tracked text file was scanned for control characters; none remain.
- **Rollback script:** renamed `rollback_marketplace.sql` and now also reverses migration 014.
- **Re-tested on a copy:** the pre-upgrade backup was migrated through 010–014 and rolled back. The schema and data of all 47 tables were identical.

---

## Phase 5 results (18 October 2026): public store pages and seller attribution

**Store pages (`/store/{slug}`):**
- **Profile header:**
  - logo, or the store's initial if there is no logo;
  - name and badge — "Official Waimart store" for the platform, "Verified seller" when staff gave the badge;
  - rating from **approved reviews of verified purchases** of the store's products;
  - town and county, "Selling on Waimart since …", and the number of products shown;
  - description (escaped);
  - public contact details **only if the seller ticked "show on my store page"**;
  - a note that payment and delivery go through Waimart;
  - a search box limited to the store.
- **Products:** only the store's **live** products, using the same visibility rule as the rest of the shop. The usual price, availability, discount, brand and sort filters apply, plus **store categories with counts**. Category, search and sort stay inside the store. An unknown category id is ignored.
- **Suspended or unknown stores:** 404. Suspended stores' products are already hidden everywhere (Phase 4).
- **Never shown:** legal name, business address, registration number, application text, owner/team names and emails, payout details, documents and suspension reasons. `StoreRepository::findPublic` selects only public columns.
- **Official Store:** has a page too (`/store/waimart-official`).

**"Sold by" attribution:**
- **Product page:** "Sold by {store}" linking to the store, with the Official or Verified badge, and a seller box with the store rating and "Visit store".
- **Product cards** (listings, search, home rails): "Sold by {store}" for marketplace sellers. The Official Store is not repeated on every card.
- **Cart:** each line shows "Sold by {store}" with a link.
- **Seller-facing:** the Seller Centre header has "View my store", and the store address on the profile page is a link. Admin seller pages link to the public store and to the seller's products.

**Found and fixed:** the product page showed customers the internal SKU (e.g. `S4-BT-1`, which includes the seller id). Customers now see the seller's own code (`BT-1`), and a test checks this.

**Tests:**
- `StorePagesHttpTest` (7): store shows only its own live products (draft, pending, unlisted and other sellers' products excluded); 404 for unknown and suspended stores; 10 kinds of private data checked absent, and contacts shown only on opt-in; escaping of a script in the description; rating counts approved reviews only (4.5 from 2, ignoring pending and rejected); category filter and search stay within the store; "Sold by" on product page, cards and cart, with the SKU prefix hidden.
- **Full suite: 325 tests, 2,216 assertions, all passing.**
- **Browser:** 0 serious/critical accessibility issues on store pages (seller, Official, in-store search), the seller product page and the seller profile. Responsive checks passed for store pages, product page and cart at phone, tablet and desktop widths. Screenshots reviewed; fixtures removed.

---

## Phase 6 results (18 October 2026): multi-seller checkout, sub-orders, commission

**One checkout, one order, one sub-order per seller.** The existing single placement transaction now also does steps 1–3 below, in order:
1. **Opens** one seller sub-order per seller in the cart (`WM-XXXXXXXX-1`, `-2`, …). Each records its merchandise subtotal and its **share of any coupon discount**, allocated pro rata with the rounding remainder on the last group, so shares always add up exactly.
2. **Inserts each item** linked to its seller and sub-order, with a **commission snapshot**: rate, amount, net and the rule used. Precedence is **seller rule > the product's category rule > its parent category's rule > default (10% provisional)**, and the Official Store pays 0%.
3. **Totals** each sub-order's commission and seller net.

Then, as before:
- stock is locked and reserved;
- any failure rolls everything back, including sub-orders;
- the idempotency key returns the same order on a retry, with no second order or sub-orders.

Changing commission rules later never changes placed orders (tested).

**Money definitions (per sub-order):**
- **Merchandise:** at the prices charged, including any flash-sale price.
- **Coupons:** **platform-funded**: seller net is not reduced and commission is calculated on the full price.
- **Delivery fees:** belong to Waimart (delivery-fee share 0).
- **Nothing here is money received:** earnings ledger postings happen in Phase 8, after payment verification.

**Client rules applied at checkout** (`MarketplacePolicy`, used by the checkout page, the live quote and order placement):
- **Cash on delivery:** offered for pickup orders, or for home delivery of Official Store items only. Mixed or marketplace home deliveries must use M-Pesa. The checkout shows the reason, and the server refuses a forged COD submission. Setting `marketplace_cod_policy = all` lifts the rule.
- **Delivery fee:** one zone fee per order (default), with a note when the cart has several sellers. `per_seller` charges once per seller group and is implemented and tested, but not enabled.

**Existing orders (migration 015):** each existing order was back-filled with an Official Store sub-order, marked *pre-marketplace*:
- no commission and no ledger effect;
- status and timestamps copied from the order;
- every item linked;
- `order_items.seller_order_id` is now mandatory.

**Checks:**
- **Drill on a copy:** every order has sub-orders, every item points at the right seller, and subtotals and discounts reconcile. Orders, fulfilment, reservations, stock movements, inventory and payments were identical before and after.
- **Main database:** then migrated (backup taken first).
- **Rollback script:** renamed `rollback_marketplace.sql` and re-drilled through 010–015, with an identical result.

**Interim bridge (until Phase 7):** the parent order still drives fulfilment. Every parent status change is mirrored onto the sub-orders: confirmed → accepted, cancelled or expired → cancelled with the reason, fulfilment stages copied. When an order is confirmed (payment verified or COD confirmed), each **marketplace seller is emailed** that their sub-order is ready to prepare; the Official Store is not. Phase 7 hands control of each sub-order to its seller and to Waimart staff.

**Customer and admin views:**
- **Checkout:** items grouped "Sold by …" when the cart has marketplace sellers.
- **Order pages:** confirmation, tracking and account pages show each seller group with its sub-order number and its own progress.
- **Admin order page:** new "Seller sub-orders" panel with merchandise, discount share, commission (with rate), seller net and a pre-marketplace marker. Customers never see commission.

**Flash-sale consent:** a marketplace seller's product can be added to a Waimart flash sale only if the seller ticked "Allow Waimart to include my products in flash sales" (new Store profile option), because a flash price lowers their earnings.

**Found and fixed:**
- **Pre-existing bug:** the checkout's empty "Discount" row was visible because Bootstrap's `d-flex` overrode the `hidden` attribute. A global `[hidden]` rule fixes it.
- **Wording:** the "parts may arrive at different times" note now appears only for orders with more than one seller.
- **Duplicate text:** the COD explanation no longer appears twice on checkout.

**Tests:**
- `MultiSellerCheckoutTest` (9): three-seller split with exact commission (0%, 10%, 7.5% with half-up rounding); rule precedence and immutable snapshots; exact discount allocation; rollback leaves no sub-orders; idempotent retry; COD rule in four cases; both fee policies; sub-orders follow confirm, cancel and expiry, with the seller emailed (not the Official Store); flash-sale consent.
- `MultiSellerOrderHttpTest` (2): checkout grouping, fee note and COD reason; COD forgery refused server-side; grouped confirmation page; admin sub-order panel; commission never shown to customers.
- `HeavyConcurrencyTest`: 8 real processes racing for the last unit in mixed-seller carts — exactly 1 order with exactly its 2 sub-orders, no orphans, consistent stock (3 of 3 runs).
- **Full suite: 337 tests, 2,299 assertions, all passing.**
- **Browser:**
  - mixed-seller cart and checkout, a seller's order tracking page and the admin order page: accessibility and responsive checks passed;
  - storefront accessibility and responsive regression, plus the checkout E2E flow, passed;
  - screenshots reviewed; fixtures and the fixture order removed.
