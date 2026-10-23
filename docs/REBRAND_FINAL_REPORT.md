# Ecommerce: Final Implementation Report

**Date:** 19 October 2026 · **Branch:** `feature/rebrand` · **Scope:** the brief "Rebrand the e-commerce platform to Ecommerce and implement full database-driven management", plus the remaining items of the marketplace upgrade (Phase 10/11).

Stage-by-stage detail, with every decision and fix, is in [REBRAND_NOTES.md](REBRAND_NOTES.md). This report maps the work to the brief's deliverables (section 13) and acceptance criteria.

---

## 1. Deliverables (brief, section 13)

| # | Deliverable | Where it is |
|---|---|---|
| 1 | Rebranded website using the supplied logo | Stage 1. The logo assets (wordmark, icon, full logo, favicon, app icons, social-sharing image) are cut from the supplied file and used across the shop, Seller Centre, admin, emails, SMS, PWA manifest and error pages. Old branding scan: none left (`tests/scripts/brand_scan.php`). |
| 2 | Branding across storefront, checkout, accounts and admin | Stages 1–3. Name, tagline, colours (with a readable-contrast palette), logos and icons are settings, edited in Admin → Settings → Branding. |
| 3 | Database-driven settings and content | Stage 2: typed, grouped, validated settings with export/import. Stage 3: pages with drafts, scheduling and revisions; menus; homepage sections. Stage 4: payment methods, delivery settings, message templates, status names. |
| 4 | Admin interfaces for branding, content, header, footer, payment methods, configuration | Settings (12 areas), Content & marketing (Pages, Menus, Homepage, Banners, Flash sales, Messages), Finance → Payment methods, Fulfilment → Delivery settings. |
| 5 | Secure administrator account management | Stage 5: staff pages with roles, extra permissions, forced password change, reset link, sign out everywhere, two-step reset, recent sign-ins and actions; My account for every staff member. |
| 6 | Configurable roles and granular permissions | Stage 5: Roles & permissions matrix, custom roles, 8 built-in roles (including Content & Marketing, Delivery & Logistics), 36 permissions, per-person grants, no self-escalation. |
| 7 | Audit logs | Every admin change is audited with before/after values (no secrets). The audit log and sign-in activity are append-only, enforced by database triggers. Viewing needs `audit.view`, and exports are themselves audited. |
| 8 | Migrations and schema documentation | Migrations 018–024 (below); `database/rollback/rollback_rebrand.sql`, drilled after every stage; [ARCHITECTURE.md](ARCHITECTURE.md) §12–12a. |
| 9 | List of hardcoded content converted | Section 3 below. |
| 10 | Test results | Section 4 below. |
| 11 | Changed files, new modules, outstanding issues, deployment steps | Sections 2, 5 and 6 below. |

## 2. New modules and changed files

223 files changed (76 added, 130 modified, 17 removed) since the marketplace Phase 10 part 1 commit.

| Area | New services | New admin screens |
|---|---|---|
| Brand | `Brand`, `BrandingService` | Settings → Branding |
| Settings | `SettingsService` (rewritten as a registry) | Settings: General, Contact & business, Social, SEO, Checkout & accounts, Orders & returns, Delivery, Marketplace, Email & SMS, Security, Maintenance; Export & import |
| Content | `SiteContentService`, `ContentRenderer` | Pages, Menus, Homepage |
| Payments | `PaymentOptions` (rewritten), `SecretStore` | Payment methods |
| Delivery | `StatusLabels` (+ `DeliveryService` switches and store-wide free delivery) | Delivery settings |
| Messages | `MessageTemplates` (+ `NotificationService::sendTemplate`, mail settings) | Messages |
| Access | `RoleService`, `MfaService`, `AuthEvents` (+ `StaffService`, `AuthService`, `Auth`) | Roles & permissions, Staff (extended), My account, Sign-in activity, staff two-step sign-in page |

**Removed:** the nine hardcoded information-page templates, now content records, and the old single-page settings form.

**Database migrations:**

| # | Adds |
|---|---|
| 018 | Brand settings; `seller_slug_aliases` (old Official Store address redirects) |
| 019 | Grouped settings |
| 020 | One account per phone number (`uq_users_phone`), for sign-in by phone |
| 021 | Uniform on/off settings |
| 022 | `content_pages`, `content_page_revisions`, `menus`, `menu_items`, `home_sections`, with the current site as seed |
| 023 | `payment_methods`, `secrets`, `message_templates`, `order_status_labels`; delivery and email settings |
| 024 | `roles.is_system`, `user_permissions`, `mfa_recovery_codes`, `auth_events`, account-security columns, append-only triggers on `audit_logs` and `auth_events`, two roles |

