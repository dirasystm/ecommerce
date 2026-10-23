# Ecommerce — Operations

## Reservation expiry worker (required)

Unconfirmed orders hold stock only for a limited time:

| Setting | Default | Applies to |
|---|---|---|
| `reservation_minutes` | 30 | M-Pesa orders awaiting payment or verification |
| `reservation_minutes_cod` | 240 | Cash-on-delivery orders awaiting staff confirmation |

When an order is **confirmed** (payment verified, or COD confirmed by staff), its stock is held until fulfilment and never expires.

`bin/expire-reservations.php` releases due reservations, marks those orders `expired`, notifies customers, and purges guest carts older than 30 days. It is safe to run repeatedly or concurrently: a MariaDB named lock allows one run at a time, and every release is a guarded, exactly-once database update.

### Run it manually

```
C:\xampp\php\php.exe C:\xampp\htdocs\ecommerce\bin\expire-reservations.php
```
Output: `[time] expired orders: N, reservations released: N, errors: N, guest carts purged: N`. The exit code is 1 if any order failed (details are in `storage/logs/app-*.log`).

### Windows / XAMPP — Task Scheduler (every minute)

Run this once in an **elevated** Command Prompt:

```
schtasks /Create /TN "Waimart Reservation Expiry" /SC MINUTE /MO 1 /RU SYSTEM ^
  /TR "\"C:\xampp\htdocs\ecommerce\bin\expire-reservations.bat\""
```

- Check it: `schtasks /Query /TN "Waimart Reservation Expiry" /V /FO LIST`
- Run it now: `schtasks /Run /TN "Waimart Reservation Expiry"`
- Remove it: `schtasks /Delete /TN "Waimart Reservation Expiry" /F`
- Output is appended to `storage\logs\expiry.log`. Quiet runs log only when something happened.

### Linux — cron (production)

```
* * * * * /usr/bin/php /var/www/waimart/bin/expire-reservations.php --quiet >> /var/www/waimart/storage/logs/expiry.log 2>&1
```

### Fallback

If the scheduler is not running, the cart, checkout and product pages trigger a small sweep (at most 20 orders, at most once a minute site-wide). **This does not replace the scheduled worker in production.** Busy sites need the worker so stock is released on time.

## Order status rules (summary)

The order workflow (`app/Services/OrderWorkflow.php`) is the only way a status changes. Every transition is checked against the table below, recorded in the status history, and audited when staff make it.

- **M-Pesa:** `awaiting_payment` → `awaiting_verification` → `confirmed` (only after payment is verified)
- **COD:** `pending_confirmation` → `confirmed` (by staff)
- **Delivery:** `confirmed` → `processing` → `packed` → **`dispatched`** (stock deducted here) → `out_for_delivery` → `delivered`
- **Pickup:** `confirmed` → `processing` → `ready_for_collection` → **`collected`** (stock deducted here)
- **Cancel:** allowed until handed over. It releases reserved stock exactly once, returns a coupon use, and records a refund obligation if the payment was already verified. After dispatch or collection, use the returns process instead.
- **Customers** can cancel online only while `awaiting_payment` or `pending_confirmation`.

## Database memory (recommended)

XAMPP ships MariaDB with `innodb_buffer_pool_size=16M`, which is smaller than the shop's data once the catalogue reaches a few thousand products, so queries start reading from disk. In `C:\xampp\mysql\bin\my.ini` (back it up first), set:

```ini
innodb_buffer_pool_size=256M
```

Then restart MySQL from the XAMPP Control Panel. Phase 12 measurements are in `docs/REQUIREMENTS_CHECKLIST.md` (Phase 12 notes).

## Daily and weekly checks

| When | What | Where |
|---|---|---|
| Daily (schedule it) | `php bin/check-integrity.php`: order and part totals, stock vs reservations, refund caps, ledger reconciliation, the append-only guards, at least one super admin, a payment method on, a delivery option on. Exit code 1 if anything fails. | Task Scheduler / cron, like the backup |
| Daily | Payments waiting to be verified, COD orders to confirm | Finance → M-Pesa payments, Payment methods (the list shows what is waiting) |
| Weekly | Failed or blocked sign-ins, especially at the staff sign-in | System → Sign-in activity |
| Weekly | Unusual staff actions (exports, settings, payment numbers, roles) | System → Audit log (filter by area) |
| Before paying sellers | Reconciliation | Marketplace → Marketplace report, then Seller payouts |

## Staff accounts and security

