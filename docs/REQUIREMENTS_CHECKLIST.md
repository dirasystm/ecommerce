# Waimart — Requirements Checklist

Status key: ☐ not started · ◐ in progress · ☑ done and tested · ✖ blocked or failing (see notes)

The spec is the "Final Requirements Amendment". There is no earlier base spec, so gaps are filled with Jumia-style defaults, which are listed under **Assumptions**.

## Phase tracker

| # | Phase | Status | Evidence |
|---|---|---|---|
| 1 | Environment inspection, checklist, architecture | ☑ | `docs/ARCHITECTURE.md`, this file |
| 2 | Schema, config, routing, security foundation | ☑ | 4 migrations (46 tables); 65 tests / 169 assertions green — see Phase 2 notes |
| 3 | Brand system, responsive layout, storefront | ☑ | 90 PHPUnit tests green; 18/18 responsive checks (phone/tablet/desktop) — see Phase 3 notes |
| 4 | Catalogue, categories, brands, variants, inventory | ☑ | 120 PHPUnit tests / 415 assertions green; 33/33 responsive checks incl. admin — see Phase 4 notes |
| 5 | Auth, guest & registered checkout, carts | ☑ | 153 PHPUnit tests / 536 assertions green; 18/18 responsive checks (cart, checkout, auth) — see Phase 5 notes |
| 6 | Transactional orders & stock reservations | ☑ | 172 PHPUnit tests / 667 assertions green incl. multi-process concurrency; browser E2E checkout — see Phase 6 notes |
| 7 | Expiry, cancellation, release, consumption | ☑ | 191 PHPUnit tests / 782 assertions green incl. concurrent expiry workers — see Phase 7 notes |
| 8 | Delivery zones, pickup points, collection workflow | ☑ | 206 PHPUnit tests / 874 assertions green (~40 s) — see Phase 8 notes |
| 9 | M-Pesa (phone/Till/Paybill) & COD workflows | ☑ | 219 PHPUnit tests / 952 assertions green — see Phase 9 notes |
| 10 | Admin dashboard, permissions, reports, audit | ☑ | 231 PHPUnit tests / 1,036 assertions green — see Phase 10 notes |
| 11 | Returns, refunds, notifications, customer service | ☑ | 240 PHPUnit tests / 1,095 assertions green — see Phase 11 notes |
| 12 | Security, responsive, concurrency, performance, regression tests | ☑ | 256 PHPUnit tests / 1,666 assertions green; 0 serious/critical axe issues on 41 pages; 129/129 responsive checks; browser checkout E2E — see Phase 12 notes |
| 13 | Install docs, backup/restore, final acceptance report | ☑ | `docs/INSTALL.md`, `docs/BACKUP_RESTORE.md`, `docs/ACCEPTANCE_REPORT.md`; restore drill verified by checksum; 263 PHPUnit tests / 1,684 assertions green — see Phase 13 notes |

## A. Project decisions
| ID | Requirement | Status |
|---|---|---|
| A1 | Delivery and pickup fulfilment | ☑ both end to end (checkout → workflow → dispatch/delivery or ready/collection) |
| A2 | Atomic stock reservation, configurable expiry, safe release | ☑ Phases 6–7 |
| A3 | Guest and registered checkout | ☑ `OrderFlowTest`, `CustomerFlowTest` |
| A4 | COD, M-Pesa phone, Till, Paybill (manual) | ☑ submit → verify/reject; COD confirm + cash recording — `PaymentFlowTest` |
| A5 | No card gateway / Daraja | ☑ (by design) |
| A6 | Auditable payments, verifications, inventory movements | ☑ ledger rows for every stock change; one `payment_verifications` row per submission (DB-unique) with staff, amount and note; audit entries for every decision, COD record and payment-number change |
| A7 | Continuous testing; failures fixed and rerun | ☑ every phase ended on a green full run; failures found along the way are listed in each phase’s notes with the fix and rerun |

## B. Delivery & pickup
| ID | Requirement | Status |
|---|---|---|
| B1 | Offer only configured/available fulfilment methods | ☑ delivery/pickup shown only when an active zone/point exists; M-Pesa methods only when numbers configured — `CheckoutPrepareTest` |
| B2.1 | Collect and validate name, phone, email (policy), county, town, location, instructions | ☑ `CheckoutPrepareTest`, `guest_email_required` setting |
| B2.2 | Admin: zones, locations, fees, ETA ranges, free threshold, COD eligibility | ☑ `/admin/delivery-zones` — `FulfilmentAdminTest` |
| B2.3 | Server-side delivery fee; browser value ignored | ☑ `testDeliveryFeeComputedServerSideIgnoringPostedValues` |
| B2.4 | Clear message when location unsupported | ☑ `testUnsupportedLocationExplainsAlternative` |
| B2.5 | No unsubstantiated delivery dates (ranges only, from zone config) | ☑ ranges from zone config, labelled as estimates |
| B3.1 | Pickup point fields (name, code, county, town, address, map, contact, hours, status, instructions, capacity, timestamps) | ☑ all fields incl. fee, COD, max order value, daily capacity |
| B3.2 | Admin add/edit/activate/deactivate/search | ☑ `/admin/pickup-points` (audited; warns about open orders) |
| B3.3 | Customer selects an active point and sees location and instructions | ☑ checkout lists address, hours, instructions, map link, fee |
| B3.4 | Inactive points never selectable (checked on server) | ☑ `testPickupRules` |
| B3.5 | Pickup snapshot stored on order | ☑ `testDeactivatedPointsLeaveCheckoutButOrdersKeepTheirSnapshot` |
| B4.1 | Pickup workflow states | ☑ placed → payment/confirmation → confirmed → processing → ready_for_collection → collected; cancel |
| B4.2 | Ready ≠ collected; staff must confirm collection | ☑ workflow refuses `collected` without a verified code; generic endpoint cannot do it |
| B4.3 | Unguessable collection reference/code, hashed, rate-limited, invalidated | ☑ 8-char random code, HMAC + AES-GCM at rest, redacted in outbox, 5 tries/15 min per order, invalidated on collect/cancel/reissue |
| B4.4 | Record staff, time, outcome of collection | ☑ `collected_at/by`, `collection_outcome`, ID-check confirmation, audit |
| B4.5 | Customer tracking shows pickup status and instructions | ☑ code shown only to owner / placing session / private-link holder |
| B5.1 | Delivery workflow states | ☑ confirmed → processing → packed → dispatched → out_for_delivery → delivered; cancel/return paths |
| B5.2 | Whitelisted transitions only | ☑ `OrderWorkflow::TRANSITIONS` + guards; no set-status operation — `testWorkflowGuards`, `testStaffActionsArePermissionChecked` |
| B5.3 | Delivered timestamp and confirming actor | ☑ `delivered_at`, `delivered_by`, `delivery_confirmation` |
| B5.4 | Status history for every transition | ☑ `testHistoryRecordsEveryTransitionWithActor`; staff transitions audited |

