# Ecommerce — Installation

This guide installs Ecommerce (formerly Waimart; internal names such as the `waimart` database keep the old name) on **Windows with XAMPP**, the environment it was built and tested on. A section at the end covers a Linux production server.

## 1. Requirements

| Component | Tested version | Notes |
|---|---|---|
| XAMPP | 8.2.12 | Apache + PHP + MariaDB |
| PHP | 8.2.12 | Extensions: `bcmath`, `mbstring`, `openssl`, `pdo_mysql`, `gd`, `fileinfo` (all enabled in XAMPP 8.2 by default). `curl` is needed only to run the tests. |
| MariaDB | 10.4.32 | MySQL 8 should work but is untested |
| Composer | 2.x | Installs PHPMailer and PHPUnit |
| Apache modules | `mod_rewrite` (required), `mod_headers` (recommended) | Both enabled in XAMPP |

Check the PHP side with `C:\xampp\php\php.exe -m`. Composer checks it too (step 3).

## 2. Put the code in place

Copy the project folder to `C:\xampp\htdocs\ecommerce`. The shop is then served at **http://localhost/ecommerce**.

- Only `public/` is web-visible. The root `.htaccess` blocks `app/`, `config/`, `storage/`, `bin/`, `vendor/`, `docs/`, the tests and all `.md` files, and routes everything else into `public/`.
- To use a different folder name, change `app.url` and `app.base_path` in step 4.
- On a domain root (e.g. `https://shop.example.co.ke`), set `base_path` to `''`.

## 3. Install PHP dependencies

From the project folder:

```
cd C:\xampp\htdocs\ecommerce
composer install --no-dev        # production: PHPMailer only
composer install                 # development: also PHPUnit, to run the tests
```

`php` is not on PATH in a default XAMPP install. Either add `C:\xampp\php` to PATH, or run the scripts below with `C:\xampp\php\php.exe` in place of `php`.

## 4. Create the databases and local config

Start **Apache** and **MySQL** in the XAMPP Control Panel, then run:

```
php bin/install.php                          # XAMPP default: root with no password
php bin/install.php --root-password=SECRET   # if your MySQL root has a password
```

The installer is safe to re-run. It:
- creates the databases `waimart` and `waimart_test` (utf8mb4);
- creates a least-privilege MySQL user `waimart`, limited to those two databases, with a random password;
- writes `config/config.local.php` with that password and a random **app key**.

Existing values are always kept.

> **Back up `config/config.local.php` and keep it secret.** The app key signs and encrypts pickup collection codes and other tokens. If it is lost, open pickup orders need new codes. See [BACKUP_RESTORE.md](BACKUP_RESTORE.md).

`config/config.php` holds the defaults; anything in `config.local.php` overrides them. The settings you may need to change:

| Key | Default | Change it when |
|---|---|---|
| `app.url` | `http://localhost/ecommerce` | The shop has a real address. Emailed links (password reset, order tracking) always use this, never the browser's Host header. |
| `app.base_path` | `/ecommerce` | The folder changes, or you serve from a domain root (`''`) |
| `app.env` / `app.debug` | installer writes `development` / `true` | Going live: `production` / `false` |
| `session.secure` | `false` | The site runs on HTTPS. Set `true`: cookies become Secure and HSTS is sent. |
| `mail.driver` | `log` | Sending real email: `smtp`, with `mail.smtp.*` and `mail.from_email` filled in. Until then, emails are written to `storage/logs/mail-YYYY-MM-DD.log`. |
| `paths.mysqldump`, `paths.mysql` | XAMPP paths | MySQL client tools live elsewhere (used by backup and restore) |

Example production `config/config.local.php` (the installer already wrote `key` and `db.password`):

