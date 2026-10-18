<?php

declare(strict_types=1);

/**
 * Backup of everything needed to recover the shop, written to storage/backups:
 *   <db>-<stamp>.sql          consistent dump of the main database
 *   <db>-<stamp>-files/       uploads/ (product images, banners) and config.local.php
 *                             (DB password + app key; the key decrypts pickup collection codes)
 * Usage: php bin/backup.php [--keep=14] [--db-only]
 */

require dirname(__DIR__) . '/bootstrap.php';

use Waimart\Core\App;
use Waimart\Core\Backup;

$opts = getopt('', ['keep::', 'db-only']);
$keep = max(1, (int) ($opts['keep'] ?? 14));

$dir = Backup::dir();
$db = (string) config('db.database');
$base = sprintf('%s/%s-%s', $dir, $db, date('Ymd-His'));

try {
    Backup::dump($db, "$base.sql");
} catch (Throwable $e) {
    fwrite(STDERR, 'Backup FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
echo "Database: $base.sql (" . number_format(filesize("$base.sql")) . " bytes)\n";

if (!isset($opts['db-only'])) {
    $n = Backup::copyTree(App::root() . '/public/uploads', "$base-files/uploads");
    $local = App::root() . '/config/config.local.php';
    if (is_file($local)) {
        copy($local, "$base-files/config.local.php");
    }
    echo "Files:    $base-files/ ($n uploaded file(s)" . (is_file($local) ? ' + config.local.php' : '') . ")\n";
    echo "Keep this folder private: config.local.php contains the database password and the app key.\n";
}

// Retention: keep the newest $keep backups (dump and its files folder together).
$all = glob("$dir/$db-*.sql") ?: [];
rsort($all);
foreach (array_slice($all, $keep) as $old) {
    @unlink($old);
    Backup::removeTree(substr($old, 0, -4) . '-files');
}
