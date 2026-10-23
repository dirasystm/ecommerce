# Abandoned cart recovery, loading feedback and tables: implementation report

**Date:** 22 October 2026 · **Branch:** `feature/rebrand` · **Migration:** 034 · **Rollback:** `database/rollback/rollback_marketing.sql` (031–034)

## 1. Summary

| Feature | Result |
|---|---|
| Abandoned cart recovery | Server-side cart activity and detection after a configurable period (default 60 minutes). Email and SMS reminders, each recorded separately, with its own secure, expiring link to the cart. Postponed when the customer comes back. Stopped by an order, an empty cart, unavailable items or an opt-out. Retries with a configurable limit. Recovery figures, the reminder log and settings under **Marketing Intelligence → Abandoned carts**. |
| Loading feedback | One reusable system (`public/assets/js/ui.js`): a full-page overlay with a short delay, inline loaders, button spinners with double-submit protection, skeletons, a page-navigation bar, and a fetch helper with timeouts. Every indicator is removed on success, error, timeout or "back". |
| Tables | All 63 views with tables were audited. 44 database-backed lists now use one standard: server-side paging (10 rows by default; 15, 25 and 50 available), "Showing 1–10 of N", live search with a 300 ms pause, filters, sortable columns, and loading, empty and error states. The other 19 are bounded (one order's items, matrices, aggregates); they keep their layout with the shared styling and are listed in the audit below. |

## 2. Files and components

**New**
- `app/Core/ListParams.php`: page, size, search and sort from the query string, validated and allowlisted. Its `fetch()` returns only the current page and the total.
- `app/Views/partials/table/toolbar.php`, `app/Views/partials/table/footer.php`; helpers `sort_th()` and `empty_row()` in `app/helpers.php`.
- `public/assets/js/ui.js`: loaders and live tables; loaded before `app.js` in the main, admin and seller layouts.
- `app/Services/Marketing/CartRecovery.php`: recovery links, reopening a cart, recovered orders, figures and logs.
- `app/Views/admin/marketing/abandoned-carts.php`; `database/migrations/034_abandoned_cart_recovery.sql`.
- Tests:
  - `tests/Integration/AbandonedCartTest.php`
  - `tests/Feature/AbandonedCartHttpTest.php`
  - `tests/Feature/TablesHttpTest.php`

**Changed**
- `Automation.php`:
  - cart reminders measured in minutes from the last activity;
  - guests reached through consented leads;
  - a configurable retry limit;
  - only available items in reminders;
  - a link per reminder.
- `CartService.php`: activity time on every cart change and on opening the cart or checkout.
- `Signals.php`: crediting recovered orders.
- `MessageTemplates.php` and `EmailLayout.php`: the requested subject and wording, and a "Return to your cart" button.
- `SettingsService.php`: four new settings.
- About 30 controllers and services and about 45 views: tables (section 7).
- `app.css`: loader, table, toolbar, footer and empty-state styles.
- `app.js`:
  - add to cart, wishlist, checkout totals, privacy banner and "Not interested" use the loaders.
  - It also fixes a regression from the marketing release: a helper name collision had stopped checkout totals from updating live and made add-to-cart reload the page.
- `admin.js`: the old double-submit code is replaced by `ui.js`.
- Payment and checkout forms show "Processing your payment details. Please wait…" and "Placing your order. Please wait…".

## 3. Database (migration 034, additive only)

- **`carts`:** `last_activity_at` (back-filled from `updated_at`) and `visitor_id`, both indexed.
- **`marketing_messages`:** `link_token_hash` (unique, the hash only), `clicked_at`, `recovered_order_id`, `recovered_amount`, plus an index on (automation, sent_at).
- **Settings:** `cart_abandon_minutes` (60), `cart_reminder_link_days` (7), `cart_recovery_guests` (1), `marketing_max_attempts` (3).
- **Automation:** the cart reminder's first step is kept at 1 hour; the minutes setting drives it.

## 4. Scheduler

Reminders are sent by the existing marketing scheduler. Nothing new needs to be scheduled.
- **Windows / XAMPP:**
  ```
  schtasks /Create /TN "Ecommerce Marketing" /SC MINUTE /MO 15 /RU SYSTEM /TR "\"C:\xampp\htdocs\ecommerce\bin\marketing-run.bat\""
  ```
- **Linux (cron):**
  ```
  */15 * * * * php /path/to/ecommerce/bin/marketing-run.php --quiet >> /path/to/ecommerce/storage/logs/marketing.log 2>&1
  ```

With a 15-minute schedule, a reminder goes out 60 to 75 minutes after the last activity. Quiet hours (20:00–08:00) and the daily and weekly limits still apply.

## 5. Email and SMS requirements

- **Email:** works with the SMTP settings already in Email & SMS (noreply@example.co.ke).
- **SMS:** needs an approved Talksasa sender ID ("TALKSASA" was refused). Until then, choose **Email** only under Abandoned carts → Settings.
- **Delivery tracking:**
  - A failed send is retried, then marked **Failed**; it is never reported as sent.
  - "Logged" means it was recorded without being sent (development mail log, or SMS switched off).
  - SMS delivery comes from Talksasa delivery reports where they were checked.
- **Who receives reminders:** only people who agreed to marketing on that channel (Kenya Data Protection Act). Guests are reached only if they signed up for the newsletter with consent in the same browser. Every reminder has an unsubscribe link.

## 6. Loading indicators: how to use them

| Need | Use |
|---|---|
| A normal form | Nothing to do. POST forms get a spinner on the button that sent them and cannot be sent twice; the label is inferred, or set with `data-loading-text="Saving…"`. |
| A form that should block the page (checkout, payment) | `data-loading-overlay="Placing your order. Please wait…"` on the form. |
| File uploads | Automatic: "Uploading… Please keep this page open." No percentages are invented (the website updater keeps its real progress bar). |
| Your own request | `WM.setButtonLoading(btn, 'Saving…')` / `WM.clearButtonLoading(btn)`, `WM.showInlineLoader(el, 'Loading…')` / `WM.hideInlineLoader(el)`, `id = WM.showGlobalLoader(msg)` / `WM.hideGlobalLoader(id)`, `WM.showSkeleton(el, 'table')` / `WM.clearSkeleton(el)`, `WM.fetch(url, options, timeoutMs)` |
| Opting out | `data-no-loader` on a form |

**Guarantees:**
- The global overlay is counted per caller, so one request never hides another's overlay. It waits 300 ms, so fast requests do not flicker.
- Requests time out after 30 seconds with a readable message.
- Everything resets when a page is restored with "back".
- Reduced-motion users get a gentle pulse.
- Indicators use `role="status"` and `aria-busy`.

## 7. Table audit

The **Standard** treatment means:
- server-side paging (10 rows by default; 10, 15, 25 or 50);
- live search on the listed fields, run on the server with bound parameters;
- filters, sortable columns from an allowlist, and loading, empty and error states;
- a correct page after a search or deletion;
- rows-per-page and page links that keep the filters.

| # | Page (view) | Treatment | Search fields / filters |
|---|---|---|---|
| 1 | Orders (`admin/orders/index`) | Standard | Order number, phone, name, email · status tabs, method, payment status · sort placed, total, number |
| 2 | Seller orders (`admin/orders/seller-orders`) | Standard | Part or order number, seller · status tabs, seller · sort since, value |
| 3 | Customers (`admin/staff/customers`) | Standard | Name, email, phone · status · sort name, orders, booked value, joined |
| 4 | Customer: orders (`admin/staff/customer`) | Standard (paged) | — |
| 5 | Collection desk (`admin/collections/index`) | Standard | Order number, customer · pickup point · sort ready, order |
| 6 | Returns (`admin/cs/returns`) | Standard | Return or order number, customer, seller · status tabs · sort requested |
| 7 | Notifications outbox (`admin/cs/notifications`) | Standard | Recipient, template, subject, order · status tabs, channel |
| 8 | M-Pesa payments (`admin/payments/index`, both tabs) | Standard | Code, order, customer, phone · oldest hold first |
| 9 | Cash on delivery (`admin/payments/cod`) | Standard + bounded "latest 30 recorded" | Order, customer · sort expected |
| 10 | Seller payouts (`admin/finance/payouts`) | Standard | Payout number, seller, reference · status tabs · sort amount, requested |
| 11 | Seller ledger (`admin/finance/ledger`) | Standard | Order part, payout, note · balance, entry type |
| 12 | Commission report (`admin/finance/commissions`) | Standard over computed rows (one per seller); rule tables bounded | Seller |
| 13 | Marketplace report (`admin/finance/marketplace`) | Standard over computed rows; totals cover all sellers | Seller · sort sales, name, net |
| 14 | Sellers (`admin/sellers/index`) | Standard | Store, address, owner email · status tabs · sort name, products, approved |
| 15 | Seller applications (`admin/sellers/applications`) | Standard | Number, store, county · status tabs · sort submitted, store |
| 16 | Product approvals (`admin/products/moderation`) | Standard | Product, seller, category · status tabs |
| 17 | Products (`admin/products/index`) | Standard | Name, SKU · seller, category, status, approval · sort newest, name, price, available |
| 18 | Categories (`admin/categories/index`) | Standard (tree order kept) | Name, slug, parent · status · sort name, products |
| 19 | Brands (`admin/brands/index`) | Standard | Name, slug · status · sort name, products |
| 20 | Inventory (`admin/inventory/index`) | Standard | Product, variant, SKU · stock level · sort SKU, product, on hand, available |
| 21 | Stock ledger (`admin/inventory/show`) | Standard | Reason, note, staff, order · movement type |
| 22 | Pickup points (`admin/pickup/index`) | Standard | Name, code, town, county · status · sort code, name, location, waiting |
| 23 | Station applications (`admin/pickup-applications/index`) | Standard | Reference, business, town, county, contact, phone · status tabs |
| 24 | Seller cases (`admin/disputes/index`) | Standard | Case, order or part number, seller, customer · open/closed · sort updated |
| 25 | Banners (`admin/content/banners`) | Standard | Title, subtitle, link |
| 26 | Flash sales (`admin/content/sales`) | Standard | Title · live/scheduled/ended/off |
| 27 | Pages (`admin/content/pages/index`) | Standard | Title, address · status · sort title, last change |
| 28 | Staff (`admin/staff/index`) | Standard (+ bounded role summary) | Name, email · role, status |
| 29 | Audit log (`admin/audit/index`) | Standard | Action, record, staff, IP, details · dates, area, entity, ID, staff · CSV follows filters |
| 30 | Sign-in activity (`admin/audit/sign-ins`) | Standard | Account, typed, IP · staff/everyone, event |
| 31 | Email & SMS log (`admin/messaging/index`) | Standard | Phone, email, order, text · type, status |
| 32 | Campaigns (`admin/marketing/campaigns`) | Standard | Name, channel, platform · sort · period |
| 33 | Leads (`admin/marketing/leads`) | Standard | Name, email, phone (name only without `marketing.leads`) · stage, consent, campaign |
| 34 | Consent changes (`admin/marketing/consent`) | Standard (+ bounded suppression summary) | Email (with `marketing.leads`) · channel |
| 35 | Segment members (`admin/marketing/segment`) | Standard (paged) | — |
| 36 | Abandoned carts (`admin/marketing/abandoned-carts`) | Standard | Customer, email, phone, cart number |
| 37 | Reminder log (same page) | Standard | Customer, email, phone, reason · channel, status |
| 38 | Seller Centre: orders (`seller/orders/index`) | Standard (own orders only) | Order number · tabs · sort confirmed, value |
| 39 | Seller Centre: products (`seller/products/index`) | Standard (own products only) | Name, SKU, category · status tabs · sort |
| 40 | Seller Centre: cases (`seller/disputes/index`) | Standard (+ bounded latest 30 returns) | Case or order number |
| 41 | Seller Centre: earnings ledger (`seller/finance`) | Standard (+ bounded payout requests) | Order part, payout, note · balance |
| 42 | My orders (`account/orders`, cards) | Standard (own orders only) | Order number, product · in progress / delivered / cancelled |
| 43 | My wishlist (`account/wishlist`, grid) | Paged | — |
| 44 | My reviews (`account/reviews`) | Paged | — |
| 45 | Message templates (`admin/messages/index`) | Bounded (about 45 fixed templates in groups): instant in-page filter | Name, description |
| 46 | Delivery zones (`admin/delivery/index`) | Bounded (a few zones with their towns) | — |
| 47 | Homepage sections (`admin/content/home/index`) | Bounded, ordered by hand | — |
| 48–49 | Menus (`admin/content/menus/index`, `show`) | Bounded, ordered tree | — |
| 50 | Flash sale items (`admin/content/sale-form`) | Bounded (one sale's items) | — |
| 51 | Return items (`admin/cs/return`) | Bounded (one return) | — |
| 52 | Order items and history (`admin/orders/show`) | Bounded (one order) | — |
| 53 | Seller detail (`admin/sellers/show`) | Bounded (members of one seller) | — |
| 54 | Dashboard widgets (`admin/dashboard`, `admin/partials/daily-chart`) | Bounded (top 5, 14 days) | — |
| 55 | Payment methods (`admin/payment-methods/index`) | Bounded (4 methods) | — |
| 56 | Reports (`admin/reports/index`) | Aggregates for a period | — |
| 57 | Roles matrix (`admin/roles/index`) | Matrix | — |
| 58 | Website updates history (`admin/updates/index`) | Bounded (latest 15; older ones are rolled back first) | — |
| 59 | Marketing overview, recommendations, segments, campaign links | Aggregates / one campaign's links | — |
| 60 | Product specifications (`catalogue/product`) | Not a listing | — |
| 61 | Customer return form (`orders/return`) | Bounded (one order's items) | — |
| 62 | Seller order detail (`seller/orders/show`), seller report | Bounded / aggregates | — |
| 63 | My consent history (`account/privacy`) | Bounded (latest 50 changes, also in the data export) | — |

**Shared improvements on every table:**
- header cells have `scope="col"` and new tables have captions;
- column headers, rows and hover use one style;
- numbers are right-aligned;
- tables scroll sideways inside their panel on phones, never the whole page;
- the toolbar and footer stack on narrow screens.

**Security:**
- Search terms are bound parameters, with `%` and `_` matched literally.
- Sort keys come from an allowlist; page and size are validated, with the size capped at 50.
- Sellers and customers are filtered by the server-side seller context or the signed-in account; the IDOR test matrix still passes.
- Lead contact details stay masked and cannot be searched without `marketing.leads`.

## 8. Tests

**Results (23 October 2026):**

| Run | Result |
|---|---|
| Full PHPUnit suite | **OK: 563 tests, 6,525 assertions** |
| Re-run after the final checkout fixes (checkout, payments, multi-seller orders, tables, abandoned carts) | OK: 31 tests, 468 assertions |
| `tests/Integration/AbandonedCartTest.php` | 5 tests |
| `tests/Feature/AbandonedCartHttpTest.php` | 3 tests |
| `tests/Feature/TablesHttpTest.php` | 3 tests over all 36 standard admin lists and the customer list |
| Browser: `tests/Browser/checkout-flow.mjs` (phone size) | Passed, with no JavaScript errors |
| Browser: `tests/Browser/live-search.mjs` | Passed, with no JavaScript errors |
| Accessibility (axe, WCAG 2.1 AA) | 7 public pages and 24 admin pages: no serious or critical violations |

**What the integration test (`AbandonedCartTest.php`) covers:**
- reminders only after 60 minutes;
- one email and one SMS per cart, each with its own link;
- new activity postpones the reminder;
- unavailable items or an emptied cart stop it;
- a real SMTP failure is retried, then marked failed (never "sent");
- guests are reached only through a consented lead;
- an order is counted as recovered once.

**What the HTTP test (`AbandonedCartHttpTest.php`) covers:**
- the link sends a signed-out customer to sign in, then back to the cart;
- another account is refused;
- tampered and expired links are refused;
- a guest's cart reopens in the new browser and nothing is ordered;
- the admin page shows figures and masked contacts, has search and filters, and saves settings with validation.

**What the table test (`TablesHttpTest.php`) covers:**
- 10 rows by default and the 15-row option;
- totals, search with filters, sorting, and page links that keep both;
- out-of-range pages;
- hostile input: quotes, `%_`, markup, long text, bad size, sort and page values;
- customers see only their own orders, even when searching for someone else's order number.

**What the browser checkout flow covers:**
- the privacy banner, add to cart, pickup station, delivery quote, cash on delivery;
- a double-clicked "Place order" creates **one** order;
- confirmation and the private tracking link.

**What the live-search check covers:** typing narrows 56 products to 2 in place; clearing restores them; the page never reloads.

**Accessibility pages:**
- **Public:** home, product, cart, checkout, sign-in, register, track order.
- **Admin:** dashboard, orders, seller orders, products, inventory, customers, sellers, staff, audit, Email & SMS, messages, payments, brands, and the Marketing pages (overview, campaigns, link studio, abandoned carts, leads).

**Problems the checks found, all fixed:**
1. Checkout told guests that email was optional while the shop required it ("Require email for guest checkout" is on), so guests who skipped it were sent back with an error. The label now follows the setting.
2. On screens from 768 to 991 px wide, the privacy banner covered the fixed "Add to cart" bar. The banner now sits above the bottom bars at all those widths, and the page keeps room under the banner.
3. A 1-pixel vertical overflow made some tables count as keyboard-unreachable scroll regions. Tables now scroll sideways only, and the keyboard check re-runs after live updates.
4. My new abandoned-cart test left test-database mail settings blank, which broke a later test. It now restores them.
5. The regression from the marketing release described in section 2: checkout totals not updating live, and add-to-cart reloading the page.

## 9. Backup and rollback

- **Before deploying:** run `php bin/backup.php`, then `php bin/migrate.php` (034 adds only).
- **To roll back:** use `database/rollback/rollback_marketing.sql` (031–034, drilled for 031–033 with the schema identical to the backup). Roll back the code together with it.

## 10. Outstanding items and limitations

- **SMS reminders** wait for an approved Talksasa sender ID. Use email only until then.
- **The 15-minute scheduler** must be set up on the live server; reminders are only sent by it.
- **Consent:** reminders reach only customers who agreed to marketing on that channel. Many customers may not have agreed, so recovery figures will start small; this is the lawful default.
- **Guests:** a guest who never gave contact details with consent cannot be reminded. There is no email capture before the order is placed.
- **Tab closing:** closing a tab or signing out does not trigger anything (by design); the inactivity period does.
- **Uploads:** they show a spinner, not a percentage. The website updater keeps its real progress.
- **Bounded lists** (section 7, rows 45–63) are not paged, by design.