```php
<?php
return [
    'app'     => ['key' => '…keep the generated value…', 'env' => 'production', 'debug' => false,
                  'url' => 'https://shop.example.co.ke', 'base_path' => ''],
    'db'      => ['password' => '…keep the generated value…'],
    'session' => ['secure' => true],
    'mail'    => ['driver' => 'smtp', 'from_email' => 'orders@example.co.ke',
                  'smtp' => ['host' => 'smtp.example.co.ke', 'port' => 587, 'username' => '…', 'password' => '…', 'encryption' => 'tls']],
];
```

## 5. Create the tables

```
php bin/migrate.php
```

This applies `database/migrations/001…024` in order and records each one in `schema_migrations`. Re-running applies only new migrations, so upgrades use the same command. Migration 004 loads the reference data: the 47 counties, roles and permissions, and default settings.

## 6. Create your administrator

```
php bin/create-admin.php --email=you@example.co.ke --first=Your --last=Name
```

A strong **one-time** password is generated and printed once. There are no default accounts or passwords anywhere in the application; this command is the only way to create the first super administrator.

1. Sign in at **/admin/login** with the one-time password.
2. You are asked to choose your own password before anything else.
3. Then open **My account** (top right) and turn on **two-step sign-in** with an authenticator app. Save the recovery codes.

Running the command again for the same email promotes that account to super admin, with a new one-time password; its other sessions are signed out.

Add further staff from **Admin → Staff**. They are emailed a link to set their own password. Give each a role (change what roles can do, or create roles, in **Admin → Roles & permissions**):

| Role | Main permissions |
|---|---|
| Super Administrator | Everything, including staff accounts and shop settings (payment numbers, reservation windows) |
| Store Manager | Orders (incl. cancel), catalogue, stock adjustments, delivery zones, pickup points, banners/flash sales, payments verification, refunds, returns, reviews, customers, reports, audit log |
| Finance | Verify/reject M-Pesa payments, COD cash records, refunds, payment resolutions, reports |
| Fulfilment Staff | Process, dispatch and deliver orders, confirm pickup collection (code check), record COD cash |
| Inventory Clerk | Catalogue, stock adjustments, return inspections |
| Customer Support | View customers and orders, cancel orders, returns, review moderation |
| Content & Marketing | Pages, menus, homepage, banners, flash sales, coupons, message wording |
| Delivery & Logistics | View orders, dispatch and delivery, pickup handover, pickup station details |

To require two-step sign-in for every staff member, tick **Settings → Security → Require two-step sign-in for all staff**.

## 7. Configure the shop (Admin)

1. **Settings** (one page per area):
   - **Branding:** name, tagline, colours, logo and icons.
   - **Contact & business, Social media, SEO.**
   - **Checkout & accounts, Orders & returns:** reservation windows (M-Pesa 30 min, COD 240 min), return window.
   - **Email & SMS:** sender name and address, reply-to, and the mail server (SMTP; the password is stored encrypted). Use **Send me a test email**. Until set, the values in `config.local.php` are used.
   - **Security, Maintenance & announcement, Marketplace.**
2. **Finance → Payment methods:**
   - enter the M-Pesa **Till**, **Paybill** and/or **Send Money** number;
   - switch methods on or off;
   - edit names, instructions and order limits.
   - A method appears at checkout only once it is on and its number is set.
3. **Fulfilment:**
   - **Delivery zones:** counties and towns, fee, days, COD.
   - **Pickup points.** For testing across Kenya, `php bin/seed-kenya-coverage.php` adds a station in the main town of every county that has none, plus a "Rest of Kenya" delivery zone (KSh 599, 3–7 days, COD) for counties no zone covers. Run it with `--dry-run` first. The new stations' addresses say "exact location to be confirmed": replace them with real addresses, or switch unused stations off, before going live.
   - **Delivery settings:** home delivery and pickup on/off, store-wide free-delivery amount, checkout notes, status names.
4. **Catalogue:** categories, brands, products, variants and opening stock (recorded as stock movements).
5. **Content & marketing:**
   - **Pages:** About, Contact, Help, policies… (they ship with sensible wording; review them, especially Terms and Privacy);
   - **Menus, Homepage, Banners, Flash sales;**
   - **Messages:** the wording of every email and SMS.

