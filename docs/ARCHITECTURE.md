# Basemall — Architecture

Multi-vendor marketplace inspired by Jumia Kenya (single-store until the marketplace upgrade, docs/MARKETPLACE_PLAN.md).
Brand: **Basemall**, "Everything You Need. One Marketplace." (formerly Waimart; see docs/BASEMALL_REBRAND.md). Internal identifiers
keep the old name on purpose: the `Waimart\` PHP namespace, the `waimart` database, the session cookie and `WAIMART_` environment variables.

## 1. Environment (inspected 2026-10-17)

| Item | Value |
|---|---|
| OS | Windows 11 Pro, XAMPP |
| PHP | 8.2.12 (pdo_mysql, bcmath, mbstring, openssl, gd, fileinfo) |
| Database | MariaDB 10.4.32 (InnoDB) |
| Web server | Apache, port 80, mod_rewrite available, DocumentRoot `C:/xampp/htdocs` |
| Tooling | Composer, Git |
| Project path | `C:\xampp\htdocs\ecommerce` (was empty; no existing `waimart` DB) |

Environment caveats handled in code:
- `php.ini` timezone is `Europe/Berlin` → app forces `Africa/Nairobi`.
- `session.cookie_httponly` is empty → app sets HttpOnly, SameSite=Lax, strict mode.
- MariaDB 10.4 has **no `SKIP LOCKED`** → expiry worker uses `GET_LOCK()` + conditional updates.
- MariaDB 10.4 **does enforce `CHECK` constraints** → used for inventory invariants.

## 2. Technology

- PHP 8.2, no framework; Composer PSR-4 autoloading (`Waimart\` → `app/`).
- PDO with `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, prepared statements only.
- Bootstrap 5 + vanilla JavaScript (assets served locally so the site works offline).
- PHPUnit for unit and integration tests, run against a separate `waimart_test` database.
- Money: `DECIMAL(12,2)` in the DB; `bcmath` string arithmetic in PHP via a `Money` helper. Never floats.
- Currency: KES.

## 3. Directory layout

```
ecommerce/
├── .htaccess              # routes everything into public/, denies the rest
├── public/                # only web-exposed directory
│   ├── index.php          # front controller
│   ├── .htaccess
│   └── assets/ (css, js, img, vendor/bootstrap)
├── app/
│   ├── Core/              # Router, Database, Request, Response, View, Session, Csrf,
│   │                      # Auth, Validator, Money, RateLimiter, Config, Crypto
│   ├── Controllers/       # Storefront/*, Account/*, Admin/*
│   ├── Services/          # Business rules (see §5)
│   ├── Repositories/      # SQL lives here
│   └── Views/             # PHP templates (layouts, partials, pages)
├── config/                # config.php + config.local.php (git-ignored secrets)
├── database/
│   ├── migrations/        # numbered .sql files
│   └── seeds/
├── bin/                   # CLI: migrate.php, expire-reservations.php, backup.php
├── storage/ (logs, uploads, backups, cache)   # not web-accessible
├── tests/ (Unit, Integration, Concurrency)
└── docs/
```

Site URL in development: `http://localhost/ecommerce/`.

## 4. Request flow & security foundation

`public/index.php` → bootstrap (config, error handling, session) → Router → middleware
(CSRF, auth, permission, rate limit) → Controller → Service → Repository → View.

- CSRF token on every state-changing form; POST-only mutations.
- Output escaped by default via `e()` helper.
- Passwords: `password_hash()` (bcrypt/argon2 where available).
- Session ID regenerated on login and privilege change.
- Security headers: CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy.
- Uploads: MIME sniffed with `fileinfo`, re-encoded with GD, random file names, stored outside executable paths.
- Errors logged to `storage/logs`, generic message to users. No stack traces in production.
- Rate limiting via a `rate_limits` table (login, password reset, guest tracking, collection-code verification, payment submissions).

## 5. Core services (business rules live here only)

| Service | Responsibility |
|---|---|
| `CartService` | Session/persistent carts, guest→user merge capped at available stock |
| `PricingService` | Server-side recalculation of prices, discounts, delivery fee, totals |
| `DeliveryService` | Zones, eligibility, fees, free-delivery threshold, COD eligibility |
| `PickupPointService` | CRUD, active-only selection, snapshot data |
| `InventoryService` | On-hand / reserved / available, stock movements ledger |
| `ReservationService` | Create, release, expire, consume reservations, exactly once |
| `CheckoutService` | One transaction: validate → lock → reserve → create order |
| `OrderWorkflow` | Allowed status transitions + status history |
| `PaymentService` | Manual M-Pesa / Till / Paybill submissions, verification, COD collection |
| `ResolutionService` | Late-payment-after-expiry cases |
| `ReturnService` | Return requests, inspection, restock, refund obligations |
| `NotificationService` | Email (SMTP or log) + SMS outbox; all messages stored |
| `AuditLogger` | Every admin action, payment decision and inventory movement |

Controllers never change order, payment or stock state directly. They call these services.

## 6. Inventory model