## C. Stock reservation
| ID | Requirement | Status |
|---|---|---|
| C1 | On-hand / reserved / available at variant level; invariant enforced | ☑ DB CHECKs + `InventoryService`; `assertInventoryConsistent` (reserved = Σ active reservations) checked across lifecycle tests |
| C2 | 12-step transactional checkout; no partial reservations | ☑ `OrderService::place` — `OrderPlacementTest` |
| C2.1 | No overselling under concurrency | ☑ 8 parallel processes, 1 unit → exactly 1 order (`ConcurrencyTest`) |
| C2.2 | Idempotent checkout (double-click, refresh, retry) | ☑ unique idempotency key; 6 parallel identical submissions → 1 order; HTTP double-submit and browser double-click tested |
| C3 | Reservation ledger; states; at-most-once transitions; DB safeguards | ☑ active → released/expired/consumed via guarded updates + UNIQUE(reservation_id, movement_type) |
| C4 | Admin-configurable expiry (default 30 min); expiry restores stock and notifies | ☑ settings `reservation_minutes`/`reservation_minutes_cod`; expiry releases, records reason/time, marks order expired, notifies (admin settings screen: Phase 10) |
| C5 | M-Pesa submission ≠ payment; late verification → resolution workflow | ☑ no auto-confirm, no reservation extension; late verify → requires_resolution → re-reserve or cancel with refund |
| C6 | Cancel/reject releases active reservations exactly once; post-dispatch → returns | ☑ scenarios 5 & 8; coupon use returned; refund obligation if paid |
| C7 | Consumption at dispatch (delivery) / collection (pickup), exactly once | ☑ dispatch tested (scenario 7); collection uses the same primitive (UI in Phase 8) |
| C8 | CLI expiry worker, idempotent, concurrency-safe, documented scheduling | ☑ `bin/expire-reservations.php` + `.bat`; GET_LOCK; 4 concurrent workers tested; `docs/OPERATIONS.md`; fallback sweep |
| C9 | 12 inventory acceptance scenarios | ☑ all 12 (tracker below) |

### C9 scenario tracker
| # | Scenario | Test | Result |
|---|---|---|---|
| 1 | Two customers race for last unit | `ConcurrencyTest::testScenario1ManyCustomersOneLastUnit` (8 processes) | ☑ |
| 2 | Last unit reserved, second checkout | `OrderPlacementTest::testScenario2LastUnitAlreadyReserved` | ☑ |
| 3 | Expiry makes stock available | `ReservationLifecycleTest::testScenario3…` | ☑ |
| 4 | Expired reservation processed twice | `testScenario4ExpiryIsIdempotent`, `ConcurrencyTest::testConcurrentExpiryWorkers…` | ☑ |
| 5 | Order cancelled twice | `testScenario5CancelTwiceReleasesOnce` | ☑ |
| 6 | Payment verified after expiry | `PaymentFlowTest::testScenario6…` (re-reserve and cancel-with-refund paths) | ☑ |
| 7 | Reservation consumed twice | `testScenario7ConsumeOnceAtDispatch`, `testExpiredReservationsCanNeverBeConsumed` | ☑ |
| 8 | Cancel after dispatch | `testScenario8CancelAfterDispatchIsRefused` (+ admin HTTP test) | ☑ |
| 9 | Return passes / fails inspection | `ReturnsRefundsReviewsTest::testScenario9InspectionDecidesRestockAndRefundIsSeparate` | ☑ |
| 10 | DB error during reservation creation | `OrderPlacementTest::testScenario10FailureMidReservationRollsBackEverything` | ☑ |
| 11 | Network retry repeats checkout | `OrderPlacementTest::testScenario11…`, `ConcurrencyTest::testSameSubmissionInParallelCreatesOneOrder`, `OrderFlowTest::testDoubleSubmit…` | ☑ |
| 12 | Variant reserved and released correctly | `OrderPlacementTest::testScenario12…` + `ReservationLifecycleTest::testScenario12VariantReleasedCorrectly` | ☑ |

## D. Guest & registered customers
| ID | Requirement | Status |
|---|---|---|
| D1 | Guest browse/cart/checkout; secure token tracking; rate-limited; no enumeration | ☑ guest checkout; HMAC-hashed tracking token (stripped from URL after use); number+phone lookup rate-limited with one generic error; masked personal data — `OrderFlowTest` |
| D2 | Register, login/out, reset, profile, addresses, history, tracking, cancel/return requests, wishlist, verified reviews, notification prefs | ☑ all features; returns (`ReturnService`), verified-purchase moderated reviews (`ReviewService`) |
| D2.1 | Ownership checks on every customer endpoint | ☑ addresses, wishlist, saved addresses at checkout, orders (account/confirmation/track) — `testUsersCannotTouchEachOthersAddresses`, `testCustomersCannotSeeEachOthersOrders` |
| D3 | Verified guest→account order linking | ☑ one-time code to the order’s own phone/email; same-email registration does not auto-attach; 5 attempts committed; no enumeration — `OrderFlowTest::testGuestOrderLinksToAccountOnlyWithVerifiedCode` |
| D4 | Guest/user carts, safe merge, no reservation from cart, revalidation | ☑ `CartServiceTest` (7 tests), `testGuestCartSurvivesNavigationAndMergesOnLogin` |
| D5 | Guest and account acceptance tests | ☑ all 10 items (Phases 5–7 tests incl. `OrderFlowTest`, `CustomerFlowTest`, `ReservationLifecycleTest`) |