All are additive. No customer, order, product, payment or ledger record was changed or deleted. Each was applied to the dev database after a backup.

## 3. Hardcoded content converted

| Was hardcoded | Now managed in |
|---|---|
| Store name in about 180 strings (views, services, emails, SMS) | Settings → Branding (`brand_name()` everywhere) |
| Tagline, colours, logo, icons, favicon, social image | Settings → Branding |
| `WM-` order prefix | `order_number_prefix` setting (old numbers keep working) |
| Footer tagline, contact block, social links, copyright holder, footer note | Settings → Contact & business / Social / General |
| Home page title, default search description | Settings → SEO |
| Products per page | Settings → General |
| About, Contact, Help, Delivery & pickup, Pickup stations, How to pay, Returns, Terms, Privacy | Content → Pages (with drafts, scheduling, revisions) |
| Top bar messages and links, Help menu, footer columns and highlights, phone bottom bar, homepage side tiles, "Why shop with us" | Content → Menus |
| Homepage section order, headings and counts | Content → Homepage |
| Payment method names, descriptions, instructions, footer payment badges | Finance → Payment methods |
| M-Pesa numbers (were on the general settings page) | Finance → Payment methods |
| Email sender, reply-to, mail server | Settings → Email & SMS (password in the encrypted store) |
| Wording of all 28 customer and seller emails and SMS | Content → Messages |
| Delivery checkout notes, free-delivery amount, delivery/pickup availability | Fulfilment → Delivery settings |
| Order status names shown to customers | Fulfilment → Delivery settings |
| Roles' permissions | System → Roles & permissions |