Every sellable unit is a **product variant**. A product without options has one default variant, so inventory always lives at variant level.

```
inventory(variant_id PK, on_hand INT, reserved INT,
          CHECK (on_hand >= 0), CHECK (reserved >= 0), CHECK (reserved <= on_hand))
available = on_hand - reserved
```

The `reserved` counter is a cached sum of `stock_reservations` rows with `status='active'`.
It changes only inside the same transaction that changes a reservation row.
A consistency check (`bin/inventory-audit.php` + test) verifies the two always agree.

### Reservation ledger
```
stock_reservations(id, order_id, order_item_id, variant_id, quantity,
                   status ENUM('active','released','expired','consumed'),
                   created_at, expires_at NULL, released_at, release_reason,
                   consumed_at, UNIQUE(order_item_id))
```
`expires_at` is NULL once an order is confirmed, which means the reservation is held until fulfilment.

### Exactly-once transitions
Each transition is a guarded update inside a transaction:
```sql
UPDATE stock_reservations SET status='released', released_at=NOW(), release_reason=?
 WHERE id=? AND status='active';
-- only if rowCount() = 1:
UPDATE inventory SET reserved = reserved - ? WHERE variant_id=?;
INSERT INTO stock_movements (...);   -- UNIQUE(reservation_id, movement_type)
```
A second release, expiry or consumption attempt matches 0 rows and does nothing. The unique key on `stock_movements` is a second safeguard.

### Stock movement ledger
`stock_movements(id, variant_id, movement_type, on_hand_delta, reserved_delta, reservation_id NULL, reference_type, reference_id, actor_user_id NULL, note, created_at)`

Movement types: `receipt`, `adjustment`, `reserve`, `release`, `expire`, `consume`, `return_restock`, `return_writeoff`.

### Checkout transaction (C2)
1. Validate cart against the DB; recalculate everything server-side.
2. Validate the fulfilment option (zone or active pickup point).
3. `BEGIN`.
4. `SELECT … FROM inventory WHERE variant_id IN (…) ORDER BY variant_id FOR UPDATE`. Locks are taken in a fixed order to avoid deadlocks.
5. Recheck `on_hand - reserved >= qty` for every line; on failure `ROLLBACK` and return a "stock no longer available" message naming the items.
6. Insert the order, order-item snapshots, fulfilment snapshot and reservations; increment `reserved`; write `reserve` movements; add status history.
7. `COMMIT`, then show confirmation.

**Idempotency:** the checkout form carries a one-time `checkout_token` (random, stored in the session). `orders.idempotency_key` is `UNIQUE`. A retry or double-click hits the unique key and returns the existing order instead of creating a second one or reserving twice.

### Consumption point (C7)
- Delivery orders: on transition to **dispatched** (handed over for delivery).
- Pickup orders: on transition to **collected**.

In one transaction: consume the active reservation, set `on_hand -= qty` and `reserved -= qty`, and write a `consume` movement. This is rejected if the reservation is not active.

### Returns
Returned goods are never restocked automatically. Inspection produces `restock` (`on_hand += qty`, `return_restock` movement) or `write-off` (movement only, no stock change).

## 7. Order, payment and fulfilment state

Three separate dimensions on `orders`:

**Order status** (single state machine, transitions whitelisted in `OrderWorkflow`):

```
placed ──► awaiting_payment ──► awaiting_verification ──► confirmed
   │              │                      │                    │
   │ (COD)        └──── expired ◄────────┘                    ▼
   └──► pending_confirmation ──► confirmed               processing
                                                         │        │
                                    delivery ◄───────────┘        └──────► pickup
                       packed → dispatched* → out_for_delivery     ready_for_collection
                                → delivered                         → collected*
   cancelled (before dispatch only)
   requires_resolution (late payment after expiry)
   return_requested → returned            (* = stock consumed)
```

**Payment status:** `unpaid`, `awaiting_verification`, `verified`, `rejected`, `cod_pending`, `cod_collected`, `refund_due`, `refunded`.

**Resolution flag:** `requires_resolution` + reason. It is cleared only through `ResolutionService`.

Rules:
- Submitting an M-Pesa reference only sets `awaiting_verification`. It never confirms the order and never extends the reservation.
- **Confirmation** happens when M-Pesa/Till/Paybill payment is verified or staff confirm a COD order. Confirmation clears `expires_at`, so the reservation is held until fulfilment.
- When an unconfirmed order's reservation expires, the order becomes `expired`.
- When payment is verified after expiry, the system tries to re-reserve stock in a fresh transaction. If stock is available, staff may confirm. If not, the order goes to `requires_resolution` (staff options: re-reserve later, cancel with `refund_due`). The customer is notified either way.
- COD cash collection is recorded separately (`cod_collected`, collected by, amount, time) from the `delivered` status.
- Cancellation after dispatch is refused; the returns flow is used instead.

## 8. Payments (manual, no gateways)

