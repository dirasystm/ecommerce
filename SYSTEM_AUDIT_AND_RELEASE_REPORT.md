# System Audit and Release Report

## A. Project summary

| | |
|---|---|
| **Project** | Basemall: multi-vendor e-commerce marketplace (repository `dirasystm/ecommerce`) |
| **Audit date** | 22 October 2026 |
| **Technology** | PHP 8.2, MariaDB 10.4 and Apache 2.4 (XAMPP on Windows for development; Linux cPanel for live). Own lightweight framework, Bootstrap 5, PHPMailer, Talksasa SMS API |
| **Size** | 310 routes, 53 controllers, 68 services, 142 views, 75 tables (29 migrations), 8 roles, 38 permissions |
| **Scope** | Storefront, customer accounts, checkout and payments (M-Pesa manual and cash on delivery), order tracking, Seller Centre, admin (orders, catalogue, finance, sellers, content, settings, roles, audit, Email & SMS, website updates), notifications, backups and migrations |
| **Environments** | Development database `waimart` (left unchanged apart from the additive migration 029). All destructive and attack testing ran against the disposable `waimart_test` database, which was restored afterwards, or against throwaway databases that were then dropped |
| **Release status** | **READY WITH DOCUMENTED LIMITATIONS** (see F) |

## B. Testing summary

| Check | What was run | Result |
|---|---|---|
| Automated test suite (PHPUnit: unit, integration, HTTP) | Full suite before and after the fixes | Baseline **520 tests / 5,588 assertions, all passing**. Final **523 tests / 5,606 assertions, 0 failed, 0 skipped** |
| PHP syntax | `php -l` on every project PHP file | 407 files, 0 errors |
| Dependency audit | `composer audit` | No known vulnerable packages |
| Secret scan | Whole git history plus tracked files, for the live database, SMTP and SMS credentials, private keys and access tokens | None found. `config.local.php` and `config.live.php` are git-ignored |
| Static security scan | Tokenizer-based scan for shell, `eval` and `unserialize` use, SQL built from request input, and redirects from input | 2 shell calls, both safe (fixed values, escaped or argument-array, CLI or backup only). 0 SQL-from-input. 0 open redirects |
| Stored XSS (live) | Script payload planted in 1,057 free-text values; every reachable page crawled as admin, seller, customer and guest | **1,296 pages, 0 unescaped, 0 server errors**. Payload confirmed rendered escaped (100–219 times per page) |
| Attack probes | CSRF (8 forms), SQL injection and hostile input (64 tries across 8 inputs), path traversal (5), open redirect (4), method tampering (5), Host-header injection, unknown URL | **88 probes, 88 handled safely** |
| Access control | Existing route-security unit tests (every admin, seller and account route) plus HTTP role matrix and cross-customer/cross-seller (IDOR) tests | Pass (part of the suite) |
| Production mode | Live settings with the database unreachable | Clean error page, no internal details, HSTS sent, no PHP version |
| Sensitive paths | 15 URLs (config, logs, backups, private documents, source, `.git`, vendor) | All 403 |
| Migrations | Fresh database from all 29 migrations compared with development | 1,245 schema items identical. Re-run applies 0 |
| Rollback | All migrations plus `rollback_basemall.sql`, compared with a clean 001–017 install | 46 statements, 1,001 items identical |
| Data integrity | `bin/check-integrity.php` on development data | All checks OK (stock, refunds, ledger reconciliation, guards) |
| Accessibility (axe-core, WCAG 2.1 A/AA) | 14 public pages; 22 admin pages at desktop and phone width | **0 serious or critical issues** after fix A-5 |
| Responsive layout | Phone, tablet and desktop emulation | All pass |
| Performance (local, Apache) | Median of 5 runs per page; load test with 20 concurrent clients, 200 requests per page | See below |

**Performance, measured on the development PC.** The live server will differ.

| Page | Median response | Database queries | Load test (20 concurrent): p95, errors |
|---|---|---|---|
| Home | 162 ms | 38 → **27** | 1,022 ms, 0/200 |
| Category / Product | 131 / 89 ms | 21 / 25 → 22 | 433 ms (product), 0/200 |
| Search | 105 ms | 21 | 80/200 were HTTP 429 (rate limit, by design) |
| Categories / Help | 89 / 89 ms | 14 → **5** / 16 → **7** | 369 ms, 0/200 |