## E. Database & admin
| ID | Requirement | Status |
|---|---|---|
| E1 | Tables for pickup points, orders, snapshots, fulfilment, inventory, reservations, movements, payments, verifications, status history, guest tokens, returns/refunds, audit logs | ☑ schema (`SchemaSafeguardsTest::testAllRequiredTablesExist`) |
| E2 | FKs, unique constraints, indexes | ☑ schema — DB-level safeguards tested (see Phase 2 notes) |
| E3 | Transactions on all state-changing operations | ☑ checkout, reservations, release/expiry/consumption, payments, COD, collection, returns, refunds, resolutions, staff changes |
| E4 | Historical snapshots preserved | ☑ item name/variant/SKU/price/image and delivery address or pickup-point details copied onto the order at placement |
| E5 | DECIMAL money; bcmath calculations | ☑ `testAllMoneyColumnsAreDecimal`, `MoneyTest` |

## F. Customer messages
| Message | Status |
|---|---|
| Order placed · Stock unavailable · Reservation expired · Payment submitted (awaiting verification) · Payment verified · Payment rejected · Ready for collection · Dispatched · Delivered · Cancelled · Requires resolution | ☑ `OrderPresenter`, `OrderNotifier`, `PaymentService` messages; wording tested (never claims payment on submission, never “ready” before staff mark it) |

## H. Final acceptance criteria

Evidence for each criterion (tests and documents) is in [ACCEPTANCE_REPORT.md](ACCEPTANCE_REPORT.md).

| # | Criterion | Status |
|---|---|---|
| H1 | Home delivery and pickup are both functional | ☑ |
| H2 | Pickup points are configurable; inactive locations cannot be selected | ☑ |
| H3 | Orders preserve the selected fulfilment details | ☑ |
| H4 | Guest and registered checkout both work | ☑ |
| H5 | Stock is reserved transactionally at successful order placement | ☑ |
| H6 | Concurrent orders cannot oversell stock | ☑ |
| H7 | Reservation expiry releases inventory safely | ☑ |
| H8 | Cancellation and rejection release eligible reservations exactly once | ☑ |
| H9 | Fulfilment consumes stock exactly once | ☑ |
| H10 | Returns use a controlled inspection and restocking workflow | ☑ |
| H11 | Manual M-Pesa payments remain pending until verified | ☑ |
| H12 | Late payment verification after reservation expiry is handled safely | ☑ |
| H13 | COD collection is recorded separately from order delivery | ☑ |
| H14 | Customers can access only their own private order information | ☑ |
| H15 | Administrative actions are permission-checked and audited | ☑ |
| H16 | All relevant automated tests pass, or any outstanding failure is documented | ☑ |
| H17 | Installation, scheduled expiry processing, backup and recovery are documented | ☑ |

## Phase 2 notes (2026-10-17)

**Built:** front controller + regex router, kernel with CSRF, `auth`/`staff`/`can:`/`throttle` middleware, security headers (CSP, frame-deny, nosniff), hardened sessions (HttpOnly, SameSite=Lax, strict mode, idle timeout, ID regeneration), PDO wrapper (strict SQL mode, real prepared statements, READ COMMITTED, deadlock retry), bcmath `Money`, Kenyan phone validator, AES-256-GCM `Crypto`, DB rate limiter, redacting logger, audit logger, settings store, migration runner, installer, admin creator, backup script.

**Database safeguards enforced by MariaDB (tested):**
- `inventory` CHECKs: `on_hand >= 0`, `reserved >= 0`, `reserved <= on_hand`
- `stock_reservations.active_item_key` (generated) UNIQUE → one active reservation per order item
- `stock_movements` UNIQUE `(reservation_id, movement_type)` → each transition is recorded once
- `orders.idempotency_key` UNIQUE → duplicate checkout submissions rejected
- `payment_submissions.transaction_code` UNIQUE → an M-Pesa code can't be reused
- `payment_verifications.submission_id` UNIQUE → one decision per submission
- `cod_collections.order_id` UNIQUE, `reviews.order_item_id` UNIQUE, `delivery_zone_locations (county, town)` UNIQUE
- `order_items` CHECKs: returned ≤ fulfilled ≤ quantity

**Environment fix:** the MariaDB system table `mysql.db` was corrupt (Aria "crashed"). With the user's approval it was backed up to `storage/backups/mysql-db-table-*` and repaired with `REPAIR TABLE`. `CHECK TABLE` now reports OK and all 3 rows were preserved.

**Security:** the app connects as a least-privilege MySQL user `waimart`, limited to the `waimart` and `waimart_test` databases. Secrets live in git-ignored `config/config.local.php`.

**Setup commands** (from the project root, with `C:\xampp\php` on PATH):
```
php bin/install.php                 # databases, DB user, config.local.php (safe to re-run)
php bin/migrate.php                 # apply migrations
php bin/create-admin.php --email=you@example.com
php bin/backup.php                  # backup to storage/backups (keeps newest 14)
php vendor/bin/phpunit              # tests (rebuild waimart_test each run)
```

## Phase 3 notes (2026-10-17)

**Brand system** (`public/assets/css/app.css`): colour tokens sampled from the logo. A wordmark, icon and favicons were cut from the supplied logo with transparent backgrounds. **Accessibility:** white text on the logo's bright orange (#F7801E) is only 2.6:1, so filled buttons use `--wm-action` #C25400 (4.6:1, AA) and the bright orange is kept for accents with dark text. The site also has a skip link, visible focus rings, landmarks, alt text on every image, labelled controls, and support for reduced motion.

**Storefront:** Jumia-style home page with a promo strip, sticky header (search, account and help menus, cart), category sidebar with subcategory flyouts, hero carousel, info tiles, quick categories, flash sale with live countdown, top deals, category tiles, top sellers, per-category rails and a value band. On mobile there's an off-canvas category menu, a full-width search row and a bottom navigation bar. Content pages: Help Center (FAQ), How to pay (Till, Paybill and Send Money numbers come from settings), Delivery & pickup, Returns, Contact, About, Terms, Privacy, and All categories.

**Single pricing rule:** `PricingService::resolve()` (flash sale → compare-at → base). The same rule will be used for checkout recalculation in Phase 6, so the displayed price and the charged price can't drift apart.

**Honest display:** "items left" comes from *available* stock (on hand minus reserved). There are no fake star ratings; ratings come only from verified reviews (Phase 11). Discount percentages are rounded down so they're never overstated.