- **New staff:** Admin → Staff → Add. They get an email link to set their own password. Give the smallest role that fits; add a single permission on their page if they need one more thing.
- **Someone leaves:** remove their staff access (their history stays in the audit log), or suspend them. Either signs them out everywhere at once.
- **Lost phone (two-step sign-in):** on their staff page, **Reset two-step sign-in**. They sign in with their password and set it up again.
- **Suspected compromise:**
  1. on their page, **Sign out everywhere**, then **Require a password change** (or email a reset link);
  2. review Sign-in activity and the audit log for that person.
- **Credentials** (the SMTP password and the SMS API token) live in the encrypted store, under Settings → Email and Admin → Email & SMS. They are encrypted with the app key in `config.local.php`. If the key changes, the page says the password must be entered again.
- The **audit log** and **sign-in activity** cannot be edited or deleted, even directly in the database (triggers refuse it).

### Customers stay signed in ("Keep me signed in")

Customers and sellers stay signed in on their phone or computer, in the browser or the app, until they sign out. Closing the browser or app does not sign them out.
- The box is ticked by default on the sign-in and registration pages. Customers can untick it on a shared device.
- **What ends it:**
  - signing out on that device;
  - changing the password;
  - "Sign out everywhere";
  - suspending the account;
  - a year without use.
- **Staff are never remembered.** The admin always asks for the password, and two-step sign-in where it is required.
- Database update `035_remember_tokens.sql` adds the table. Website updates applies it automatically.

## Email & SMS (System → Email & SMS)

One page for both channels (permission `messaging.manage`; changing the SMS token also needs `secrets.manage`).

- **SMS gateway: Talksasa bulk SMS** (`https://bulksms.talksasa.com/api/v3/`). To set it up:
  1. In Talksasa → Developers, click **Reveal token** and copy the API token. Paste it under **API token** and save. It is stored encrypted and never shown again. The page then shows the balance, which proves the token works.
  2. Enter the **Sender ID** exactly as approved on the Talksasa account (up to 11 characters).
  3. **Send a test SMS** to your own phone. This works while customer SMS is still off.
  4. Choose **Send through Talksasa** and save. From then on, every customer and seller SMS goes out through Talksasa.
  - While SMS is off, messages are only recorded in the log ("Not sent (log only)").
- **Numbers at the top:** the SMS balance (refreshed every 2 minutes, or with **Refresh**) and, for SMS and for email, how many were sent, failed or only logged: in total, today and in the last 30 days.
- **Delivery reports:** "Sent" means Talksasa accepted the SMS. **Check delivery reports** asks Talksasa about SMS from the last 3 days; any not delivered move to Failed with the reason.
- **Message log:** search by phone (07… or 2547…), email, order number or text; filter by SMS/email and status.
  - **Re-send** a failed message after fixing the cause (balance, token, sender ID).
  - Messages that held a code or private link cannot be re-sent: repeat the original action instead.
- **How notifications are sent:** Email only, SMS only, or Both.
  - If someone can't be reached the chosen way (e.g. SMS only, but no phone number), they get the other one.
  - While SMS sending is switched off, "SMS only" also sends email, so customers are never left without updates.
  - One-time codes and reset links always go the way the person asked for.
- **Email server:** the mail server, port, encryption, username and sender details, with the account password stored encrypted. Settings → Email shows the same settings.
- **Test your setup** (below the SMS gateway and email server): a Test SMS box and a Test email box.
  - Each shows the outcome under the button in plain words: what went wrong and what to do (for example "The sender ID is not approved on your Talksasa account"), with the exact message from Talksasa or the mail server under *Technical details*.
- **Credentials are protected:** changing where credentials are sent requires the right to manage credentials (`secrets.manage`). This covers the mail server, port, encryption, username and the SMS API endpoint, because the saved password or token, and every email or SMS including reset links and codes, would go there.
- SMS are sent while the page or action that triggers them runs. If Talksasa is slow or down, the message is marked Failed (with the reason) after about 12 seconds, and the order or other action still completes.

## Forgotten passwords

Every sign-in page has **Forgot your password?**: the shop sign-in (customers and sellers) and staff sign-in.
- People enter their email **or** phone number and choose either:
  - **an email link**, which works for 60 minutes;
  - **an SMS code** (6 digits), which works for 15 minutes and allows 5 tries.
