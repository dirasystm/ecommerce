# Waimart — Installation

This guide installs Waimart on **Windows with XAMPP**, the environment it was built and tested on. A section at the end covers a Linux production server.

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

This applies `database/migrations/001…009` in order and records each one in `schema_migrations`. Re-running applies only new migrations, so upgrades use the same command. Migration 004 loads the reference data: the 47 counties, roles and permissions, and default settings.

## 6. Create your administrator

```
php bin/create-admin.php --email=you@example.co.ke --first=Your --last=Name
```

A strong password is generated and **printed once**. Sign in at **/admin/login**, then change it at **/account/password**. Running the command again for the same email promotes that user to super admin. Add further staff from **Admin → Staff**, giving each a role:

| Role | Main permissions |
|---|---|
| Super Administrator | Everything, including staff accounts and shop settings (payment numbers, reservation windows) |
| Store Manager | Orders (incl. cancel), catalogue, stock adjustments, delivery zones, pickup points, banners/flash sales, payments verification, refunds, returns, reviews, customers, reports, audit log |
| Finance | Verify/reject M-Pesa payments, COD cash records, refunds, payment resolutions, reports |
| Fulfilment Staff | Process, dispatch and deliver orders, confirm pickup collection (code check), record COD cash |
| Inventory Clerk | Catalogue, stock adjustments, return inspections |
| Customer Support | View customers and orders, cancel orders, returns, review moderation |

## 7. Configure the shop (Admin)

1. **Settings:**
   - M-Pesa **Till number**, **Paybill + account format** and/or the **phone number** customers pay to. A method appears at checkout only once its details are set.
   - Reservation windows: `reservation_minutes` (M-Pesa, default 30) and `reservation_minutes_cod` (COD, default 240).
   - Return window.
2. **Delivery zones:** counties/towns, fee, delivery days, and whether COD is allowed.
3. **Pickup points:** code, address, opening hours, active/inactive. Inactive points can't be chosen at checkout.
4. **Catalogue:** categories, brands, products, variants and opening stock (recorded as stock movements).
5. Optional: banners and flash sales.

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