**Local assets only:** Bootstrap 5.3.3 and Bootstrap Icons 1.11.3 are in `public/assets/vendor`, so the strict CSP (`script-src 'self'`, no inline scripts) holds.

**Uploads hardening:** `public/uploads/.htaccess` stops any script running there and sandboxes directly opened SVGs.

**Demo data:** `php bin/seed-demo.php [--reset]` creates 56 products, 67 variants and 51 categories with fictional brands, a live 6-hour flash sale, 3 banners and opening stock recorded as `receipt` stock movements. It refuses to run in production, and `--reset` refuses if any orders exist.

**Responsive testing:** `tests/Browser/responsive.mjs` (puppeteer-core with the installed Edge, using device emulation) screenshots each page at 375, 768 and 1366 px. It fails on horizontal overflow or console errors. Run with `cd tests/Browser && npm install && node responsive.mjs`.

**Links that go live in later phases:** `/search`, `/category/*`, `/product/*`, `/flash-sales` (Phase 4); `/cart`, `/login`, `/register`, `/account`, `/track-order` (Phase 5). Until then they show the 404 page.

## Phase 4 notes (2026-10-17)

**Storefront:** category pages (including subcategories), brand pages, search, flash-sale page and product pages.
- **Listings:** brand facet with counts (it ignores its own filter so shoppers can widen the selection), price range, in-stock, discounted, five sorts plus "best match" for searches, pagination, and in-stock items first. Filters are plain GET URLs, so they're shareable and work without JavaScript. On mobile the filters open in an off-canvas panel.
- **Search:** MariaDB FULLTEXT prefix matching (`+word*`) combined with a LIKE fallback on product, brand and category names, so 2-letter words like "TV" still match. Boolean operators are stripped, and LIKE wildcards are escaped.
- **Product page:** gallery with thumbnails, variant chips that update price, discount, stock, SKU and the quantity limit, "Only N left", a countdown when on flash sale, description/specifications/reviews tabs, a delivery & returns box, related products and recently viewed. Descriptions are stored as plain text and escaped.

**Staff area:** `/admin/login` (rate-limited 5 per 15 min per account and 30 per IP, generic error, failures audited, timing-safe for unknown emails) and an admin layout whose menu shows only what the user's permissions allow.
- **Catalogue:** categories (two-level rules), brands, products (create with default variant and opening stock, edit, specifications as `Label: value` lines), variants (unique SKU, "was" price must exceed price, attributes, low-stock threshold, activate/deactivate, set default), and images (≤10 per product).
- **Image safety:** uploads are checked by content type, decoded with GD and re-encoded to WebP at a maximum edge of 1000px under a random file name. The original upload is never stored, so a disguised PHP file is rejected.
- **No hard deletes:** products are deactivated, never deleted, because orders reference them.
- **Inventory:** list with all / low / out / reserved filters; per-variant page with receive, adjust (with a reason, and "Other" requires a note) and stock count, plus the full stock ledger.
- **Inventory rule:** staff can't adjust physical stock below the units reserved for open orders; reserved stock only changes through the order workflow.

**New DB safeguard (migration 005):** at most one default variant per product (generated unique key), plus a `stock_movements.reason` column.

**Test infrastructure:** `tests/Feature/*` starts the PHP built-in server with `WAIMART_ENV=testing`, so real HTTP flows (login, CSRF, permissions, validation round-trips, uploads) run against the isolated `waimart_test` database. The browser harness can now log in (`WAIMART_LOGIN_EMAIL` / `WAIMART_LOGIN_PASSWORD`).

**Housekeeping:** the temporary `screenshot-bot@waimart.local` account used for the admin screenshots is suspended with no roles; it's kept so the audit trail stays intact.

**Deferred to Phase 10 (admin dashboard):** management of banners and flash sales (currently seed data only), and staff account management.

## Phase 5 notes (2026-10-17)

**Customer accounts:** register (rate-limited 10/hour per IP, Kenyan phone normalised, bcrypt), sign in and out (the guest cart merges in), forgot/reset password, profile (changing email needs the current password), password change, notification preferences, up to 10 saved addresses with a single default (DB-enforced, migration 006), and a wishlist.
- **Password reset:** the link token is stored only as an HMAC. It expires in 60 minutes, is single-use, and requesting a new link invalidates the old one. The response is identical for unknown emails, and requests are rate-limited per email and IP. The token is redacted from the notifications outbox, and the reset page sends `Referrer-Policy: no-referrer`.
- **Session invalidation:** `users.password_changed_at` signs out every *other* session after a password change or reset. The session that made the change stays signed in.
- **Safe redirects after login:** "intended" is recorded only from the server's own request path, and `/admin` is never used as a customer destination.

**Carts (D4):** guest carts are stored in the database behind a random HttpOnly cookie, kept only as a hash. Each user has one persistent cart. Adding is capped at min(available stock, per-order limit) with a clear message. Every view re-validates lines against live data, flagging unavailable, out-of-stock or over-limit lines. Those lines are excluded from the subtotal, and checkout is blocked until the cart is fixed. **Carts never reserve stock.** On login the guest cart merges into the account cart without exceeding stock or limits, and the guest cart is then deleted (replay-safe). Add-to-cart uses fetch with a toast and falls back to a normal form post without JavaScript.

**Checkout (no order placement until Phase 6):** one page for guests and signed-in customers.
- **Customer details:** guest email is optional unless the `guest_email_required` setting is on.
- **Delivery method:** home delivery (saved address or a new one, with a town autocomplete) or pickup station (grouped by county, showing address, hours, instructions, map link and fee).
- **Payment:** only configured methods are offered. COD is disabled where the zone or station disallows it.
- **Live preview:** the fee, total and ETA preview come from `/checkout/quote`. On submit, `CheckoutService::prepare()` re-validates everything and **reprices server-side**; posted fees or totals are ignored. It then shows a review page. No order is created and no stock is touched yet (tested).

**DeliveryService (read side):** zone matching where a town-specific rule beats a county-wide one, free-delivery threshold, COD eligibility, and pickup restrictions (inactive, maximum order value, daily capacity). Demo data has 4 zones and 6 pickup stations (one inactive, one without COD). Some counties, such as Mandera, are deliberately uncovered so the "not delivered here" path can be tested. Admin management of zones and stations comes in Phase 8.

