# Waimart — Backup and Restore

## What has to be backed up

| What | Where | Why |
|---|---|---|
| The database | MariaDB `waimart` | Orders, customers, payments, stock and its full movement ledger, audit log, settings |
| Uploaded images | `public/uploads/` | Product and banner images and store logos. These are files, so they are **not** in the database dump. |
| Seller documents | `storage/private/` | Verification documents uploaded by seller applicants. Private files, never web-accessible, and **not** in the database dump. |
| Local config | `config/config.local.php` | Database password and the **app key**, which signs and encrypts pickup collection codes and other tokens. Without the same key, open pickup orders need new codes and outstanding emailed links stop working. |

`bin/backup.php` saves all three.

## Making a backup

```
php bin/backup.php              # database + uploads + config.local.php
php bin/backup.php --db-only    # database only
php bin/backup.php --keep=30    # keep the newest 30 backups (default 14)
```

Each run writes to `storage/backups/` (never web-accessible):

```
waimart-20261018-020000.sql          consistent dump (--single-transaction: no locking, shop stays open)
waimart-20261018-020000-files/
    uploads/                          copy of public/uploads
    private/                          copy of storage/private (seller documents)
    config.local.php                  copy of the local secrets
```

- **Credentials:** they're passed to `mysqldump` through a temporary file that is deleted straight away, never on the command line.
- **Retention:** older backups beyond `--keep` are deleted, each dump together with its files folder.
- **Failure:** the script exits with code 1 and leaves no partial file.

### Schedule it (Windows, nightly at 02:00)

From an elevated Command Prompt:

```
schtasks /Create /TN "Waimart Nightly Backup" /SC DAILY /ST 02:00 /RU SYSTEM ^
  /TR "\"C:\xampp\php\php.exe\" \"C:\xampp\htdocs\ecommerce\bin\backup.php\""
```

On Linux: `0 2 * * * /usr/bin/php /var/www/waimart/bin/backup.php >> /var/www/waimart/storage/logs/backup.log 2>&1`

### Keep a copy somewhere else

A backup on the same disk doesn't protect against disk failure, theft or ransomware. At least weekly, copy `storage/backups` to another place: an external drive, another computer, or encrypted cloud storage. The files folders contain `config.local.php` (passwords and the app key), so store these copies somewhere private, and encrypt them if they leave the premises.

## Restoring

`bin/restore.php` loads a backup made by `bin/backup.php`:

```
php bin/restore.php --file=storage/backups/waimart-20261018-020000.sql --force --with-uploads
php bin/migrate.php
```

| Option | Meaning |
|---|---|
| `--file` | The `.sql` dump to load (required) |
| `--database` | Target database (default: the configured main database) |
| `--force` | Required if the target already has tables. **Before anything is replaced, the current data is dumped** to `storage/backups/pre-restore-<db>-<time>.sql`, so a restore can itself be undone. |
| `--with-uploads` | Also copies `<backup>-files/uploads` back into `public/uploads` and `<backup>-files/private` back into `storage/private`. Existing files with the same name are overwritten; nothing is deleted. |

When it finishes, the script reports the number of tables, products and orders, and the last migration in the backup. `php bin/migrate.php` then applies any migrations newer than the backup (it does nothing if there are none).

`config.local.php` is **never overwritten automatically**.

### Scenario: undo a mistake on the same machine

1. Put the shop in a quiet state: stop the expiry scheduled task with `schtasks /Change /TN "Waimart Reservation Expiry" /DISABLE`, and optionally stop Apache.
2. Run the restore commands above with the backup you want.
3. Re-enable the task with `schtasks /Change /TN "Waimart Reservation Expiry" /ENABLE` and start Apache.

Orders placed after that backup are lost from the database. Check `pre-restore-…sql`, the mail log and the M-Pesa statement for anything that needs re-entering.

### Scenario: rebuild on a new machine

1. Install XAMPP and copy the code ([INSTALL.md](INSTALL.md), steps 1–3).
2. Copy `config.local.php` from the backup's `-files` folder to `config/`. This keeps the same app key.
3. Run `php bin/install.php`, which creates the databases and MySQL user and keeps the copied password and key.
4. Run `php bin/restore.php --file=<backup>.sql --with-uploads`, then `php bin/migrate.php`.
5. Recreate the scheduled tasks ([INSTALL.md](INSTALL.md), steps 8–9) and do the checks in INSTALL step 10.

### Rolling back the marketplace upgrade (migrations 010 onwards)

**Preferred:** restore the pre-upgrade backup, which is kept permanently in `C:\xampp\backups\waimart\`, using the commands above. Orders taken after the upgrade are lost from the database.

**To keep orders taken since the upgrade,** and only if no third-party seller has products or orders yet:

```
php bin/backup.php
C:\xampp\mysql\bin\mysql.exe -u root waimart < database\rollback\rollback_marketplace.sql
```

Then restore the pre-upgrade source code from `C:\xampp\backups\waimart\source-20261018-033226-pre-marketplace\`, or check out the `main` branch in Git.

The script removes only the marketplace tables, columns and reference data. If any third-party seller has products or orders, it stops at its first statement and changes nothing; use the backup in that case. It is re-tested on a copy whenever a marketplace migration is added: after migrations 010–015 and the rollback, the schema and data of all 47 tables were identical to the pre-upgrade backup.

### Test your backups

A backup you have never restored is a hope, not a backup. Once a month, restore the latest backup into the **test** database, which is disposable and rebuilt by the test suite anyway:

```
php bin/restore.php --file=storage/backups/<latest>.sql --database=waimart_test --force
```

Check the reported product and order counts look right.

## Verified during Phase 13 (2026-10-18)

- `bin/backup.php` dumped `waimart` (47 tables) and copied 57 upload files plus `config.local.php`.
- `bin/restore.php` without `--force` **refused** to touch the non-empty `waimart_test` (exit 1).
- With `--force`, it first saved `pre-restore-waimart_test-….sql`, then restored the backup.
- `CHECKSUM TABLE` on all 47 tables was **identical** between `waimart` and the restored copy.
- `tests/Integration/BackupRestoreTest.php` repeats this on every test run:
  - dump → drop all tables → import, with every table checksum compared;
  - unsafe database names and missing files rejected;
  - nested upload folders copied.