- The SMS option appears only when SMS is switched on (Email & SMS).
- Either way they set a new password on the same page. Staff then return to staff sign-in, where two-step sign-in still applies.
- The page looks the same whether or not the account exists, so it cannot be used to find out who has an account.
- Codes and links are hidden in the message log. Requests, wrong codes and resets appear under Sign-in activity.

## Order progress and arrival dates

The customer's order page shows every step, from placing the order to delivery or collection.
- **Each step shows:** which office handles it (from the staff member's role, never their name), when it happened, and what is happening now.
- **Estimated arrival:** counted in working days, Monday to Saturday.
  - It uses the delivery zone's days, plus **Days to prepare before dispatch** (Delivery settings).
  - Pickup orders use **Pickup orders ready after**.
  - After dispatch, the count starts from the dispatch. Out for delivery shows "Today".
- **Messages:** customers get an SMS and an email at each step: confirmed, being prepared, packed, dispatched, out for delivery, delivered or ready, and collected. Each carries the estimate. The wording is under Content → Messages.

## Pickup station applications

Businesses with existing premises apply on the public page **Become a pickup station** (`/become-a-pickup-station`, linked in the footer).
- **The form:** business details, contact person, location (county, town, address, landmark, Google Maps link or "Use my current location"), opening hours day by day, space for parcels, lockable storage, cash on delivery, an optional photo of the shop front (stored privately) and notes.
- **After applying:** the business gets a reference (`PS-…`) by email and SMS. It can check the status at `/become-a-pickup-station/check` with the reference and its phone number.
- **Review:** super admins (or anyone given `pickup_points.approve`) are emailed. Review under **Admin → Station applications**.
  - The review page shows every detail, the photo, the map location and the stations already in that county.
  - **Approve and create station** creates the pickup point with a suggested code in the usual style (e.g. `LKP-NYK-01`). Adjust any field first; the station is live at checkout straight away.
  - **Reject** needs a reason, which the business sees.
  - The business is told by email and SMS either way.
- **At checkout**, choosing Pickup asks for the customer's county ("Your county").
  - It is filled in from the county they typed for delivery, or from their saved address.
  - Each county shows how many stations it has, or "no station yet".
  - "Suggested for you" lists that county's stations at once (same town first), and the full list narrows to the county. Typing a town in the search box still searches every station.
  - One tap on a suggestion chooses that station.
  - "Use my location" ranks by distance only for stations that have map coordinates; add them on each station's page.

## Pickup stations: arrival, collection and returns (Sales → Collection desk)

The Collection desk has three tabs. Station staff need a staff account with the **Delivery & Logistics** role (Fulfilment Staff and Managers can also use it).

| Tab | What it lists | What staff do |
|---|---|---|
| **On the way to the station** | Pickup orders being prepared | When the parcel arrives, click **Arrived, notify customer**. |
| **Ready to hand over** | Parcels waiting, with each one's last day ("Last day" and "Overdue" are marked) | Open the order: the customer's code and ID, then **Verify code & hand over**. |
| **Returns to warehouse** | Parcels going back from the stations | When one is back at the warehouse, click **Received back**. |

**When a parcel arrives**, the customer gets an SMS and email at once, with:
- the station's name, address and opening hours;
- their private collection code;
- a reminder to bring their national ID or passport (or a written authorisation and ID if someone collects for them);
- the **last day to collect** (arrival + the hold period, 7 days by default), and that the parcel is returned otherwise.

**Automatic steps**, done by the scheduled job (`bin/expire-reservations.php`, every minute):
- **Reminder:** one SMS and email the day before the last day (24 hours before, by default).
- **Return:** a parcel still not collected after the hold period is returned automatically.
  - The order is cancelled ("Not collected within 7 days: returned to the warehouse") and the code stops working.
  - If the customer had paid by M-Pesa, a refund is recorded for Finance (Finance → Refunds).
  - The customer gets an SMS and email saying so.
  - The parcel appears under **Returns to warehouse**. Its stock stays held (not sold again) until staff click **Received back**.
- Both can be changed under **Settings → Orders**: the hold period, the reminder timing, and "Return parcels not collected in time" (if off, overdue parcels are only marked Overdue).
- Orders with marketplace sellers' parts are not returned automatically: staff handle them on the desk.

**Sending a parcel back early** (for example, the customer refuses it): on the order at the desk, open **Send it back to the warehouse**, choose why, and confirm. The same steps follow.

**Lost code:** anyone at the desk can **Re-issue a new one**. It goes to the customer's own phone and email, with the same last day. The old code stops working.

## Order tracking numbers

When staff dispatch a seller's part of an order, they can enter the courier's **delivery reference**. This becomes the customer's **tracking number**:
- it is shown on the order page;
- it is included in the "dispatched" email and SMS (placeholder `{tracking_note}`);
- on **Track order**, customers can enter either the order number or the tracking number, together with the phone number used at checkout.

## Website updates (System → Website updates; super admins)

Install a new version of the website from a **.zip of the project** while the shop stays open.

1. **Upload:** choose the .zip and click **Upload and check**. It uploads in 2 MB pieces with a percentage, then is checked; nothing on the live site changes yet.
   - The package must be this project (same `composer.json` name). It may sit inside a top folder (as GitHub's "Download ZIP" does). `vendor/` is optional.
   - Every file is compared with the live one. **Only changed and new files are used; nothing is ever deleted.**
   - **Never touched:** `config/config.local.php`, `storage/`, `public/uploads/`, `.git/`.
   - Changed PHP files are syntax-checked.
   - **Database:** only migrations not yet applied are considered. A migration counts as applied when one with the same number was applied, even under an older file name.
     - **Installed with the update:** pure additions, such as new tables, new columns that are optional or have a default, named indexes and constraints, triggers, views and new reference rows.
     - **Left for later:** a migration that changes or deletes existing data, or changes existing columns, is not run and does **not** stop the update. It waits under **Database updates**, together with every migration after it, so they still run in order.
   - Only a PHP syntax error in the package blocks an update.
2. **Review:** the number of changed, new and unchanged files, and the database additions (or the reasons it is blocked). Confirm with your password, or cancel.
3. **Install:** a spinner, the current task and a percentage throughout. In order:
   1. the database is backed up (only if it changes);
   2. every file about to be replaced is backed up;
   3. the new files are written beside the live ones under temporary names;
   4. the database additions are applied, and all files are switched in one quick pass (each switch is an instant rename; the whole pass takes milliseconds);
   5. the browser checks the home page and the health check, and the server checks that every changed class loads;
   6. finish.
4. **If anything fails after the first change,** everything is rolled back automatically:
   - replaced files are restored and new files and folders removed;
   - the tables, columns and other objects the update added are removed;
   - existing data is never touched.
   - If the new version is so broken that the admin cannot run, the page calls the **emergency rollback**, `public/update-rescue.php`, which works without the rest of the website.
5. **Roll back by hand:** the most recent installed update has a **Roll back** button in the history (password required). Anything saved in tables or columns the update added is removed with them.

**Last resort, on the server:** `php bin/update-rescue.php` lists recent updates; `php bin/update-rescue.php <id> --confirm` rolls one back without the website.

### Database updates (on the same page)

Database changes that an update left for later wait here. When any are waiting, a red count shows on **Website updates** in the menu.
- **The list:** each waiting change in the order it will run, marked "changes existing data" or "additions only", with what each step does.
- **Running them:** enter your password and click **Back up and run database updates**. Do it when the shop is quiet.
  1. A full backup of the database is saved first, to `storage/backups/before-database-update-<date>.sql`.
  2. The changes run in order.
  3. The result and the backup's name are shown. Every run is in the audit log (`system.db_update_*`).
- **If one fails:** it stops there. The ones before it stay done, and the message names the backup. Restore it with `php bin/restore.php` if needed.
- **Command line:** `php bin/migrate.php` does the same, without the backup.

**Backups without mysqldump:** many shared hosts do not allow running `mysqldump`. The website then makes the backup itself, in PHP, automatically. The file includes tables, rows, views and triggers, and loads with the `mysql` client or phpMyAdmin.

- Each update's journal and backups are in `storage/updates/<id>/`. The three most recent are kept.
- Every step is in the audit log (`system.update_*`) and the update history.
- PHP's zip extension is **not** needed: the updater reads standard .zip files itself.

## Staff alerts for new orders

As soon as a customer places an order, the staff who must act on it get an **email and an SMS**:
- **M-Pesa orders:** super administrators and everyone who can verify payments (permission `payments.verify`, e.g. Finance and Manager). They are alerted again the moment the customer submits the M-Pesa transaction code, with the code, the amount claimed and the phone it was paid from.
- **Cash-on-delivery orders:** super administrators and everyone who can confirm orders (`orders.manage`, e.g. Manager and Fulfilment staff).

How it works:
- Who receives alerts follows **System → Roles & permissions**; suspended staff receive nothing.
- Staff need a phone number on their account to get the SMS.
- The alerts are sent after the customer's page has loaded, so checkout is never slowed down.
- Every alert appears in **Email & SMS → Message log**.
- Wording: **Messages → Staff alerts** ("New order placed (to staff)", "M-Pesa code submitted (to staff)").
- SMS needs the gateway to be on; until then, alerts go by email.

### Red counts and the "orders waiting" reminder

While signed in to the admin, a **red number** next to a menu item shows how much work is waiting there.

**Orders** counts the orders not yet acted on, and only the ones you can move on:
- COD orders to confirm (`orders.manage`);
- M-Pesa codes to verify (`payments.verify`);
- orders needing a resolution (`resolutions.manage`).

Other menu items count their own queues, again only for staff who can open them:
- M-Pesa payments, Collection desk, Seller orders, Returns and Reviews;
- Resolutions, Cash on delivery, Refunds;
- Seller applications, Product approvals, Seller cases and Station applications.

While orders are waiting, a **pop-up in the bottom-right corner** says "N orders are waiting for your action" and links to the right orders.
- **Remind me later** hides the pop-up for 10 minutes. It comes back at once if another order arrives.
- The counts, and the "(N)" in the browser tab title, refresh every 30 seconds, so a new order appears without reloading the page.
- These refreshes do not keep an idle admin signed in: the session still times out as usual.

The **Dashboard** opens with **Priority actions**: one coloured, clickable tile per queue that has work waiting, showing how many. The most urgent tiles come first, and each tile opens its list.

| Colour | Tier | Queues |
|---|---|---|
| Red | Urgent | COD orders to confirm, M-Pesa payments to verify, orders needing resolution, refunds due |
| Orange | Do today | Orders to prepare, packed and ready to dispatch, seller parcels to receive, pickups past the hold period, returns |
| Blue | Approval | Seller applications, products to approve, station applications, reviews, open seller cases |
| Purple | Follow up | New orders still awaiting payment, COD cash not recorded |
| Amber | Stock | Variants out of stock or low on stock |

- Empty queues are listed underneath under "All clear".
- The sales chart now sits below Latest orders.

## Marketing Intelligence (Admin → Marketing Intelligence)

Recommendations, customer segments, promotional links with results, leads and automated marketing messages. Everything is counted from what happens on this shop; nothing is estimated except where the screen says "est.".

**Who can use it**
- `marketing.view`: dashboards, campaigns, segments and automations, with contact details masked. Given to *Content & marketing* and *Manager*.
- `marketing.manage`: create campaigns and links, edit automations and Marketing settings. Given to *Content & marketing*.
- `marketing.leads`: see and export lead contact details. Super administrators only, until you grant it under Roles & permissions. Every export is in the audit log.

**Privacy and consent (Kenya Data Protection Act, 2019)**
- Customers choose for themselves on the sign-up form and under **My account → Privacy & marketing**: marketing email, marketing SMS, how often, and personalised suggestions. Every change is kept, with the date and the wording shown. Staff cannot switch marketing on for a customer.
- Guests see a privacy banner. With "Ask guests first" (the default), nothing a guest does is recorded until they tap **Allow**. Saying no also deletes what was recorded for that browser.
- Customers can see and delete their browsing history (**My account → Browsing history**) and download their data as a file.
- Every marketing message has an unsubscribe link, and emails carry a List-Unsubscribe header. Order and account messages are not marketing and are not affected.
- Shopping activity is deleted after **Keep shopping activity for** (180 days by default); guest activity after 30 days.

**Promotional links (Campaigns & links)**
1. Create a campaign: name, objective, channel or platform, dates. Enter the **actual cost** if you want return on ad spend; it is never estimated.
2. Create a link to a product, category, brand, store, search or another page of the shop. A link can only open pages of this shop.
3. The **Link studio** gives you:
   - the short link `https://example.co.ke/go/<code>`;
   - ready-to-post texts for Facebook, Instagram, WhatsApp and X, plus hashtags and share buttons;
   - a QR code for posters (its scans are counted separately);
   - downloadable square and story images.
   Prices and ratings always come from the shop. A coupon can only be one that exists and is active.
4. This shop never posts or sends messages on social media for you. Copy the texts and post them yourself.

**How results are counted**
- Link-preview crawlers (WhatsApp, Facebook…) get the share card and are not counted as visits.
- A sale counts for the last link the visitor clicked within the attribution window (30 days). You can switch the view to the first link instead.
- "Paid" means M-Pesa verified or cash on delivery collected. Cancelled and refunded orders are never counted as sales.
- Impressions and reach on social platforms are not available to the shop; read them in the platform's own reports.

**Automations (all off until you switch them on)**
- The automations are: welcome; cart reminders (3 h and 24 h); after delivery; review request; win-back (30/60/90 days after the last order); wishlist price drops; product discovery.
- Before each message is sent, the system checks consent, the daily and weekly limits (1 and 3), quiet hours (20:00–08:00 Kenya time) and whether it is still relevant. For example, a cart reminder stops if the customer has ordered.
- A message is never queued twice for the same event. **Pause all marketing messages** in Marketing settings stops everything at once.
- The message wording is under **Content → Messages** (group "Marketing").
- SMS needs an approved sender ID (Email & SMS). Until then, choose email only.

**Abandoned carts (Marketing Intelligence → Abandoned carts)**
- **When a cart counts as abandoned:** it has items, nothing happened for **First reminder after** minutes (default 60), and no order followed. Activity is recorded on the server: adding, changing or removing items, and opening the cart or checkout. Closing the browser or signing out sends nothing early.
- **What a reminder contains:** the items still on sale and in stock at today's prices, and a personal link (valid for 7 days) back to that cart. A customer's cart needs them signed in. A guest's cart reopens in the browser that follows the link. Nothing is ordered or charged.
- **Who gets reminders:** only customers who agreed to marketing on that channel, and guests who signed up for the newsletter with consent in the same browser.
- **When reminders stop:**
  - a new visit postpones the reminder;
  - an order, an emptied cart or items no longer available stop it;
  - failures are retried up to the set number of attempts, then marked Failed.
- **What the page shows:** abandoned and remindable carts; email and SMS sent, delivered and failed; retries; link clicks; carts customers came back to; recovered orders and revenue (and how much of it is paid); the recovery rate. It also has the searchable cart list, the reminder log and the settings.

**Loading feedback and tables (all staff, seller and customer pages)**
- **Loading feedback:** buttons show a spinner and cannot be pressed twice while a form is sent. Checkout and payment show a full-page "Please wait" message. Uploads show "Uploading…". Slow page changes show a thin bar at the top.
- **Tables:**
  - show 10 rows by default (15, 25 or 50 from the footer) with "Showing 1–10 of N";
  - search as you type (on the server), filter and sort without reloading the page;
  - work without JavaScript as normal links and forms.
- See `MARKETPLACE_RECOVERY_LOADING_TABLES_IMPLEMENTATION_REPORT.md` for the developer API (`WM.setButtonLoading`, `ListParams`, the table partials).

**Scheduler (required for automations and the nightly upkeep)**

Windows / XAMPP, in an elevated Command Prompt:
```
schtasks /Create /TN "Ecommerce Marketing" /SC MINUTE /MO 15 /RU SYSTEM ^
  /TR "\"C:\xampp\htdocs\ecommerce\bin\marketing-run.bat\""
```
Linux (cron) on the live server:
```
*/15 * * * * /usr/bin/php /path/to/ecommerce/bin/marketing-run.php --quiet >> /path/to/ecommerce/storage/logs/marketing.log 2>&1
```
- Each run queues due messages and sends them.
- Once a day it also rebuilds "frequently bought together" and deletes activity past its retention.
- Run it by hand with `php bin/marketing-run.php` (add `--nightly` to force the daily tasks).
- Only one run works at a time, so overlapping runs are harmless.
- Output is in `storage/logs/marketing.log`. The Automations page shows when the nightly tasks last ran.

**Troubleshooting**
- *No recommendations on the homepage:* check that **Show recommendations** and the placement are on in Marketing settings. Only products in stock are shown. New shops show "Popular right now" until there is activity.
- *Automation shows "waiting" but nothing is sent:* the scheduler is not running, it is quiet hours, the daily limit was reached, or marketing is paused.
- *"Not allowed (consent)":* the customer has not agreed to that channel. This is expected.
- *Link opens the homepage:* the link is switched off or expired, or the code is wrong.

**Rollback:** `database/rollback/rollback_marketing.sql` removes migrations 031–033 (after a backup; see the file header).

## Backups

`php bin/backup.php` writes a consistent database dump plus copies of `public/uploads` and `config.local.php` to `storage/backups`, and keeps the newest 14. Scheduling, off-site copies, restore and rebuild procedures are in [BACKUP_RESTORE.md](BACKUP_RESTORE.md). Installation is in [INSTALL.md](INSTALL.md).
