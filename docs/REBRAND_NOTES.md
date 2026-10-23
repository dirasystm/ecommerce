# Ecommerce Rebrand and Database-Driven Management

**Started:** 18 October 2026 · **Branch:** `feature/rebrand` (from `feature/multi-vendor`) · **Status:** All 6 stages complete (plus sign-in with phone or email). Summary: docs/REBRAND_FINAL_REPORT.md

This is the plan and progress record for the owner's request "Rebrand the e-commerce platform to Ecommerce and implement full database-driven management". The marketplace work it builds on is recorded in `docs/MARKETPLACE_PLAN.md`.

---

## 1. Audit findings (before any change)

| Area | Found | Gap against the brief |
|---|---|---|
| Branding | About 200 user-visible "Waimart" mentions in services, views and controllers, including e-mail and SMS text. Logo files, favicon and colours hardcoded in CSS and layouts. | Everything. |
| Internal identifiers | PHP namespace `Waimart\…`, database `waimart` / `waimart_test`, session cookie `waimart_sid`, lock names, `WAIMART_ENV`, tab key `with_waimart`, order numbers `WM-…`. | Kept unchanged on purpose: renaming them changes nothing customers see and risks breakage (brief §2, "do not perform a careless global replacement"). |
| Settings | A key/value `settings` table (28 keys) and one long settings page. | No types, groups, branding, SEO, contact or social settings; no export/import. |
| Content | Banners, flash sales and coupons are already in the database. | The 9 information and legal pages are hardcoded templates (328 lines). Header, footer and menus are hardcoded. |
| Payments | Manual M-Pesa (Till, Paybill, Send Money) plus cash on delivery. A method is "on" when its number is filled in. No gateway, secrets or callbacks exist. | No enable/disable switch, display names, instructions or ordering. |
| Notifications | E-mail and SMS outbox (no SMS gateway yet). | About 18 message types written in code. |
| Access control | 6 fixed roles, 32 permissions, a server-side check on every route, and staff screens (create, roles, suspend, sign out everywhere). | No custom roles or permission matrix, no MFA, no forced password change. Failed logins are not logged, and the audit log is not protected at database level. |
| Staff accounts | **None** in the dev database. | A secure first super-admin setup is needed (Stage 5). |
| Logo | 1254×1254 PNG on an off-white background (#FEFEFE). Sampled green #01623C / #004C2F and orange #FC7502 / #FE5D00, matching the brief's #005B3D / #FF6A00. | — |

## 2. Decisions (owner, 18 October 2026)

1. Marketplace Phase 10: its money-safety fixes were committed first ("part 1"). The rest of its verification is folded into Stage 6 here.
2. **New orders are numbered `BM-…`.** Existing `WM-…` numbers, links and receipts keep working. The prefix is a setting (Branding).
3. **Payments stay manual M-Pesa plus cash on delivery.** No gateway integration (none exists, and the marketplace decision was manual M-Pesa only). Stage 4 builds payment-method management and an encrypted, masked secrets store ready for a future gateway.
4. **Staff MFA (authenticator app) is optional for everyone** (Stage 5).

**Out of scope unless the owner asks:**
- card or bank gateways, webhooks and sandbox/live switching (needs a provider and credentials);
- WhatsApp messages;
- multiple languages;
- tax rules beyond "prices include VAT" (no invented legal requirements).

## 3. Stages (pause after each)

| # | Stage | Main deliverables |
|---|---|---|
| 1 | Brand foundation | Logo assets from the supplied file; brand settings; settings-driven palette; every visible mention through the store name; favicon, icons, manifest, Open Graph; Official Store renamed; BM- order numbers |
| 2 | Settings system | Typed, validated, grouped settings pages (General, Contact & business, Social, SEO, Store, Checkout & accounts, Maintenance & announcement); audit; export/import of non-secret settings |
| 3 | Content management | Information and legal pages in the database (publish, unpublish, schedule); header, footer and mobile menus; homepage sections and order; announcement bar; footer entirely from settings |
| 4 | Payments, delivery, messages | Payment methods table driving checkout (server-validated); encrypted secrets store; free-delivery threshold and delivery instructions; editable message templates with placeholders and a preview |
| 5 | Roles and admin accounts | Custom roles and a permission matrix, per-user grants, no self-escalation; new roles (Content & Marketing, Delivery & Logistics); optional TOTP MFA; forced password change; failed-login logging; append-only audit log; secure first super-admin |
| 6 | Verification and docs | Full regression, IDOR and role matrix, payment and delivery checks from the brief, browser sweep, final scan for old branding, docs (with the remaining marketplace Phase 10/11 items) |

---

## Stage 1 results (18 October 2026): brand foundation

**Brand assets** (`public/assets/brand/`). Made from the supplied logo (kept as `logo-original.png`) with GD:

| File | Size | Use |
|---|---|---|
| `logo-wordmark.png` | 599×112 | headers, footer, Seller Centre, admin |
| `logo-icon.png` | 241×160 | the cart-and-B mark beside the header wordmark |
| `logo-full.png` | 640×496 | stacked logo with tagline (error pages) |
| `favicon-32.png`, `apple-touch-icon.png`, `icon-192.png`, `icon-512.png` | square | browser tab, iOS home screen, web app |
| `og-image.png` | 1200×630 | social sharing preview |

- Crops follow the artwork's measured edges, not guesses: the white gap at rows 656–671 separates the cart from the wordmark.
- The logos' near-white background is transparent, so no box shows on white cards.

**Settings-driven identity** (`Brand` service, migration 018). New settings:
- `store_tagline`;
- `brand_primary`, `brand_secondary`;
- eight brand-image paths;
- `order_number_prefix`.

`store_name` became "Ecommerce". Values are changed only where they still held the shipped Waimart defaults.

- **Names:** every user-facing mention goes through `brand_name()`: pages, e-mails, SMS, flash messages, seller and admin screens, CSV export file names. A tokenizer-based rewrite placed each mention by context: HTML text, PHP strings, and `{store}` placeholders inside class constants (filled by `brand_text()`). Code comments and identifiers were left alone.
- **Colours:** admins pick a primary and an accent colour. `Brand::palette()` derives the shades, Bootstrap's RGB values, and a button colour that always keeps white text at **4.5:1 or better**. The logo orange #FF6A00 is only 2.87:1 with white text, so buttons use #C85200 automatically. A primary colour too light for white text is refused with an explanation. The palette is printed after `app.css` on every layout; the CSS defaults match it.
- **Head tags on every layout** (`partials/brand-head.php`):
  - theme colour, favicon and app icons;
  - the web app manifest `/manifest.webmanifest`, generated from settings;
  - Open Graph / Twitter preview on storefront pages;
  - page titles "Page | Ecommerce" and "Ecommerce — Everything you need, one store."
- **E-mail sender name** = the store name, unless `mail.from_name` is set in `config.local.php`.
- **Fallbacks are neutral:** a missing name falls back to `config('app.name')` ("Ecommerce"); a missing or invalid image or colour falls back to the shipped files and palette. Image paths are checked against path traversal.

**Admin → Settings → Branding** (`settings.manage`, super admins today):
- name, tagline and order-number prefix;
- colour pickers with live "in use now" swatches;
- four image uploads, each with a preview and "use the original image again".

Uploads are checked by content (type, size, dimensions; ImageService) and re-encoded as PNG, never stored as uploaded:
- an icon upload generates the favicon and every app-icon size;
- if any upload in a save fails, nothing is kept (no orphan files);
- replaced uploads are deleted, but shipped files never are;
- every change is written to the audit log as `branding.updated` with before and after values.

**Official Store:** "Waimart Official Store" is now "Ecommerce Official Store" at `/store/official-store`. The old address `/store/waimart-official` redirects permanently (301) via the new `seller_slug_aliases` table, which keeps any store's old links working after an address change.

**Impersonation guard:** seller store names and addresses may not contain the platform's current name, however it is spelled ("Ecommerce Deals", "E Commerce Store", "E-COMMERCE Outlet", `ecommerce-deals`), nor the former name. The list follows the name setting.

**Order numbers:** new orders are `BM-XXXXXXXX`. Every route and lookup accepts any two-letter prefix, so `WM-` orders, tracking links and receipts keep working, and the prefix can be changed later without breaking anything.

**Rollback:** `database/rollback/rollback_rebrand.sql`. Drilled on a copy: the pre-upgrade backup was migrated through 010–018, then this rollback and the marketplace rollback were run. The schema was identical and all data identical except the `updated_at` timestamp of the one restored setting (key/value comparison: all 17 settings identical).

**Hardcoded content converted in this stage:**

| Was hardcoded | Now |
|---|---|
| Store name in about 180 user-facing strings (views, services, controllers, e-mails, SMS) | `store_name` setting via `brand_name()` |
| Tagline in page titles and `config('app.tagline')` | `store_tagline` setting |
| Brand colours in `app.css`, the minimal layout, admin login and the theme-colour meta | `brand_primary` / `brand_secondary` plus the derived palette |
| Logo, icon, favicon and apple-touch paths in 6 templates | brand-image settings (`Brand::asset`) |
| `WM-` order prefix (generation, 11 routes, 3 lookups, 3 placeholders) | `order_number_prefix` setting plus a shared pattern |
| E-mail sender name `Waimart` in config | the store name (overridable in `config.local.php`) |
| `waimart-*.csv` export names | `Brand::slug()` |
| Hardcoded `'waimart'` impersonation check | `Brand::protectedNames()` |

Still hardcoded, and scheduled:
- information and legal pages, header and footer links, homepage copy (Stage 3);
- payment method labels and instructions (Stage 4);
- message wording (Stage 4).

**Tests:**
- **`BrandTest` (unit, 4):** hex validation (including injection attempts), WCAG contrast values, readable button colours for any accent, shades and mixes.
- **`BrandingTest` (6):**
  - the migration's values and files;
  - validation of every field, nothing saved on error, audit before and after, a palette change, new orders using a new prefix;
  - uploads making every icon size at exact dimensions, replacing and deleting old files, reset to the supplied images;
  - a non-image or too-small upload refused with no orphan files;
  - neutral fallbacks and path-traversal refusal;
  - old `WM-` numbers still found while malformed numbers are not.
- **`BrandingHttpTest` (3):**
  - all five non-super-admin roles get 403 on view and save;
  - a change goes live in titles, palette, theme colour, logo alt text, Open Graph, manifest and the order-number hint;
  - a too-light colour is refused;
  - a crawl of 24 public pages, 7 admin pages and 7 Seller Centre pages finds no "Waimart" and the store's name on every public page.
- **Seller name rules:** extended to the new name.
- **Older tests:**
  - updated for BM- numbers and the renamed Official Store;
  - one test (`PaymentFlowTest`) had been silently resetting the store name to "Waimart" for every test after it; it now keeps the current name.
- **Full suite: 402 tests, 3,274 assertions, all passing.**
- **Browser:** 0 serious/critical accessibility issues on the storefront (9 pages), the Branding page and the admin dashboard. Responsive checks passed at phone, tablet and desktop; screenshots reviewed (header, footer, error page, Branding page, Seller Centre).

**Found and fixed along the way:**
- The storefront `<title>` read `config('app.name')` before the setting.
- The error-page button put white text on the bright orange (contrast failure); it now uses the accessible button colour.

**For the owner:**
- The dev database's **demo products** still contain the old name (e.g. "Waimart Basics Long-Grain Rice 5kg"). These are product records, not code, and should be replaced or deleted with the other demo data before go-live; `bin/seed-demo.php` now uses the store name for new demo data.
- Set the real sending address (`mail.from_email`) in `config.local.php` before go-live.

---

## Stage 2 results (18 October 2026): settings system, and sign-in with phone or email

### Settings

**Grouped, typed settings** (`SettingsService`, migration 019). The settings are a registry: each has a label, a validation rule (its type), help text, a group and a widget. **Admin → Settings** is now one page per group, with a section menu (Branding sits in it too):

| Group | Settings |
|---|---|
| General | store description, products per page (12–96) |
| Contact & business | customer-care phone and email, WhatsApp, business and postal address, care hours, registered business name |
| Social media | Facebook, Instagram, X, TikTok, YouTube, LinkedIn (https:// only) |
| Search & sharing (SEO) | home page title, default description, "hide the shop from search engines" (for a test copy) |
| Checkout & accounts | allow customers to create accounts, require email for guest checkout |
| Orders & returns | stock holds, COD auto-confirm, pickup hold, return window (moved from the old page) |
| M-Pesa | till, paybill and Send Money details (moved; Stage 4 replaces this with payment-method management) |
| Marketplace | product moderation, payout settings, seller onboarding documents (moved) |
| Maintenance & announcement | close the shop for maintenance with a message; an announcement bar with an optional link |

- **Saving one page touches only that page's settings**, so saving one page can never blank another.
- Every change is audited (`settings.updated`). Changes to payment destinations also get their own `settings.payment_destination_changed` entry.
- Settings are cached per request, and every write clears the cache, so changes apply on the next page load.
- `/admin/settings` redirects to General. The old "save everything" form still works, for scripts.

**What each new setting does on the shop:**
- **Footer and Contact page:** contact details, a WhatsApp link (`wa.me`), social icons, address and hours. The copyright line shows the registered business name (otherwise the store name) and the current year. The footer tagline now comes from Branding; it was still hardcoded.
- **SEO:** the home page title override and the default meta description (falling back to the store description, then a neutral sentence). With "hide from search engines" ticked, every shop page carries `noindex, nofollow`.
- **Products per page:** applies to category, search, store and flash-sale listings, always kept within 12–96 even if a bad value is stored.
- **Closed registration** is enforced on the server (`AccountService::register`); a crafted request is refused too. The Register page explains it and points to guest checkout, and the "Create account" links are hidden.
- **Maintenance mode:** customers get a branded "We'll be back soon" page with the admin's message, HTTP **503** and `Retry-After: 3600`, so search engines treat it as temporary. That covers every shop page and action, including adding to the cart.
  - Staff see the shop as normal, with a reminder bar.
  - The admin, Seller Centre, sign-in pages, health check and manifest stay open.
- **Announcement bar:** dark text on a light accent tint, optionally linked. Links may only be a page on this site (`/…`) or an `https://` address; `javascript:`, `data:`, `//host` and quote tricks are refused.

**Export & import** (Settings → Export & import):
- Export downloads the settings as JSON (audited).
- Import validates every value exactly as the pages do, and **applies nothing if any value is invalid**.
- **M-Pesa payment numbers are never exported or imported**, and branding is not importable through settings. Unknown keys are reported, not applied.
- Exporting then importing an unchanged shop changes nothing. To make that exact, migration 021 stores the product-moderation switch as `1`/`0` like every other on/off setting; the code accepts both forms, so behaviour is unchanged.

**Hardcoded content converted:** footer tagline; footer contact block (now phone, email, WhatsApp and hours); copyright holder; home page title; default meta description (it mentioned specific product categories and "across Kenya"); products per page (24 in code).

### Sign-in with phone or email

- **All sign-in pages** (customers and sellers at `/login`, staff at `/admin/login`) have one field, **"Email or phone number"**. It uses `autocomplete="username"`, so password managers keep working.
- `AuthService::identify()`: anything containing `@` is an email. Anything else is read as a Kenyan phone number in any common form (`0712 345 678`, `0712345678`, `+254 712 345 678`, `254712345678`, `712345678`), normalised to `2547…`. Anything else is not looked up at all.
- **Security is unchanged or stronger:**
  - one generic error, "Incorrect email, phone number or password.", whether the account exists or not, with the same timing (a dummy hash is checked);
  - the 5-attempts-per-15-minutes lock is keyed on the *normalised* identifier, so trying every spelling of a number counts against one limit;
  - the per-network limit (30 per 15 minutes) covers email and phone attempts together;
  - customers cannot use the staff sign-in, and suspended accounts cannot sign in.
- **One account per phone number** (migration 020, `uq_users_phone`), checked with a clear message at registration and when staff add a person.
  - Accounts without a phone are unaffected.
  - If existing data had two accounts sharing a number, the migration would be refused and nothing would change; the file says how to find and fix them. The dev data had none.
- **Changing your phone number now needs your current password**, like changing your email, because both sign you in.
- Older forms that post `email` still work.
- Password reset stays by email: no SMS gateway is connected yet. SMS reset can come with one.

### Database

- Migrations 019 (new settings with today's behaviour as defaults), 020 (unique phone) and 021 (flag format) were applied to the dev database after a backup (`storage/backups/waimart-20261018-210318.sql`).
- `rollback_rebrand.sql` covers 018–021.
- **Re-drilled on a copy of the pre-upgrade backup** (010–021 forward, both rollbacks back): identical schema, and identical data apart from one restored setting's `updated_at` (all 17 settings' keys and values identical). The drill caught the users table's indexes coming back in a different order; the rollback now rebuilds them in the original order.

### Found and fixed

- Product moderation was stored as `required`/`none` while the settings form saves `1`/`0`. It was harmless (both are understood) but made exports inexact; normalised.
- Two older tests had silently relied on shared state: one wrote the old moderation format, and several test helpers registered every account with the same phone number. Both now set their own values.
- The selected item in admin section menus used Bootstrap's default blue; it now uses the brand colour.

### Tests

- **`SettingsGroupsTest` (6):**
  - every setting has a group and a stored default, and the defaults keep today's behaviour;
  - saving one page leaves other pages' settings untouched, including an unrelated checkbox, and is audited;
  - validation of social links (https only), announcement links (5 attack forms refused), page size, length limits, WhatsApp;
  - export excludes payment numbers; a round trip changes nothing; import is all-or-nothing, ignores payment numbers, branding and unknown keys, is audited, and refuses junk;
  - page size drives listings and is clamped;
  - closed registration is refused by the service.
- **`PhoneSignInTest` (6):**
  - identifier recognition in every form, including an injection-shaped value that is never looked up;
  - sign-in with email (any case) or phone (four forms);
  - a shared lock across spellings, and the per-network limit across email and phone;
  - staff sign-in by phone; customers refused at the staff sign-in; suspended accounts refused;
  - phone uniqueness (service and database), many accounts without a phone allowed, and password-protected phone changes.
- **`SettingsHttpTest` (5):**
  - three non-super-admin roles get 403 on all 11 settings pages and on saving, and cannot close the shop;
  - super admins reach every page, and unknown groups give 404;
  - contact, social and SEO settings show on the shop; bad links are refused with messages, nothing changes, and noindex works;
  - maintenance: customers get 503 with the message and `Retry-After` on five pages and on add-to-cart; sign-in, admin sign-in, health and manifest stay open; sellers keep working; staff see the shop with the reminder; the announcement bar and its link show;
  - closed registration: the page notice, the hidden link, and a crafted POST refused;
  - export download and import upload.
- **`PhoneSignInHttpTest` (3):**
  - the customer page signs in by phone (two forms) and email;
  - the old `email` field still works;
  - a generic error for a wrong password and for an unknown number, with the typed identifier kept;
  - staff sign-in by phone, customers refused there;
  - registration refuses a taken number.
- **Full suite: 422 tests, 3,585 assertions, all passing.**
- **Browser:** 0 serious/critical accessibility issues on all 11 settings pages, both sign-in pages, the register page, the maintenance page and the shop with the announcement bar. Responsive checks passed (the maintenance page's 503 status shows as an expected console message). Screenshots reviewed.

## Stage 3 results (18 October 2026): content management (pages, menus, homepage)

### Pages (Admin → Content & marketing → Pages)

- The nine information pages (About, Contact, Help, Delivery & pickup, Pickup stations, How to pay, Returns policy, Terms, Privacy) are now stored in the database (`content_pages`), seeded with their original wording. The old PHP templates are gone.
- Each page has a title, a meta description, a body, a draft/published status, optional publish and unpublish times, a sort order and "show in the Help menu".
- **New pages** can be added; they live at `/pages/{slug}`. Built-in pages keep their own addresses (`/about`, `/help`…), cannot be deleted or renamed, and `/pages/about` redirects (301) to `/about`.
- **Drafts:** visitors get 404; staff who manage content see a preview with a "not published" notice.
- **Revisions:** every save keeps the previous version (last 30 per page), and any revision can be restored.
- **Safe formatting, no HTML:** `## heading`, `### smaller`, `- list`, `1. list`, `**bold**`, `*italic*`, `[text](link)`, `> note`, and `?? question` for FAQ accordions.
  - Everything typed is escaped first, so nothing typed can become markup. Links may only be a page on this site, `https://`, `mailto:` or `tel:`; any other link keeps only its text.
  - `{store}`, `{return_days}`, `{phone}` and similar fill in live values from settings.
  - `[[contact_details]]`, `[[pickup_stations]]` and `[[payment_instructions]]` insert live blocks. Payment instructions show only the M-Pesa methods that have a number configured, plus cash on delivery. (The dev database has no M-Pesa numbers yet, so How to pay says the details are shown at checkout.)

### Menus (Admin → Content & marketing → Menus)

- Top bar messages and links (with dropdowns), the Help menu, footer highlights, the three footer columns (the menu title is the column heading), the homepage side tiles, the "Why shop with us" strip and the phone bottom bar (at most 5 items) all come from menus.
- Items have a label, a link, an icon, a description, an order, visibility and "open in a new tab". The same link rules as pages apply; `javascript:` and similar are refused with a message.
- The footer note (prices in KSh…) is a setting under General, so only settings managers can change it.

### Homepage (Admin → Content & marketing → Homepage)

- The homepage is an ordered list of sections: slider and categories, popular categories, flash sale, top deals, shop by category, featured products, category rows and "why shop with us".
- Sections can be moved, renamed, given an item count, hidden, or scheduled (show from / hide after).
- **Category rows** (one chosen category) and **text blocks** (same formatting as pages) can be added as many times as needed.
- Slides are still edited under Banners, and flash sales under Flash sales, as before.

### Permissions and audit

- All of it needs `content.manage` (super admins and managers today; Stage 5 adds a Content & Marketing role). Other roles get 403 on every page and action.
- Every change is audited (`content.page_*`, `content.menu_*`, `content.section_*`).

### Database

- Migration 022 (`content_pages`, `content_page_revisions`, `menus`, `menu_items`, `home_sections`, the `footer_note` setting, and the seeded content) was applied to the dev database after a backup (`storage/backups/waimart-20261018-222159.sql`).
- `rollback_rebrand.sql` covers 018–022. **Re-drilled** on a copy of the pre-upgrade backup: identical schema, and identical settings keys and values.

### Found and fixed

- While building, a new service file was briefly written over the existing `ContentService.php` (banners and flash sales). It was restored from git unchanged, and the new code lives in `SiteContentService.php`.
- The test database's cleanup emptied the new content tables between tests; the seeded content is now captured once and restored before every test, so no test can leak edits.
- An older payment test saved the whole settings form and blanked the new footer note; it now snapshots and restores settings.

### Tests

- **`ContentRendererTest` (4):** formatting; 9 attack forms (script, img onerror, `javascript:`, `data:`, `//host`, quote breakouts, tags in headings, FAQ and bold) never become markup; FAQ accordions; live values and blocks, including only configured payment methods.
- **`SiteContentServiceTest` (8):** pages, slugs and system-page protection, scheduling, revisions and restore, menus and link validation, the bottom-bar limit, homepage sections and their schedule, audit.
- **`ContentHttpTest` (4):** three non-content roles get 403 on 7 pages and on saving; editing a page goes live; draft preview, 404 for visitors, publishing; menus and footer follow the admin, bad links refused, managers cannot change the footer note; homepage sections hide and text blocks appear.
- **Full suite: 439 tests, 3,830 assertions, all passing.**
- **Browser:** 0 serious/critical accessibility issues on the 10 public content pages and 9 content admin pages; responsive checks passed; screenshots reviewed.

## Stage 4 results (19 October 2026): payments, delivery and messages

### Payment methods (Admin → Finance → Payment methods)

- The four methods the order system handles (M-Pesa Till, Paybill and Send Money, which are manual, and cash on delivery) are now records in `payment_methods`.
- For each method, staff set:
  - on/off;
  - the name (used at checkout, on orders, in reports and admin lists);
  - the footer badge;
  - the line shown under it at checkout;
  - extra customer instructions (the safe content format, with `{order_number}` and `{amount}`);
  - display order;
  - optional minimum and maximum order totals.
- **The M-Pesa numbers moved here** from Settings, next to the method they belong to. They are still settings, so changing a number still writes its own `settings.payment_destination_changed` audit entry. `/admin/settings/payments` now redirects here.
- **A method is offered only when** it is switched on, its M-Pesa number is set, and the order total is within its limits. Cash on delivery also needs the zone or station and the marketplace rule to allow it.
  - Checkout re-checks all of this on the server when the order is placed, against the real total including delivery. A disabled or out-of-limit method posted anyway is refused.
- **Safeguards:**
  - an M-Pesa method can't be switched on without its number;
  - the last method that is on can't be switched off;
  - limits are validated;
  - every change is audited (`payment_method.updated`, before and after).
- **What uses it:**
  - checkout lists the methods in their set order; a method outside its limit shows why, disabled;
  - the customer's order page shows the method's instructions under the payment details (for cash on delivery, while payment is due);
  - the How to pay page (`[[payment_instructions]]`) lists only methods currently offered, with their names, limits and instructions;
  - **the footer payment badges** now come from the methods offered (the M-PESA badge only when an M-Pesa method is offered). They were hardcoded before.
- **Health at a glance:** the list shows, for each method, whether it is offered (or why not), payments waiting, verified and rejected in the last 30 days, and when a payment was last verified. The "max code submissions per order" rule is on the same page.
- **No gateway:** these are manual methods, so there are no gateway keys, callbacks or sandbox/live modes; the page says so. When a gateway is added, its keys go in the secrets store below. Its callbacks must be verified server-side, as the brief requires.

### Encrypted secrets store

- `secrets` table: values encrypted with the app key (AES-256-GCM, the same scheme as payout details).
- Only named slots can be stored; today there is one, the **SMTP password**.
- **Write-only:** the admin shows only whether a value is set, when and by whom, never the value.
- **Permissions:** changing a secret needs `settings.manage` and the new `secrets.manage` (super admins only).
- **Audit:** changes are audited by name only (`secret.updated` / `secret.removed`); tests confirm the value never reaches the audit log.
- If the app key changes, a stored secret shows as "cannot be read: enter it again" instead of failing silently.

### Email & SMS (Admin → Settings → Email & SMS)

- **Sender name, sender email and reply-to address** (reply-to defaults to the customer care email).
- **How email is sent:**
  - the server setting (`config.local.php`, as today);
  - a mail server (SMTP host, port, encryption, username, plus the encrypted password);
  - the mail log (testing).
  - Blank settings fall back to `config.local.php`, so nothing changes until the owner chooses. **This means the owner can now set up real email from the admin, without editing server files.**
- **"Send me a test email"** reports honestly whether the email was sent, only logged, or failed (with the first line of the server's error, credentials hidden). It is audited.
- **SMS:** no provider is connected. The page says SMS messages are kept in the message log and not delivered. A provider's credentials would go in the secrets store.
- The group is not exported or imported, because its values belong to one server.

### Message templates (Admin → Content & marketing → Messages)

- **Coverage:** every customer and seller message now comes from an editable template (28):
  - order placed, and each order status (confirmed, dispatched, out for delivery, delivered, ready for collection, collected, cancelled, expired, under review);
  - payment code received, verified and not verified;
  - returns (requested, approved, rejected, completed) and refund sent;
  - seller cases (opened, reply, resolved);
  - the three per-seller part updates;
  - account messages (new **welcome email**, password reset, add-an-order code);
  - the layout of every message to sellers and seller applicants.
- **Built-in wording is unchanged.** Tests compare the rendered text with the old wording exactly. A template has a database row only once edited, and "Restore the original wording" removes it.
- **Placeholders and validation:**
  - each template lists the placeholders it may use, with descriptions; unknown placeholders are refused;
  - **required placeholders** (reset link, collection code, add-an-order code, refund amount…) can't be removed;
  - subjects and SMS must be one line (no header injection); the SMS limit is 480 characters, with a counter in the preview;
  - values are filled in a single pass, so a customer named `{collection_code}` cannot pull a code into a message.
- **Channels:**
  - email and SMS can be switched off per template;
  - **messages with codes, links or money are always sent** and can't be switched off;
  - customers' own email/SMS preferences are respected as before.
- **Preview** with a sample order beside the editor.
- Edits and restores are audited (`messages.template_updated` / `messages.template_restored`, with before and after wording).
- The new permission `messages.manage` is given to super admins and managers.
- Staff-only alerts (new application, payout request, new case) stay in code: they are internal, not customer communication.
- **Two behaviour notes:**
  - "Part of your order dispatched" (multi-seller orders) now follows the customer's SMS/email preferences, like the whole-order "Dispatched" message. Before, it ignored them.
  - New accounts get a welcome email (it can be switched off).

### Delivery settings (Admin → Fulfilment → Delivery settings)

- **Offer home delivery / offer pickup stations** switches: at least one must stay on. A switched-off method disappears from checkout, the live quote ignores it, and a crafted order is refused.
- **Store-wide free delivery from an amount**, for zones without their own free-delivery amount; a zone's own amount still wins. This is calculated on the server in `DeliveryService::quoteDelivery`, the single place every quote comes from.
- **Checkout notes** under home delivery and under pickup.
- **Order status names:** every customer-visible status can be renamed, for example "Awaiting payment" to "Waiting for your M-Pesa payment". This applies on order pages, tracking, admin and message subjects. Only the wording changes; the statuses and rules stay in code. A blank name restores the original, and renames are audited.
- Fees, delivery times, cash-on-delivery rules, regions and stations stay on the Delivery zones and Pickup points pages (Phase 8), now linked from here.

### Database

- Migration 023 was applied to the dev database after a backup (`storage/backups/waimart-20261019-102408.sql`). It adds:
  - the tables `payment_methods` (seeded with today's names and descriptions, all on), `secrets`, `message_templates` and `order_status_labels`;
  - settings for delivery and email;
  - three permissions.
- `rollback_rebrand.sql` covers 018–023. **Re-drilled:** on a copy of the pre-upgrade backup (010–023 forward, both rollbacks back): identical schema, and identical settings keys and values.

### Found and fixed while building

- A maximum order total of `0` was treated as blank. It is now refused with "leave blank for no limit".
- The test database's seed restore assumed every seeded table has an `id`; `payment_methods` is keyed by its code.

### Tests

- **`PaymentsDeliveryMessagesTest` (12):**
  - methods decide checkout: off, renamed, limits against the real total, footer badges, audit;
  - method validation, including the last method staying on;
  - safe, filled-in instructions with no nested blocks;
  - secrets: encrypted, write-only, never in the audit log, unreadable after a key change, used by the mail transport;
  - email settings validation and export exclusion;
  - built-in wording identical to before;
  - edited templates sent, a channel switched off;
  - template validation, essential channels locked, single-pass filling;
  - preferences vs always-sent messages;
  - welcome email;
  - delivery switches and store-wide free delivery (zone wins);
  - status names renamed and restored.
- **`PaymentsDeliveryMessagesHttpTest` (4):**
  - the role matrix for the three modules and the credentials endpoint (six roles);
  - disabling and renaming methods changes checkout, the badges and How to pay, and a crafted order with a disabled method is refused;
  - delivery switches, notes and status names through the admin;
  - message editing with a refused placeholder and the preview, the SMTP password never echoed, the test email result, and a real order using the edited wording with the private link redacted in the stored copy.
- Three older tests were updated for the move of M-Pesa numbers to Payment methods and for the new required email settings in the full settings form.
- **Full suite: 455 tests, 4,112 assertions, all passing.**
- **Browser:** 0 serious/critical accessibility issues on the 8 new admin pages, checkout, How to pay and the home page; responsive checks passed on phone, tablet and desktop; screenshots reviewed.

## Stage 5 results (19 October 2026): roles and admin accounts

### Roles and the permission matrix (Admin → System → Roles & permissions; `roles.manage`)

- **Every role's permissions can now be changed**, and **custom roles can be created**. The page shows the full matrix (permissions grouped by area, sensitive ones marked) with a check for each role.
- **Two new built-in roles from the brief:**
  - **Content & Marketing:** pages, menus, homepage, banners, flash sales, coupons and message wording.
  - **Delivery & Logistics:** orders (view), dispatch and delivery, pickup handover and pickup station details.
  - The brief's other roles map onto the existing ones: Store Manager = Manager; Product & Inventory = Inventory Clerk; Order & Fulfilment = Fulfilment Staff; Finance & Payments = Finance; Customer Support = Support; Seller accounts = Seller Centre (separate since the marketplace phases).
- The six existing roles keep exactly their permissions. Built-in roles can't be deleted; a custom role can be deleted once nobody has it.
- **Deny by default:** a person can do only what a role (or a personal permission) allows. Every admin page and action declares its permission and the server checks it. A new route test fails if any admin route is added without one.

### Extra permissions for one person (on their staff page)

- A permission can be given to one person on top of their roles. The matrix shows which ones come from roles (greyed out).
- The staff list flags people with extra permissions.

### No self-escalation (enforced on the server, tested)

- Nobody can change their own roles or permissions.
- **You can only give what you have:**
  - a permission you hold;
  - a role whose permissions you all hold;
  - and you can only edit roles you don't hold yourself.
  - Removing someone's extra permissions only touches the ones you hold; the rest stay for an administrator who holds them.
- The Super Administrator role always has every permission and can't be edited. Only a super admin can grant or remove super admin, or act on another super admin's account. The last active super admin can't be removed or suspended (as before).
- Only super admins hold `roles.manage`, `users.manage`, `settings.manage` and `secrets.manage` by default.

### Two-step sign-in (TOTP)

- **Optional for every account,** as decided. **Settings → Security → "Require two-step sign-in for all staff"** (off) can make it mandatory later; staff without it are then sent to set it up before anything else.
- **Setup** (Admin → My account):
  - enter the key in any authenticator app (Google or Microsoft Authenticator, Authy…), or tap the link on the phone;
  - prove it works with a code and your password;
  - get **10 single-use recovery codes**, shown once and stored hashed.
  - The secret is stored encrypted with the app key, only after it is confirmed.
- **Sign-in:** the password is checked first, then the 6-digit code (or a recovery code) on a second page.
  - The password alone never signs in, **on either sign-in page**: the customer sign-in can't be used to skip the second step.
  - The "password checked" step expires after 5 minutes.
  - Codes allow one 30-second step of clock drift and are accepted only once (no replay).
  - 5 wrong codes per 15 minutes per account.
- Turning it off needs the password (and is refused when it is required). New recovery codes also need the password. An administrator can **reset** it for someone who lost their phone; that also signs them out everywhere.
- The code generator matches the RFC 6238 test vectors.

### Account management (Admin → Staff → a person; `users.manage`)

- **Shown:**
  - last sign-in;
  - two-step status and recovery codes left;
  - whether a password change is due;
  - **recent sign-ins** and **recent actions** (from the audit log).
- **Actions:**
  - **require a password change** (they must choose a new one before using the admin);
  - **email a password reset link** (nobody sees or sets passwords for others);
  - **sign out everywhere**;
  - **reset two-step sign-in**.
  - All are audited and refused on super admins unless done by a super admin.
- **My account** (every staff member, linked from the header): change password (a new one must differ from the old), two-step sign-in, "sign out my other sessions", recent sign-in activity. A password change signs out other sessions, as before.
- Suspended accounts and accounts signed out everywhere lose their active sessions on the next click (checked on every request).

### Sign-in activity (Admin → System → Sign-in activity; `audit.view`)

- Every successful and failed sign-in, block after too many attempts, two-step challenge, success and failure, recovery-code use, sign-out, password change or reset, two-step on/off/reset and "signed out everywhere". It covers every account; the page filters to staff by default.
- **Recorded:** the account (if known), a masked identifier (`ja•••@example.com`, `0712•••678`), the IP address and the browser. Passwords and codes are never recorded (tested).
- Failed attempts with unknown emails at the staff sign-in are recorded too, so probing is visible. The page shows failed attempts in the last 24 hours.

### Append-only logs

- **`audit_logs` and the new `auth_events` refuse UPDATE and DELETE at the database level** (triggers), whatever tool issues them, like the seller ledger since 016. Tests try both on both tables.
- Viewing the audit log needs `audit.view`, and exporting it is itself audited (as before).

### First super admin

- There are still **no default accounts or passwords anywhere**.
- The only way to create the first super admin is `php bin/create-admin.php --email=…`. It now marks the printed password as **one-time**: the account must choose its own at first sign-in, and promoting an existing account signs out its sessions. It suggests turning on two-step sign-in.

### Database

- Migration 024 was applied to the dev database after a backup (`storage/backups/waimart-20261019-122409.sql`). It adds:
  - `roles.is_system`;
  - `user_permissions`;
  - `users.must_change_password`, `sessions_revoked_at` and the `mfa_*` columns;
  - `mfa_recovery_codes`;
  - `auth_events`;
  - the four append-only triggers;
  - the two roles, the `roles.manage` permission and the `staff_mfa_required` setting.
- `rollback_rebrand.sql` covers 018–024. **Re-drilled:** on a copy of the pre-upgrade backup (010–024 forward, both rollbacks back): identical schema (8 triggers after migrating, 0 after rolling back), and identical settings keys and values.

### Found and fixed while testing

- The permissions matrix widened the whole page on phones: each cell's screen-reader label ("Yes"/"No") is absolutely positioned and escaped the sideways scroller. The scroller is now their containing box, and it is keyboard-focusable with a label (an accessibility check flagged it).
- The new sign-in step was first at `/admin/login/verify`. The route-safety test flags GET addresses that look like actions, so it is now `/admin/login/two-step`. The route test now names the two public sign-in pages and the self-service account pages explicitly; every other admin route must still declare a permission.

### Tests

- **`RolesSecurityTest` (8):**
  - the shipped roles and the matrix;
  - escalation attempts: adding unheld permissions, editing your own role, the super admin role, deleting built-in or in-use roles, giving roles beyond your permissions;
  - personal permissions, including keeping what only others can change;
  - TOTP against the RFC 6238 vectors, drift and replay;
  - the full two-step sign-in: password alone doesn't sign in, wrong code, recovery codes once each, expiry, events, disable rules, admin reset;
  - the wrong-code limit;
  - sign out everywhere and forced password change;
  - masked failed sign-ins and append-only logs.
- **`RolesSecurityHttpTest` (4):**
  - two-step sign-in on both sign-in pages, returning to the page the person asked for;
  - required steps first (password change, then required two-step setup through the real page, with recovery codes shown once);
  - roles screens for five roles, and the new roles' reach;
  - a role created by a super admin giving exactly its access;
  - the sign-in activity page.
- **`AdminRoleMatrixHttpTest` (2), the role matrix from the live route table:**
  - every role (8) against every admin page: allowed pages open and never error; everything else is 403;
  - four roles posting every admin action outside their role: all 403, and nothing changes (536 checks).
- Two older tests were updated for the deliberate changes: the role count is now 8, and the route-safety allowlist.
- **Full suite: 469 tests, 4,862 assertions, all passing.**
- **Browser:** 0 serious/critical accessibility issues on roles (list and edit), staff (list and both edit views), My account, sign-in activity, Settings → Security and the staff sign-in; responsive checks passed on phone, tablet and desktop; screenshots reviewed.

## Stage 6 results (19 October 2026): verification and documentation

This stage also completes the marketplace upgrade's remaining Phase 10 and 11 items: the full browser sweep, the ledger load test and the docs. The summary for the owner is [REBRAND_FINAL_REPORT.md](REBRAND_FINAL_REPORT.md).

### Verification added

- **Role matrix from the live route table** (`AdminRoleMatrixHttpTest`):
  - each of the 8 roles is signed in and opens every admin page: allowed pages open without error, everything else is 403;
  - four roles post every admin action outside their role: all 403, and a fingerprint of settings, roles, permissions, payment methods and content is unchanged (536 checks).
- **The brief's acceptance checks** (`BriefAcceptanceHttpTest`):
  - with every optional setting deleted, 19 shop pages and 7 admin pages load without errors and invent no contact or payment details;
  - the tagline and footer note change from the admin;
  - a delivery-fee change in the admin changes the server's quote and the placed order, and a posted fee or total is ignored;
  - a suspended manager's open session stops on the next click.
- **Old-brand scan** (`tests/scripts/brand_scan.php`): every PHP string literal and HTML/JS/CSS text in the app, ignoring the deliberate internal identifiers. Result: none left.
- **Ledger load test** (`tests/scripts/ledger_load.php`, test database only): 100,000 rows across 20 sellers, written at about 1,300 rows/s through the real posting code.
  - Every balance is exact to the cent, and the balance query uses its index.
  - Seller dashboard, summary and statement: 9–20 ms.
  - Finance liability: 0.54 s; reconciliation: 0.42 s; 30-day seller performance: 1.2 s.
- **Integrity tool** (`bin/check-integrity.php`): 7 new checks:
  - the audit and sign-in log guards installed;
  - an active super administrator;
  - a payment method on;
  - a delivery option on;
  - the 9 built-in pages present;
  - no roles on non-staff accounts;
  - no two-step sign-in without a secret.
  - 33 checks in all. On the dev database every check passes except "no active super administrator", which is true: creating it is the owner's first step.
- **Full browser sweep:** every page with a fixed address, generated from the route table (63 admin, 11 Seller Centre, 11 customer account, 18 public), each signed in as the right user, plus the main id pages (an order, a case, a payout, an application, a seller part, a product, a store, a missing page).
  - Accessibility: 0 serious or critical issues.
  - Responsive at phone, tablet and desktop: no overflow and no console errors.

### Found and fixed in this stage

- **Old name in staff messages:** two Commission messages said "The Waimart Official Store…". They now use the store name.
- **Old name in addresses:** the Seller Centre filter address `?tab=with_waimart` is now `with_platform`; old links still work.
- **Contrast:** the outline "Deactivate" buttons (Pickup points and other admin lists) used Bootstrap's red, which sits on the contrast limit. The admin now uses a darker red.
- **Links told apart by colour only:** order links inside sentences on the Refunds and Resolutions pages are now underlined.
- The test fixture for the Official Store's commission note still used the old name; it now matches the shipped value.

### Documentation

- **Updated:**
  - **INSTALL.md:** first super admin with a one-time password and two-step sign-in; the new settings areas, payment methods and delivery settings; the integrity check.
  - **OPERATIONS.md:** daily and weekly checks; staff accounts and security procedures (leavers, lost phones, suspected compromise).
  - **ARCHITECTURE.md:** roles, permissions and sign-in; where managed content and settings live.
  - **BACKUP_RESTORE.md:** what the app key protects now.
- **New:**
  - **SELLER_GUIDE.md** (marketplace Phase 11);
  - **REBRAND_FINAL_REPORT.md** (the brief's deliverables, acceptance criteria, owner actions and deployment steps).

### Tests

- New: `AdminRoleMatrixHttpTest` (2), `BriefAcceptanceHttpTest` (4), and the brand and ledger scripts.
- **Full suite: 473 tests, 4,929 assertions, all passing.**
- **Rollback drill:** the pre-upgrade backup migrated 010→024 (8 triggers), then both rollbacks (0 triggers): identical schema, and identical settings keys and values.
