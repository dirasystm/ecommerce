# Basemall Marketing Intelligence: go-live report

**Date:** 22 October 2026 · **Branch:** `feature/basemall-rebrand` · **Migrations:** 031, 032, 033 · **Rollback:** `database/rollback/rollback_marketing.sql`

## Verdict: READY WITH DOCUMENTED LIMITATIONS

The features are built, tested and documented. Everything is safe to deploy, because every marketing message is **off by default** and nothing is sent without consent. Three things are still needed before the full value is available (section 6):
- an approved SMS sender ID;
- the 15-minute scheduler on the live server;
- switching on the automations you want.

## 1. What was built

| Area | What it does | Where |
|---|---|---|
| Shopping activity | Records product, category, brand and store views, searches, cart, wishlist, checkout and order events. Only with consent; staff are excluded; repeats within 30 minutes count once; guests get a pseudonymous browser id. | `Services/Marketing/Tracker.php`, `Signals.php` |
| Customer controls | Browsing history (delete one item or everything), Privacy & marketing page (email, SMS, frequency, personalisation), data export as JSON, guest privacy banner. | My account → Browsing history / Privacy & marketing; `/privacy-choices` |
| Recommendations | Placements: homepage "Recommended for you", similar products, frequently bought together, cart "You may also need", account "For you", order confirmation, marketing emails. Interest is weighted and fades over time. Only products on sale and in stock; "Not interested" is respected. | `Recommender.php`; switches and weights in Marketing settings |
| Segments | 14 live segments (new, browsing, frequent viewers, wishlist, cart abandoners, first-time, recent, repeat, high value, inactive, declining, email/SMS consent, opted out) plus buyers per category. | Marketing Intelligence → Segments |
| Automations | Welcome, cart reminders (2 steps), after delivery, review request, win-back (3 steps), wishlist price drop, product discovery. Each is queued once, then checked again at send time for consent, relevance, daily and weekly limits and quiet hours. Includes retries and a global pause. | `Automation.php`, `bin/marketing-run.php` |
| Promotional links | Campaigns with links `/go/<code>`. Links only ever open pages of this shop. Bots and crawlers are not counted; crawlers get the share preview (Open Graph). QR scans are counted separately. Links can expire or be switched off, and support A/B variants. | Campaigns & links |
| Creative studio | Creative built from database facts (name, current and real previous price, rating, stock); coupon only if real and active. Texts for Facebook, Instagram, WhatsApp and X, plus hashtags, share buttons, QR code (PNG) and square and story images (PNG). | Link studio |
| Leads & attribution | Newsletter (email consent required, SMS optional), registrations and lifecycle stage. First- and last-click attribution within a 30-day window. Contact details are masked without `marketing.leads`; exports are audited. | Leads |
| Dashboard | Cards with written definitions; 8 date ranges; attribution model switch; funnel; campaigns table with search, sort and pages; channels; advertised products; recommendation placements; rule-based optimization assistant that states sample sizes. | Marketing Intelligence → Overview |
| Consent records | Every consent change with source and wording version; suppression reasons; signed unsubscribe links that need a button press; List-Unsubscribe header. | Consent |

## 2. Honesty rules applied

- **No invented numbers.** Every figure comes from recorded events.
  - Social-platform impressions are shown as "Not available".
  - Return on ad spend (ROAS) appears only when a real cost was entered.
  - "Unique visitors" is labelled as an estimate.
  - Email opens are not tracked, and the screen says so.
- **No invented discounts.** "Was" prices are the real previous prices. Coupons must exist and be active.
- **No messages without consent**, and no posting or messaging on social media on anyone's behalf.
- **Sales count only when paid** (M-Pesa verified or cash on delivery collected). Cancelled and refunded orders are shown separately.
- The optimization assistant describes measured differences and says when samples are small. It does not claim cause.

## 3. Security and privacy

- **Permissions:**
  - `marketing.view` and `marketing.manage` go to Content & marketing; Manager gets view only.
  - `marketing.leads` (contact details and export) is super admin only and is marked sensitive in the permission matrix.
- **Promotional links:**
  - Their destinations are validated internal paths. `//host`, `https://…`, backslashes and `..` are refused (tested).
  - `/go` is rate-limited.
- **Unsubscribe links** are signed with the app key. A tampered link is refused, and only the POST button changes anything (tested).
- **Personal data:**
  - Shopping activity holds no contact details, tokens or payment data.
  - It is deleted after the retention period (180 days; guests 30).
- **Data Protection Act, 2019:**
  - consent is recorded per channel with the wording version;
  - customers can export and delete their own data;
  - opting out also removes guest activity.

## 4. Tests

| Suite | Result |
|---|---|
| Full PHPUnit suite | **OK: 552 tests, 6,165 assertions** (one real bug fixed on the way: the cart page crashed when it had items, because the recommendation hook read the wrong key) |
| New: `tests/Integration/MarketingTrackingTest.php` | 5 tests pass |
| New: `tests/Integration/RecommenderTest.php` | 4 tests pass |
| New: `tests/Integration/MarketingAutomationTest.php` | 5 tests, 23 assertions pass |
| New: `tests/Feature/MarketingHttpTest.php` (HTTP: permissions, every page, links/redirect safety/crawlers/QR/attribution/ROAS, newsletter, masking, export audit, unsubscribe, customer history/privacy/export) | 4 tests, 89 assertions pass |
| Accessibility (axe, WCAG 2.1 AA) on the new admin pages | Not run yet: built with labelled fields, table captions and keyboard-scrollable tables |
| Rollback drill (031–033 on a copy) | Schema identical to the pre-031 backup (76 tables); permissions, settings, homepage sections and role grants match |

## 5. Deployment

1. Back up: `php bin/backup.php`.
2. Deploy the code, then run `php bin/migrate.php` (applies 031, 032 and 033).
   - Migration 032 changes an existing column's list of values (`home_sections.type`). For that reason **Admin → Website updates** will refuse it.
   - Use `bin/migrate.php`, or the full live package, for this release.
3. Schedule `bin/marketing-run.php` every 15 minutes (docs/OPERATIONS.md → Marketing Intelligence).
4. Review **Marketing Intelligence → Marketing settings**. Then switch on the automations you want, email first.

## 6. Limitations and next steps

- **SMS marketing is waiting for an approved Talksasa sender ID.** "TALKSASA" was refused by the gateway. Until a sender ID is approved, use email only.
- **The scheduler must be set up on the live server**; nothing automatic is sent without it. The Automations page shows whether it has run.
- **Email deliverability:** messages from the new domain may land in spam at first (new-sender reputation; SPF, DKIM and DMARC are in place).
- **There are no social-platform APIs.** Impressions, reach and ad spend come from the platforms' own dashboards, and the cost is entered by hand.
- **Recommendations get better with activity.** A new shop shows "Popular right now" until there is enough behaviour.
- **Attribution is cookie-based (30 days)** and per browser, so one person on two devices counts as two visitors.