**Notifications:** `NotificationService` uses PHPMailer for SMTP (configure `mail.smtp` in `config.local.php`). The default dev driver `log` writes full messages to `storage/logs/mail-*.log`. Every message is stored in `notifications` with secrets redacted. SMS goes to the outbox only.

**Moved to later phases:** guest-to-account order linking (D3) needs orders, so it moves to Phase 6. Coupons are applied in Phase 6 with order pricing. Purging abandoned guest carts is added to the scheduled worker in Phase 7.

## Phase 6 notes (2026-10-17)

**Order placement (`OrderService::place`)** runs as one transaction:
1. Validate the cart and reprice everything on the server (`CheckoutService::prepare`, including coupons with a row lock).
2. Lock inventory rows in ascending variant order (`SELECT … FOR UPDATE`) and recheck availability.
3. Create the order with a random `WM-XXXXXXXX` number, item snapshots and the fulfilment snapshot.
4. Redeem the coupon.
5. Create one reservation per item and increment `reserved`, writing a `reserve` ledger row.
6. Write the status history and the private access token (stored as an HMAC).
7. Commit, then clear the cart and send notifications.

Any failure rolls back everything (tested with a fault injected after the first reservation).

**Idempotency:** the checkout page's one-time token is the idempotency key, and `orders.idempotency_key` is UNIQUE.
- Retries, refreshes and double-clicks return the same order, including concurrent ones (tested with 6 parallel processes).
- A race that sees an emptied cart also resolves to the existing order.
- A stale or forged token never creates an order.

**Initial states:**
- M-Pesa: `awaiting_payment` / `unpaid`, stock held for `reservation_minutes` (30).
- COD: `pending_confirmation` / `cod_pending`, stock held for `reservation_minutes_cod` (240; decided by the user). If `cod_auto_confirm` is on, the order is `confirmed` and the reservation has no expiry.

**Customer messages (F):** "Order placed" with next steps. M-Pesa instructions show the Till, Paybill or phone number from settings, the amount, and the reservation deadline. Wording never claims payment or readiness. "Stock no longer available" names each item and returns the customer to their cart, which is preserved.

**Access and tracking:**
- The confirmation page is visible only to the placing session or the owner.
- Private link `/track/WM-…?t=token`: the token is checked, the session is granted access, then the customer is redirected to a token-free URL (`no-referrer`, `no-store`, `noindex`).
- `/track-order` lookup by order number + phone: rate-limited, with one generic error for every failure.
- Token and lookup holders see masked details (first name, `07•• ••• 333`, town only). Owners see full details.
- An order number alone grants nothing.

**Guest → account linking (D3):** a 6-digit code goes to the phone and email recorded on the order. The code is stored as a hash, valid for 15 minutes, with 5 attempts. Failed attempts are committed (a bug where they rolled back was caught in review). The response is identical for unknown orders, and successful links are audited. Note: until an SMS gateway is configured, codes for orders without an email only reach the SMS outbox.

**Fixes from testing:**
- *Privacy:* logout kept order-access grants in the session, so the next person on a shared browser could open the previous customer's confirmation page. Logout now wipes the whole session.
- *Mobile:* the Add to cart button sat under the fixed bottom nav on phones. Product pages now use a fixed buy bar instead (Jumia pattern), and `scroll-padding-bottom` keeps keyboard focus visible.
- *Router:* route placeholders now support regex quantifiers like `{8}` (unit-tested).

**Test tooling:** `tests/scripts/race_place_order.php` (multi-process race worker), and `tests/Browser/checkout-flow.mjs` (real-browser guest checkout with a double-click, confirmation and private link on a second device). `ReservationService::$faultInjector` is a test-only hook that is ignored unless `app.env = testing`.

**Dev data:** a browser test order `WM-3TMQUGMA` (COD, pickup Thika) exists in the dev database and holds 1 kettle until its reservation expires. The Phase 7 expiry worker will release it.

## Phase 7 notes (2026-10-17)

**Reservation transitions (`ReservationService`):**
- `releaseForOrder` (cancel/reject) and expiry, `consumeForOrder` (hand-over) and `holdUntilFulfilment` (on confirmation).
- Each transition is a guarded `UPDATE … WHERE status = 'active'` inside the caller's transaction, with inventory rows locked in variant order, so repeated or concurrent calls are no-ops.
- The ledger's UNIQUE(reservation_id, movement_type) is a second safeguard.
- Consumption refuses unless *every* item still has an active reservation, so expired, released or consumed stock is never consumed. It sets `order_items.fulfilled_qty`.

**Order workflow (`OrderWorkflow`):** the only way a status changes.
- Transitions are whitelisted and filtered by fulfilment method.
- Guards:
  - M-Pesa orders can't be confirmed until the payment is verified.
  - Only COD orders can be confirmed without payment.
  - Confirmation needs a full reservation.
  - Only the system can expire an order.
  - Only staff can move fulfilment.
- Side effects run in the same transaction: release on cancel or expiry, void coupon, refund obligation if already paid, consume at dispatch or collection, timestamps and actor.
- Every transition writes status history; staff transitions are also written to the audit log.
- Cancelling after hand-over is refused with "use the returns process" (C6).

