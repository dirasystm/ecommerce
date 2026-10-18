# Waimart

Single-store e-commerce site for Kenya, inspired by Jumia. It supports home delivery and pickup stations, guest and registered checkout, and manual M-Pesa (phone, Till, Paybill) and cash on delivery. Stock reservation is safe under concurrent orders.

Built with PHP 8.2, MariaDB 10.4, PDO, Bootstrap 5 and vanilla JavaScript, on XAMPP.

| Document | Contents |
|---|---|
| [docs/INSTALL.md](docs/INSTALL.md) | Installation, configuration, going live, running the tests |
| [docs/OPERATIONS.md](docs/OPERATIONS.md) | Reservation expiry worker, order status rules, database tuning |
| [docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md) | Backups, restore, rebuilding on a new machine |
| [docs/ACCEPTANCE_REPORT.md](docs/ACCEPTANCE_REPORT.md) | Final acceptance criteria with test evidence |
| [docs/REQUIREMENTS_CHECKLIST.md](docs/REQUIREMENTS_CHECKLIST.md) | Requirement-by-requirement status and per-phase notes |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Code structure and design decisions |

Quick start (development):

```
composer install
php bin/install.php
php bin/migrate.php
php bin/create-admin.php --email=you@example.com
php bin/seed-demo.php          # optional demo catalogue
vendor/bin/phpunit
```