Target: under 500 ms per page for one visitor. Met by every page.

Compressed transfer, verified on a temporary Apache with `mod_deflate`: home page 87 → **8 kB**, Bootstrap CSS 227 → **30 kB**.

**Not verified:** Firefox and Safari (only Chromium/Edge was automated), and screen readers in use (only automated accessibility rules).

## C. Issues found and fixed

| Issue | Severity | Fix | Verification |
|---|---|---|---|
| A-4: Staff with `messaging.manage` or `settings.manage` could change the SMTP host or the SMS API endpoint. That would send the stored password or token, and every reset link and code, to their own server | **High** | Changing where credentials are sent now also needs `secrets.manage` (Email & SMS and Settings pages). `messaging.manage` is flagged as sensitive | HTTP test: refused on 3 paths, nothing changed, harmless edits still allowed |
| A-3: "SMS only" while SMS sending was switched off sent customers nothing | **High** | "SMS only" falls back to email until SMS is on | Integration test |
| A-5: The wide message-log table could not be scrolled with a keyboard | Serious (accessibility) | Scrollable tables become focusable, labelled regions on every admin page and width | axe: 22 admin pages × 2 widths pass |
| P-1: No compression of pages, CSS or JS | Medium | Guarded `mod_deflate` rules in `public/.htaccess` | Measured 70–91% smaller on a temporary Apache |
| P-2: Every page ran about 13 separate menu queries | Low | One query per request for all menus and titles | Query counts above; content tests pass |
| A-1: `X-Powered-By: PHP/8.2.12` header leaked the version | Low | Header removed | HTTP test |
| L-1: Rate-limited (429) responses gave no retry time | Low | `Retry-After` header sent | HTTP test (`Retry-After: 300`) |

Found and fixed earlier in this release cycle (each has a regression test): wrong SMS reset codes not counted towards the 5-try limit; the arrival date missing from order messages; delivery settings saves failing; and the order-journey and messaging bugs recorded in the git history.

## D. Remaining issues and risks

1. **SMS not verified end to end (external).** Talksasa accepts the API token (balance KSh 1,152) but refused sender ID "TALKSASA" ("Originator … is not authorized"). SMS sending is switched off until the account's approved sender ID is entered. Email was verified (test email delivered).
2. **Test accounts with known passwords** (`@basemall.test`) are in the development database and in any export of it. They **must be removed on the live site** before opening: `php bin/remove-test-accounts.php --confirm`.
3. **Email lands in spam at first.** SPF, DKIM, DMARC and reverse DNS are all correct. The cause is the new sender's reputation and the shared hosting server. Mark as "Not spam", and set a real reply-to address.
4. **Shared mobile IP addresses:** rate limits are per IP address. Many customers behind one mobile-carrier address could together hit the search limit (120 per minute). Watch after launch and raise the limit if needed.
5. **Registration reveals whether an email is registered.** Low; an accepted usability trade-off. Sign-in and password reset do not reveal it.
6. **The live server is not deployed or verified by this audit.** The live package was rehearsed locally only. The `Basemall-Live-Deploy` folder on the Desktop predates this commit and should be rebuilt before uploading.
7. **The development MySQL had damaged system tables twice** after unclean shutdowns (repaired with no data loss). Stop MySQL properly, or run it as a Windows service.
8. **No static-analysis tool** (PHPStan or Psalm) is installed. Coverage relied on the test suite, `php -l` and the custom scanner.

## E. GitHub status

| | |
|---|---|
| Repository | https://github.com/dirasystm/ecommerce |
| Branches | `main` and `feature/basemall-rebrand` |
| Commit with all fixes | `e16c31c` "System audit fixes; Email & SMS: notification channels, email server, test boxes with plain-language errors" |
| Push status | Succeeded. GitHub reports `main` at `e16c31c` |
| CI | None configured (0 workflow runs) |

This report is added in the next commit on the same branches. No credentials, tokens, `config.local.php`, `config.live.php` or database dumps are in the repository.

## F. Final decision

**READY WITH DOCUMENTED LIMITATIONS**

No known Critical or High issue remains in the code, and every fix is verified by an automated test. Before production use, the owner must:
- enter the approved Talksasa sender ID and send a test SMS (item 1);
- remove the test accounts on the live site (item 2);
- rebuild and upload the live package, then check it on https://basemall.co.ke (item 6).

**Production deployment is awaiting the owner's authorization.**