**Expiry worker:** `bin/expire-reservations.php` (plus `.bat` for Task Scheduler) uses `GET_LOCK` for single-runner behaviour and processes each order in its own transaction.
- Expired orders are notified by email and SMS, and abandoned guest carts are purged.
- A small fallback sweep runs on cart, checkout and product pages (once a minute, at most 20 orders).
- Scheduling instructions are in `docs/OPERATIONS.md`. **The Windows scheduled task is not created automatically** (it's a system change and needs admin rights).

**C5 policy confirmed by tests:** a submitted-but-unverified M-Pesa payment does *not* extend the reservation; the order expires and the stock is released.

**Customer cancellation:** available on order pages for the owner or a verified guest while the order is `awaiting_payment` or `pending_confirmation`, with a reason. After that, customers contact support.

**Staff order management (`/admin/orders`):** list with status tabs and counts, search by number, phone or name, and a "stock held until" column. The order page shows the allowed next steps only, each needing its own permission:
- Confirming COD orders, processing and packing: `orders.manage`
- Dispatch, out for delivery and delivered: `orders.deliver`
- Cancel: `orders.cancel`, with a reason

It also shows the reservations, the order's stock ledger and the attributed history. Pickup readiness and collection come in Phase 8; M-Pesa confirmation by payment verification comes in Phase 9.

**Tests added:** 19, covering scenarios 3, 4, 5, 7, 8 and 12 (release half), the named lock, four concurrent worker processes, workflow guards, refund-on-cancel, history, HTTP permission checks per action, and the CLI worker run as a real process.

## Phase 8 notes (2026-10-17)

**Admin, delivery zones (`delivery.manage`):** create and edit zones (fee, free-delivery threshold, ETA range, COD, active, order) and add or remove covered locations, either a whole county or a specific town. A location already covered by another zone gets a friendly error naming that zone. The list highlights counties with no active delivery.

**Admin, pickup points (`pickup_points.manage`):** create, edit, search, activate and deactivate.
- **Validation:** unique code, http(s)-only map links, coordinate ranges, normalised phone.
- **Deactivating** warns how many open orders still use the station.
- **Existing orders are unaffected:** they keep their snapshot (tested after rename, move and deactivation).

**Pickup collection (B4):**
- **Mark ready (`orders.manage`):** "Ready for collection" generates an 8-character code in the same transaction. The code is stored as an HMAC (to verify) and AES-GCM-encrypted (for authorised re-display), never in plain text.
- **Notifying the customer:** the code is always sent by SMS and email, regardless of preferences, and redacted from the stored outbox.
- **Who sees the code on screen:** only the signed-in owner, the session that placed the order, or a private-link holder. A number + phone lookup does **not** reveal it, because phone numbers are semi-public.
- **The collection desk (`orders.collect`):**
  - Staff enter the code and confirm the ID check, and the system checks it in constant time.
  - Attempts are limited to 5 per order per 15 minutes and 30 per staff member, and every failure is audited.
  - A correct code moves the order to `collected` and deducts stock exactly once. The workflow records the staff member, time, outcome and note, and marks the code as used.
- **Re-issue (`orders.manage`)** invalidates the old code and clears the lock. Cancelling invalidates the code and releases stock.
- **No bypass:** the workflow refuses `collected` unless the desk verified the code, and the generic status endpoint can't request it.
- **Overdue pickups:** orders waiting longer than `collection_hold_days` (7) are flagged on the desk and can be cancelled from the order page. They are **not** auto-cancelled; that's left as a business decision.

**Public `/pickup-stations` directory**, linked from the top bar and the delivery & pickup page.

**Bug found and fixed:** releasing stock wrote the free-text cancellation reason into `stock_movements.reason`, a 40-character code column. Under strict SQL mode, any staff cancellation with a normal-length reason crashed. The ledger now stores a code (`cancelled`, `expired` or `fulfilment`) plus the full text in `note`, with a regression test.

**Test infrastructure:** one full run took about 11 minutes. Two later runs took about 40 seconds and no individual test exceeded 3 seconds, so the cause (a probable one-off lock wait or antivirus scan) wasn't reproducible. The test bootstrap now sets short lock timeouts so any future stall fails fast with a clear error.

## Phase 9 notes (2026-10-17)

**Customer submission:** on the order page (owner or verified guest) the customer enters the M-Pesa code, the paying phone and the amount.
- The code is single-use across all orders (DB-unique plus a friendly error), with one pending submission at a time and at most `max_payment_submissions` (3) per order. Submissions are rate-limited.
- The order moves to `awaiting_verification` only. The reservation is **not** extended (C5), and the message says plainly that this is not yet confirmed.
- Customers whose order already expired can still submit a code, so a late payment is never lost.

**Verification (`payments.verify`):**
- The queue at `/admin/payments` sorts by how soon each order's stock hold expires. Staff enter the amount shown on the M-Pesa statement.
- Underpayment can't be verified. Overpayment records a refund for the difference.
- Exactly one decision per submission is possible (row lock, guarded update, UNIQUE in the DB), and each decision is audited.

**Outcomes of verification:**
- Fully reserved order → `confirmed`, and its stock is held until fulfilment.
- **Order already expired → `requires_resolution`** with an open case. No stock is promised, and the customer is told the order is under review.
- Order already cancelled → refund due.

**Reject:** the order goes back to `awaiting_payment` so the customer can retry. If money was actually received (wrong amount, wrong account), a refund obligation is recorded and the customer is told.

**Resolutions (`resolutions.manage`, `/admin/resolutions`):** each case shows current stock. Staff can:
- **Re-reserve and confirm** — only if stock is available now; the new reservation is held until fulfilment.
- **Cancel with refund** — records a refund obligation.

Both close the case with the outcome and the staff member, and notify the customer.

**COD cash (`cod.collect`, `/admin/cod`):** cash is recorded only after hand-over, once per order (DB-unique). An amount that differs from expected needs an explanation. `payment_status` changes to `cod_collected` independently of `delivered`/`collected`. Every record is audited.

**Store settings (`settings.manage`, super admin only by default):** store contacts, M-Pesa Till, Paybill and Send Money details (a method stays hidden at checkout until its number is set), submission limit, both stock-hold windows, COD auto-confirm, guest email requirement, pickup hold and return window. The settings are a validated whitelist. Changes to payment numbers get their own audit entry (`settings.payment_destination_changed`).

**Workflow addition:** entering `requires_resolution` opens a case; leaving it closes the case with an outcome.

**Not set yet (your input needed):** the real Till, Paybill and Send Money numbers. Until they're set, checkout offers only cash on delivery.

## Phase 10 notes (2026-10-17)

**Dashboard:**
- **Stat tiles:** booked sales today and over 7 days (with change vs the previous 7 days), cash received (M-Pesa + COD), and average order with cancelled and expired counts.
- **Chart:** a 14-day booked-sales column chart.
- **"Needs attention" queue:** COD orders to confirm, payments to verify, resolutions, orders to prepare or dispatch, overdue pickups, unrecorded COD cash, refunds due, and out-of-stock or low-stock variants. Each item links to its screen.
- **Also:** latest orders and stock alerts.
- **Permissions:** every widget is gated. Revenue figures need `reports.view`; `orders.view` alone does not show sales. This was caught by a test, because inventory clerks have `orders.view`.

**Chart (dataviz method):**
- **Form and colour:** a single series in a validated brand step, `#13784f`. The validator passes the lightness band, chroma floor and ≥3:1 contrast; the deeper brand green `#0b5a3a` failed the band and chroma checks.
- **Marks:** ≤24px columns, 4px rounded data-end, square at the baseline, hairline solid grid, compact ticks, and no legend (a single series).
- **Interaction:** tooltips on hover **and** keyboard focus, inserted with `textContent`, plus a "Show as table" view so no value is hover-gated.
- **Server-rendered SVG**, so no chart library is needed and CSP stays strict.
- Not yet verified by automated pointer test: the tooltip positioning. The page loads without console errors.

**Reports (`reports.view`):**
- **Tabs:** sales (summary, daily chart, by payment method, by fulfilment and by status), top products, stock, M-Pesa payments, COD reconciliation (missing and mismatched cash highlighted) and refunds.
- **Date filter:** one row above the content, with presets and a custom range (normalised and capped at 366 days).
- **Definitions** are stated on the page.
- **CSV export** for every report, audited, with formula-injection protection (`=`, `+`, `-`, `@` cells are prefixed; real negative numbers are left alone).

**Staff & roles (`users.manage`, super admin only by default):**
- **Adding staff:** add or promote an existing customer. No password is ever chosen by an admin; the new staff member gets an emailed set-password link.
- **Managing access:** edit roles, suspend (signs them out everywhere immediately), reactivate, or remove staff access (history kept).
- **Safeguards (tested):** no editing your own roles or suspending yourself; only a super admin can grant or remove super admin; the last active super admin can't be removed or suspended.
- **Role matrix:** displayed on the page.

**Customers (`customers.view`):** read-only search and profile with orders and addresses. Viewing a profile is audited (personal data access).

**Audit log (`audit.view`):** filter by date, area, entity and ID, and staff member, with before and after values. Audited CSV export (≤10,000 rows). The log is append-only in the application, and secrets are already redacted.

**Content (`content.manage`):**
- **Banners:** text or image slides, scheduled, ordered. **Links must be internal paths** (no off-site redirects from the home page). Images are re-encoded to WebP and darkened behind text for contrast.
- **Flash sales:** title and time window, with items added by SKU. The sale price must be above zero and below the normal price, and a variant can't be in two overlapping active sales (also checked when a window is edited). Sale prices flow through the single `PricingService` rule to the storefront, cart and checkout.

## Phase 11 notes (2026-10-17)

**Returns (`ReturnService`, `returns.manage`):**
- **Customer request:** from the order page, with item quantities, a reason and details. Allowed only for delivered or collected orders, within `return_window_days` (7), up to the quantity received, with one open return per order (enforced in code and by a DB unique key). Migration 008 adds the refund amount and completion note.
- **Staff steps:** approve or reject (a rejection needs a reason the customer sees), then mark the items received, then **inspect each item**:
  - **Pass** → `return_restock`: on-hand increases.
  - **Fail** → `return_writeoff`: recorded, no stock change.
  - Each item is inspected exactly once (guarded update plus UNIQUE(return_item_id, movement_type)). Stock is **never** restored automatically, on request or on receipt.
- **Completion:** staff set the refund amount. It's capped at the returned items' value at the price paid, and 0 requires a reason. Completion creates a refund obligation.
- **Order status afterwards:** a full return makes the order `returned`; a partial one restores `delivered`/`collected` **without** rerunning hand-over side effects. The original delivery time is kept, and no second consumption happens; this bug was caught during the build.

**Refunds (`refunds.manage`):** queue of refunds due, showing the phone the customer paid from. Mark a refund paid (method plus a reference such as the M-Pesa code) or cancel it with a reason; both are audited and the customer is notified. When nothing remains due, the order becomes `refunded`; if every refund was cancelled, the order's real previous payment status is restored (COD cash vs verified M-Pesa).

**Reviews (`ReviewService`, `reviews.moderate`):**
- **Who can review:** the signed-in owner of a delivered or collected item, once per order item (DB-unique), from **Account → Reviews**.
- **Moderation:** every review is moderated. Ratings are recalculated from **approved reviews only**.
- **Product page:** shows the average, the count and each review with "Verified purchase", the reviewer's first name plus surname initial, and the variant. Text is escaped.
- **Moderation guidance:** publish honest negative reviews; reject only abuse, personal data or spam.

**Notifications outbox (`customers.view`):** every message, filterable by status. Failed emails can be re-sent **except** templates whose stored body was redacted (password reset, claim code, collection code, order-placed link), because those must be re-issued through their original action.

**Correction during the build:** I suspected the admin double-submit guard would drop the clicked button's value (used by inspection and moderation forms). A real-browser check (`tests/Browser/submitter-check.mjs`) showed Chromium keeps it either way, so the suspected bug wasn't real. The guard now disables buttons on the next tick as a defensive measure, verified in Edge.

## Phase 12 notes (2026-10-18)

**Security:**
- **Route sweep (`RouteSecurityTest`):** every route is checked automatically, so a route added later can't skip these rules:
  - every admin route has `staff` plus a `can:` permission;
  - every `/account` route requires sign-in;
  - no route opts out of CSRF;
  - sign-in, reset, tracking, claim, payment and collection-code routes are rate-limited;
  - no GET route changes state.
- **End-to-end checks (`SecurityHardeningTest`):**
  - the session ID changes at sign-in and sign-out (no session fixation);
  - a foreign `Referer` is never used as a redirect target;
  - emailed links use the configured site URL, ignoring a forged `Host` header;
  - CSP, frame and nosniff headers are on both storefront and admin;
  - the shipped config has `debug = false`.
- **Other hardening:**
  - a PHP file planted under `uploads/` is never executed (`RoutingAndSecurityTest`);
  - HSTS is sent when `session.secure` is on.
- **Static review:**
  - all 407 echo expressions in templates are escaped or proven safe;
  - every SQL interpolation is a whitelisted identifier or placeholder list.
- **Dependency audits:**
  - `composer audit` is clean;
  - `npm audit` (test tooling only) was fixed by upgrading puppeteer-core to 25.12.0 and is now clean.

**Accessibility (axe-core 4.13, WCAG 2.1 A/AA, `tests/Browser/a11y.mjs`):** 0 serious or critical issues on 41 pages (storefront, account, checkout, admin). Fixes made:
- links in running text are underlined (1.4.1);
- `.text-danger`, `code` and `.btn-outline-secondary` use darker shades that pass 4.5:1 on the grey admin surfaces (1.4.3).

**Responsive:** 129/129 checks with no horizontal overflow and no console errors, at phone 375, tablet 768 and desktop 1366 widths:
- 17 public pages, including cart and checkout with an item;
- 26 admin and account pages.

Wide admin tables scroll inside their own panel.

**Concurrency (`HeavyConcurrencyTest`, real processes):**
- **Crossed carts:** 16 buyers with two-line carts added in opposite orders compete for 7 + 7 units. Exactly 4 orders win (the arithmetic maximum), there is no deadlock crash, nothing is oversold, and no order is left partly reserved.
- **Verification vs expiry:** 8 M-Pesa verifications race 2 expiry workers on already-due reservations. Every order ends either `confirmed` with its stock held, or `requires_resolution` with its stock released exactly once — never both. Start times are staggered so both outcomes occur, which the repeated runs confirmed.
- **Earlier coverage still passes:** C9 races (8 buyers for 1 unit, 6 identical submissions, 4 concurrent expiry workers).

**Performance (20,000 products / 40,000 variants / 30,000 orders, XAMPP defaults):**

| Query | Before | After |
|---|---|---|
| Category page (2,500 matches) | ~820 ms | ~160 ms |
| Search with no matches | ~770 ms | ~150 ms |
| Home "Top deals" | ~525 ms | ~70 ms |
| Admin Stock report | 1.6 s, 40,000 rows rendered | 200 lowest-stock rows on screen; CSV has all |

Changes behind these numbers:
- **Migration 009:**
  - `ix_prod_created` no longer starts with `is_active`; it made the optimiser scan every product;
  - new covering index `ix_var_deal`.
- **Card data:** images and option counts are loaded only for the rows displayed. As subqueries, MariaDB ran them for every match before sorting.
- **Variant join:** the default variant is joined through its unique key.
- **Search:** the full-text match and the brand/category-name matches run as subqueries executed once each, so the full-text index is used.
- **Empty results:** a listing with no matches skips its page and facet queries.
- **Top deals:** ranked in two stages.

Still slower: broad, catalogue-wide queries scale with the number of matches — about 0.5 s for a 2,000-match search and about 1 s for "on sale" across all 20,000 products. Recommended setting: raise MariaDB's `innodb_buffer_pool_size` from XAMPP's 16 MB (see `docs/OPERATIONS.md`). At 256 MB, category pages measured about 30% faster again.

**Regression:**
- PHPUnit: 256 tests / 1,666 assertions green (~65 s).
- Browser checks:
  - guest checkout E2E with a double-click on Place order;
  - submit-button value preserved;
  - accessibility and responsive sweeps.
- Two browser harness fixes:
  - the checkout script pointed at a renamed product slug;
  - the submitter check relied on `setContent` over a JSON page, which Edge's JSON viewer now blocks. The form is now served from an intercepted same-origin URL.
- One full run took 4 h 16 min instead of ~65 s, while the date rolled over. Not reproduced:
  - an immediate full rerun took 64 s;
  - eight back-to-back runs of the race tests took 13 s each;
  - cleaning up a large seeded dataset takes 2 s.

  The likely cause is the PC sleeping, but this isn't proven.

## Phase 13 notes (2026-10-18)

- **Docs:** `INSTALL.md` (XAMPP install, configuration, production settings, roles, scheduled tasks, post-install checks, running the tests), `BACKUP_RESTORE.md` and `ACCEPTANCE_REPORT.md` are new. `OPERATIONS.md` links to them.
- **Backups now cover everything needed for recovery:**
  - `bin/backup.php` copies `public/uploads` and `config.local.php` (the app key) next to each database dump; `--db-only` skips them. Before this, images and the key were not backed up at all.
  - Retention removes each dump together with its files folder.
- **New `bin/restore.php`:**
  - refuses to replace a non-empty database without `--force`;
  - with `--force`, dumps the current data first (`pre-restore-…sql`);
  - can restore uploads; never overwrites `config.local.php`.
- **Shared code:** dump and import logic lives in `Waimart\Core\Backup`. Credentials go through a temporary defaults file, and database names are checked against a whitelist pattern.
- **Restore drill:** the live `waimart` backup was restored into `waimart_test`; all 47 table checksums were identical. `BackupRestoreTest` (4 tests) repeats the round trip on every test run.
- **composer.json:** now declares `ext-gd` and `ext-fileinfo`, which image upload already needed. Only the lock file hash changed; no package versions changed.
- **Final run:** 263 PHPUnit tests / 1,684 assertions, 0 failures, 0 skips (~60 s). Browser checkout, submitter, accessibility and responsive checks all pass.

## Assumptions (no base spec supplied)
1. **Brand:** "Waimart" (from the logo). The prompt's "Walmart" is treated as a typo.
2. **Jumia-style storefront:** mega-menu categories, hero carousel, flash sales with countdown, deal sections, brand pages, search with filters (category, brand, price, rating), sort, product gallery, specs tab, ratings and reviews, wishlist, recently viewed.
3. **Out of scope** (not in amendment): multi-vendor marketplace, seller portal, loyalty points, gift cards, live chat. Can be added later.
4. **COD orders need staff confirmation** before the reservation is held until fulfilment (`cod_auto_confirm` setting, default off). This stops fake COD orders from locking stock indefinitely.
5. **Notifications:** email via SMTP when configured, otherwise written to the outbox and log. SMS is outbox only, because no SMS provider was specified.
6. **Currency** KES only. **Language** English.
7. **Coupons:** basic percentage/fixed coupons with validity dates and usage limits ("discounts" in C2).
8. **Partial fulfilment:** not in v1. Order items track fulfilled quantities so it can be added later.
9. **Database name:** `waimart`; test database `waimart_test`.
10. **Legal copy:** the Terms, Privacy and Returns pages are sensible starting templates (including a reference to the Kenya Data Protection Act 2019). They must be reviewed by the business or a lawyer before launch.