- Methods: `cod`, `mpesa_phone` (send money), `mpesa_till`, `mpesa_paybill`. Till and Paybill numbers and account format are admin settings.
- `payment_submissions(id, order_id, method, payer_phone, transaction_code UNIQUE, amount, submitted_at, status)`. The unique transaction code stops one M-Pesa receipt being reused across orders.
- `payment_verifications(id, submission_id, decision, actor_user_id, note, created_at)`: append-only history.
- Every decision is written to `audit_logs`.

## 9. Guest access & tracking

- `order_number`: random, non-sequential, human-friendly (e.g. `WM-7K3F9Q2X`).
- Guest access token: 32 random bytes, sent in the confirmation link and email. Only its SHA-256 hash is stored.
- Alternative tracking: order number + phone number. This is rate-limited and returns the same generic message for "not found" and "mismatch".
- Order pages send `Referrer-Policy: no-referrer`, and tokens are scrubbed from logs.

## 10. Pickup collection credentials

- When the order reaches `ready_for_collection`, a random 8-character collection code is generated (ambiguous characters excluded).
- Stored as a hash (for verification) and encrypted with the app key (so the customer can see it again on their authenticated or tokenised order page).
- Staff verification is rate-limited per order and per staff member. The code is invalidated on collection or cancellation. Staff, time and outcome are recorded.

## 11. Expiry worker

`php bin/expire-reservations.php` does the following:
1. Takes `GET_LOCK('waimart_expiry', 0)` and exits quietly if another run holds it.
2. Selects due reservations (`status='active' AND expires_at <= NOW()`), indexed on `(status, expires_at)`, in batches.
3. For each order, runs one transaction: lock the order and inventory rows, run the guarded update, decrement `reserved`, write an `expire` movement, move the order to `expired`, queue a notification.
4. Logs errors without customer personal data.

It is scheduled every minute with Windows Task Scheduler (dev) or cron (prod). As a fallback, checkout and product pages run a small sweep (max 20 rows) so dev environments without a scheduler still free stock. The fallback is not a replacement for the scheduler.

Default reservation window: **30 minutes** (`settings.reservation_minutes`).

## 12. Roles & permissions

- **Built-in roles:** `super_admin`, `manager`, `inventory_clerk`, `fulfilment_staff`, `finance`, `support`, `content_marketing`, `delivery_logistics`. Custom roles can be added (`roles.is_system = 0`).
- **What a person may do** = the permissions of their roles ∪ their personal grants (`user_permissions`). Super admin implicitly has all.
  - `Auth::permissionsFor()` reads this per request, so changes apply on the next click.
- **Checks:** every admin route declares `can:<permission>` (a unit test enforces it). Services re-check sensitive rules.
  - **No self-escalation** (`RoleService`, `StaffService`): you can only give permissions you hold, never change your own roles or permissions, and never edit a role you hold.
- **Audit:** all admin mutations are written to `audit_logs(actor, action, entity, entity_id, before_json, after_json, ip, created_at)`. It is append-only, enforced by triggers.
- **Sign-in:**
  - passwords are hashed with `password_hash`;
  - rate limits per account and per network;
  - optional TOTP second step (`MfaService`, RFC 6238) with hashed recovery codes;
  - `must_change_password` and `sessions_revoked_at` gate sessions in `Auth::user()` and the staff middleware.
- **Sign-in activity:** every attempt is recorded in `auth_events` (masked identifiers; append-only).

## 12a. Managed content and settings (Basemall)

| Area | Where it lives | Service |
|---|---|---|
| Settings (typed, grouped, validated, exported/imported) | `settings` + the registry in code | `SettingsService` |
| Brand (name, tagline, colours, logos, icons) | `settings`, `public/assets/brand` | `Brand`, `BrandingService` |
| Pages (drafts, scheduling, revisions), menus, homepage sections | `content_pages`, `content_page_revisions`, `menus`, `menu_items`, `home_sections` | `SiteContentService`, `ContentRenderer` (safe text format, no HTML) |
| Payment methods | `payment_methods` (+ M-Pesa numbers in `settings`) | `PaymentOptions` |
| Message wording | `message_templates` (edited ones only) + built-in wording in code | `MessageTemplates`, `NotificationService::sendTemplate` |
| Order status names | `order_status_labels` (edited ones only) | `StatusLabels` |
| Credentials | `secrets` (AES-256-GCM with the app key, write-only) | `SecretStore` |

## 13. Testing strategy

- **Unit:** Money, Validator, OrderWorkflow transition table, pricing.
- **Integration (real MariaDB `waimart_test`):** checkout, reservations, expiry, cancellation, consumption, payments, returns, ownership checks.
- **Concurrency:** parallel PHP processes (`proc_open`) racing for the last unit, and concurrent expiry runs.
- **HTTP smoke tests:** key pages and forms via cURL against Apache.
- **Role matrix:** every role against every admin route, from the live route table (`AdminRoleMatrixHttpTest`).
- **Scripts:** `tests/scripts/brand_scan.php` (no visible old branding), `tests/scripts/ledger_load.php` (100,000-row ledger timings), `tests/scripts/race_refund.php`.
- Every phase ends with the full suite green. Failures are fixed and rerun before the phase is accepted.