Kept in code on purpose, as the brief allows:
- internal identifiers (the `Waimart\` namespace, the `waimart` database, the session cookie, `WAIMART_` variables);
- the order and payment state rules, validation, and security limits;
- the built-in default wording, used until something is edited.

## 4. Test results

- **Automated suite: 473 tests, 4,929 assertions, all passing.**
  - Unit, integration on a real MariaDB test database, and HTTP tests against a running server.
  - It includes the marketplace money simulation, concurrency races, the IDOR matrix and the new role matrix.
- **Brief section 11 checks,** each an automated test:

| Brief check | Test |
|---|---|
| Logo and name consistent; old branding gone | `BrandingTest`, `StorefrontPagesTest`, `tests/scripts/brand_scan.php` (no visible old name) |
| Change logo and tagline in admin, see them on the shop | `BrandingTest`, `BriefAcceptanceHttpTest` |
| Footer edits appear | `ContentHttpTest`, `SettingsHttpTest`, `BriefAcceptanceHttpTest` |
| A setting changes behaviour | `SettingsGroupsTest`, `SettingsHttpTest` |
| Disabled payment method unavailable at checkout | `PaymentsDeliveryMessagesTest`, `PaymentsDeliveryMessagesHttpTest` (crafted order refused) |
| Delivery charges change the server's totals | `BriefAcceptanceHttpTest` (posted fee and total ignored), `CheckoutPrepareTest` |
| Homepage edits appear | `ContentHttpTest` |
| Missing settings show no technical errors or invented details | `BriefAcceptanceHttpTest` (26 pages with every optional setting removed) |
| Each role reaches exactly its modules; direct URLs and posts refused | `AdminRoleMatrixHttpTest` (8 roles × every admin page and action) |
| Sellers can't see other sellers' data | `IdorMatrixHttpTest` |
| Support can't reach payment secrets | `AdminRoleMatrixHttpTest`, `PaymentsDeliveryMessagesHttpTest` |
| Product managers can't grant themselves admin | `RolesSecurityTest` (escalation attempts) |
| Suspended users lose active sessions | `BriefAcceptanceHttpTest`, `RolesSecurityTest` |
| Registration, login (email or phone), browsing, search, cart, checkout, stock validation | `PhoneSignInHttpTest`, `OrderFlowTest`, `CheckoutPrepareTest`, `ConcurrencyTest` |
| Payment success, failure, duplicates | `PaymentFlowTest`, `PaymentHttpTest` (duplicate codes, single decision) |
| Tracking, cancellations, returns, refunds | `OrderLifecycleHttpTest`, `ReturnsRefundsReviewsTest`, `RefundConcurrencyTest`, `MarketplaceAfterSalesTest` |
| Notifications | `PaymentsDeliveryMessagesTest` (built-in wording unchanged, edits sent, preferences) |
| Desktop and mobile | Browser sweep below |

- **Browser sweep** (Microsoft Edge, axe-core accessibility, overflow and console checks at phone 375 px, tablet 768 px and desktop 1366 px):
  - all **103 pages** with a fixed address (63 admin, 11 Seller Centre, 11 customer account, 18 public), signed in as the right kind of user each time;
  - 0 serious or critical accessibility issues, and no horizontal overflow or console errors after the fixes listed in REBRAND_NOTES.md.
- **Rollback drill after every stage:** the pre-upgrade backup migrated 010→024, then both rollbacks run: identical schema and identical data, apart from setting timestamps.
- **Ledger load test** (`tests/scripts/ledger_load.php`): 100,000 ledger rows across 20 sellers, every balance exact to the cent.
  - Seller balances, summary and statement: **9–20 ms**.
  - Finance liability and reconciliation: **0.4–0.5 s**.
  - Seller performance report (30 days): **1.2 s**.
- **Integrity check** (`bin/check-integrity.php`, 33 checks): all pass on the dev database except "no active super administrator". That is real: the dev database has no staff yet (see section 5).

## 5. Outstanding: owner actions before going live

1. **Create your super administrator:** `php bin/create-admin.php --email=you@…`. Then sign in, choose a password and turn on two-step sign-in. The dev database has no staff accounts.
2. **Payment numbers:** enter the real M-Pesa Till, Paybill and/or Send Money numbers in Finance → Payment methods. Until then only cash on delivery is offered.
3. **Email:** set the sender and mail server in Settings → Email & SMS, then use "Send me a test email". Until then, email goes to the mail log.
4. **HTTPS** on the live server, and `session.secure = true` in `config.local.php`.
5. **Scheduled tasks:**
   - the reservation expiry worker (every minute, required);
   - nightly backup;
   - daily `bin/check-integrity.php`.
6. **Review the wording** of Terms, Privacy and Returns (Content → Pages) with your legal adviser. They were carried over from the original site.
7. **Remove demo data** before launch: the demo products named "Waimart Basics…" and test orders in the dev database.
8. **Optional:**
   - require two-step sign-in for all staff (Settings → Security);
   - raise `innodb_buffer_pool_size` (see OPERATIONS.md);
   - an SMS provider (none is connected; SMS stays in the message log, and its credentials would go in the encrypted store).

**Known limits, documented:**
- payment methods are manual (no gateway, as decided), so there are no gateway callbacks to verify;
- authenticator setup is by key or tap-to-add link (no QR image, to avoid an external library);
- message templates are plain text.

## 6. Deployment steps (existing installation)

1. `php bin/backup.php`. Copy the backup folder off the machine.
2. Deploy the code (`git pull` of this branch, then merge to main once accepted). Run `composer install --no-dev` if dependencies changed (they did not).
3. `php bin/migrate.php`: applies 018–024 in order. Each is additive.
4. `php bin/check-integrity.php`: everything should be OK except "no active super administrator" if you have none yet.
5. Complete the owner actions in section 5.
6. Rollback, if ever needed: restore the backup, or run `database/rollback/rollback_rebrand.sql` (then, only if going back before the marketplace, `rollback_marketplace.sql`) together with the matching code version.

New installations follow [INSTALL.md](INSTALL.md). Day-to-day running is in [OPERATIONS.md](OPERATIONS.md), sellers' instructions in [SELLER_GUIDE.md](SELLER_GUIDE.md), backups in [BACKUP_RESTORE.md](BACKUP_RESTORE.md).

## 7. Acceptance criteria (brief, final section)

| Criterion | Status |
|---|---|
| Consistent Ecommerce identity | Met: settings-driven everywhere; scan shows no visible old name |
| Supplied logo used correctly | Met: cut from the supplied file, not redrawn; replaceable in admin |
| Editable content and settings come from the database | Met (section 3) |
| Admins manage the site without editing code | Met. Exception: server-level items (database password, app key, HTTPS) stay in `config.local.php`, as they should. |
| Payment and delivery settings work with server-side validation | Met: re-checked at order placement; crafted requests refused (tested) |
| Distinct, enforced role privileges | Met: role matrix test over every admin route |
| Unauthorised users can't reach restricted modules or data | Met: role matrix, IDOR matrix |
| Existing accounts, orders, products and payments intact | Met: additive migrations; old order numbers and links work; rollback drills exact |
| Works on desktop and mobile | Met: 103-page sweep at three sizes |
