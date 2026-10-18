# Basemall Rebrand and Database-Driven Management

**Started:** 18 October 2026 · **Branch:** `feature/basemall-rebrand` (from `feature/multi-vendor`) · **Status:** Stage 1 complete; awaiting approval to start Stage 2

This is the plan and progress record for the owner's request "Rebrand the e-commerce platform to Basemall and implement full database-driven management". The marketplace work it builds on is recorded in `docs/MARKETPLACE_PLAN.md`.

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

**Brand assets** (`public/assets/brand/`). Made from the supplied logo (kept as `basemall-logo-original.png`) with GD:

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

`store_name` became "Basemall". Values are changed only where they still held the shipped Waimart defaults.

- **Names:** every user-facing mention goes through `brand_name()`: pages, e-mails, SMS, flash messages, seller and admin screens, CSV export file names. A tokenizer-based rewrite placed each mention by context: HTML text, PHP strings, and `{store}` placeholders inside class constants (filled by `brand_text()`). Code comments and identifiers were left alone.
- **Colours:** admins pick a primary and an accent colour. `Brand::palette()` derives the shades, Bootstrap's RGB values, and a button colour that always keeps white text at **4.5:1 or better**. The logo orange #FF6A00 is only 2.87:1 with white text, so buttons use #C85200 automatically. A primary colour too light for white text is refused with an explanation. The palette is printed after `app.css` on every layout; the CSS defaults match it.
- **Head tags on every layout** (`partials/brand-head.php`):
  - theme colour, favicon and app icons;
  - the web app manifest `/manifest.webmanifest`, generated from settings;
  - Open Graph / Twitter preview on storefront pages;
  - page titles "Page | Basemall" and "Basemall — Everything You Need. One Marketplace."
- **E-mail sender name** = the store name, unless `mail.from_name` is set in `config.local.php`.
- **Fallbacks are neutral:** a missing name falls back to `config('app.name')` ("Basemall"); a missing or invalid image or colour falls back to the shipped files and palette. Image paths are checked against path traversal.

**Admin → Settings → Branding** (`settings.manage`, super admins today):
- name, tagline and order-number prefix;
- colour pickers with live "in use now" swatches;
- four image uploads, each with a preview and "use the original image again".

Uploads are checked by content (type, size, dimensions; ImageService) and re-encoded as PNG, never stored as uploaded:
- an icon upload generates the favicon and every app-icon size;
- if any upload in a save fails, nothing is kept (no orphan files);
- replaced uploads are deleted, but shipped files never are;
- every change is written to the audit log as `branding.updated` with before and after values.

**Official Store:** "Waimart Official Store" is now "Basemall Official Store" at `/store/basemall-official`. The old address `/store/waimart-official` redirects permanently (301) via the new `seller_slug_aliases` table, which keeps any store's old links working after an address change.

**Impersonation guard:** seller store names and addresses may not contain the platform's current name, however it is spelled ("Basemall Deals", "Base Mall Store", "BASE-MALL Outlet", `basemall-deals`), nor the former name. The list follows the name setting.

**Order numbers:** new orders are `BM-XXXXXXXX`. Every route and lookup accepts any two-letter prefix, so `WM-` orders, tracking links and receipts keep working, and the prefix can be changed later without breaking anything.

**Rollback:** `database/rollback/rollback_basemall.sql`. Drilled on a copy: the pre-upgrade backup was migrated through 010–018, then this rollback and the marketplace rollback were run. The schema was identical and all data identical except the `updated_at` timestamp of the one restored setting (key/value comparison: all 17 settings identical).

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
