# Basemall — Operations

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
- **Credentials** (the SMTP password) live in the encrypted store, under Settings → Email & SMS. They are encrypted with the app key in `config.local.php`. If the key changes, the page says the password must be entered again.
- The **audit log** and **sign-in activity** cannot be edited or deleted, even directly in the database (triggers refuse it).

## Website updates (System → Website updates; super admins)

Install a new version of the website from a **.zip of the project** while the shop stays open.

1. **Upload:** choose the .zip and click **Upload and check**. It uploads in 2 MB pieces with a percentage, then is checked; nothing on the live site changes yet.
   - The package must be this project (same `composer.json` name). It may sit inside a top folder (as GitHub's "Download ZIP" does). `vendor/` is optional.
   - Every file is compared with the live one. **Only changed and new files are used; nothing is ever deleted.**
   - **Never touched:** `config/config.local.php`, `storage/`, `public/uploads/`, `.git/`.
   - Changed PHP files are syntax-checked.
   - **Database:** only migrations not yet applied are considered. They may only add: new tables, new columns that are optional or have a default, named indexes and constraints, triggers, views and new reference rows. Anything that changes or deletes existing data, or changes existing columns, **blocks** the update before anything happens; install such migrations during a quiet period with `php bin/migrate.php` after a backup.
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

- Each update's journal and backups are in `storage/updates/<id>/`. The three most recent are kept.
- Every step is in the audit log (`system.update_*`) and the update history.
- PHP's zip extension is **not** needed: the updater reads standard .zip files itself.

## Backups

`php bin/backup.php` writes a consistent database dump plus copies of `public/uploads` and `config.local.php` to `storage/backups`, and keeps the newest 14. Scheduling, off-site copies, restore and rebuild procedures are in [BACKUP_RESTORE.md](BACKUP_RESTORE.md). Installation is in [INSTALL.md](INSTALL.md).
