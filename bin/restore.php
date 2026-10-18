<?php

declare(strict_types=1);

/**
 * Restores a backup made by bin/backup.php.
 *
 * Usage:
 *   php bin/restore.php --file=storage/backups/waimart-20261018-090000.sql [--database=waimart] [--force] [--with-uploads]
 *
 *   --database      target database (default: the configured main database)
 *   --force         required when the target already has tables; they are dumped to
 *                   storage/backups/pre-restore-<db>-<stamp>.sql first, then replaced
 *   --with-uploads  also copy <backup>-files/uploads back into public/uploads (existing files with
 *                   the same name are overwritten; nothing is deleted)
 *
 * config.local.php is never overwritten automatically: if the server was rebuilt, copy it back by hand
 * from <backup>-files/ (it holds the app key that decrypts pickup collection codes).
 */

require dirname(__DIR__) . '/bootstrap.php';

use Waimart\Core\App;
use Waimart\Core\Backup;

$opts = getopt('', ['file:', 'database::', 'force', 'with-uploads']);
$file = (string) ($opts['file'] ?? '');
if ($file !== '' && !is_file($file) && is_file(App::root() . '/' . $file)) {
    $file = App::root() . '/' . $file;
}
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Usage: php bin/restore.php --file=<backup .sql> [--database=name] [--force] [--with-uploads]\n");
    exit(1);
}
$target = (string) ($opts['database'] ?? config('db.database'));
$db = App::db();

try {
    $existing = Backup::tables($db, $target);
    if ($existing) {
        if (!isset($opts['force'])) {
            fwrite(STDERR, sprintf("`%s` already has %d tables. Re-run with --force to replace them (a backup of the current data is taken first).\n", $target, count($existing)));
            exit(1);
        }
        $safety = sprintf('%s/pre-restore-%s-%s.sql', Backup::dir(), $target, date('Ymd-His'));
        Backup::dump($target, $safety);
        echo "Current data saved first: $safety\n";
        Backup::emptyDatabase($db, $target);
    }

    Backup::import($target, $file);
    $tables = Backup::tables($db, $target);
    $last = $db->value("SELECT MAX(filename) FROM `$target`.schema_migrations");
    $orders = $db->value("SELECT COUNT(*) FROM `$target`.orders");
    $products = $db->value("SELECT COUNT(*) FROM `$target`.products");
    printf("Restored %s into `%s`: %d tables, %d products, %d orders, last migration %s.\n",
        basename($file), $target, count($tables), $products, $orders, $last ?? 'none');

    if (isset($opts['with-uploads'])) {
        $n = Backup::copyTree(substr($file, 0, -4) . '-files/uploads', App::root() . '/public/uploads');
        echo "Uploads restored: $n file(s).\n";
    }
    echo "Next: php bin/migrate.php (applies any migrations newer than the backup).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Restore FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