For a development or demo shop, `php bin/seed-demo.php` loads a fictional catalogue, zones, pickup points, banners and a running flash sale. It refuses to run when `app.env` is `production`.

## 8. Schedule the reservation expiry worker (required)

Unpaid or unconfirmed orders release their stock when the reservation window ends. This needs `bin/expire-reservations.php` running every minute. On Windows, run this once in an **elevated** Command Prompt:

```
schtasks /Create /TN "Waimart Reservation Expiry" /SC MINUTE /MO 1 /RU SYSTEM ^
  /TR "\"C:\xampp\htdocs\ecommerce\bin\expire-reservations.bat\""
```

Check it works with `schtasks /Run /TN "Waimart Reservation Expiry"`, then look at `storage\logs\expiry.log`. Details, the Linux cron line and the built-in fallback are in [OPERATIONS.md](OPERATIONS.md).

## 9. Schedule backups (recommended)

```
schtasks /Create /TN "Waimart Nightly Backup" /SC DAILY /ST 02:00 /RU SYSTEM ^
  /TR "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\ecommerce\bin\backup.php\""
```

Then copy `storage\backups` off the machine regularly. See [BACKUP_RESTORE.md](BACKUP_RESTORE.md).

## 10. Check the installation

| Check | Expected |
|---|---|
| http://localhost/ecommerce/health | `{"status":"ok"}` (database reachable) |
| http://localhost/ecommerce/config/config.local.php | 403 Forbidden, **never** the file |
| Home page, a category, a product | Pages load with your catalogue |
| Place a test order (pickup + COD) | Confirmation page with a private tracking link; the order appears in Admin → Orders with stock reserved |
| `storage/logs/` | Writable by Apache; `app-*.log` files appear |
| `php bin/check-integrity.php` | `All checks OK`. It reports "no active super administrator" until step 6 is done, and checks money, stock, logs and settings. |

Optionally run the automated tests (step 12).

## 11. Recommended MariaDB setting

XAMPP gives InnoDB only 16 MB of memory. For catalogues beyond a few thousand products, set `innodb_buffer_pool_size=256M` in `C:\xampp\mysql\bin\my.ini` (back it up first), then restart MySQL. See [OPERATIONS.md](OPERATIONS.md#database-memory-recommended).

## 12. Running the tests (development)

The tests never touch `waimart`: they rebuild `waimart_test` from the migrations on every run, and the bootstrap refuses any other database.

```
vendor\bin\phpunit                       # everything: ~60 s, 263 tests
vendor\bin\phpunit --testsuite Unit      # or Integration, Feature, Http
```

- **Feature** tests start PHP's built-in server on port 8089.
- **Http** tests go through Apache at http://localhost/ecommerce and are skipped when Apache is not running.

The browser checks in `tests/Browser` use Node.js and the installed Microsoft Edge:

```
cd tests\Browser
npm install
node responsive.mjs              # phone / tablet / desktop layout, no overflow or console errors
node a11y.mjs                    # WCAG 2.1 AA (axe-core): fails on serious/critical issues
node checkout-flow.mjs out       # guest checkout end to end
node submitter-check.mjs         # admin forms keep the clicked button's value
```

## Linux production server (outline)

- PHP 8.2+ with the extensions above, MariaDB 10.4+, Apache with `mod_rewrite`.
- Point the virtual host's DocumentRoot at `public/`. The root `.htaccess` is then not needed, but `public/.htaccess` is.
- Make `storage/` and `public/uploads/` writable by the web server user, and nothing else.
- In `config.local.php`, set `paths.mysqldump` / `paths.mysql` to `/usr/bin/mysqldump` and `/usr/bin/mysql`.
- Apply the production settings from step 4 (`env`, `debug`, `url`, `session.secure`, SMTP) and serve over HTTPS only.
- Schedule the expiry worker and backups with cron (see [OPERATIONS.md](OPERATIONS.md) and [BACKUP_RESTORE.md](BACKUP_RESTORE.md)).
