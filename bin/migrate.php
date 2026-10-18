<?php

declare(strict_types=1);

/**
 * Usage:
 *   php bin/migrate.php                 apply pending migrations to the main database
 *   php bin/migrate.php --test          apply to the test database
 *   php bin/migrate.php --test --fresh  drop everything in the TEST database and re-migrate
 *   php bin/migrate.php --fresh --force drop the MAIN database tables (a backup is taken first)
 */

$opts = getopt('', ['test', 'fresh', 'force']);
if (isset($opts['test'])) {
    putenv('WAIMART_ENV=testing');
}

require dirname(__DIR__) . '/bootstrap.php';

use Waimart\Core\App;
use Waimart\Core\Migrator;

$db = App::db();
$name = (string) config('db.database');
$migrator = new Migrator($db, App::root() . '/database/migrations');

if (isset($opts['fresh'])) {
    if (!isset($opts['test'])) {
        if (!isset($opts['force'])) {
            fwrite(STDERR, "Refusing to wipe `$name` without --force.\n");
            exit(1);
        }
        echo "Backing up `$name` before wiping...\n";
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(App::root() . '/bin/backup.php'), $code);
        if ($code !== 0) {
            fwrite(STDERR, "Backup failed; aborting.\n");
            exit(1);
        }
    }
    echo "Dropping all tables in `$name`...\n";
    $migrator->dropAll();
}

$applied = $migrator->migrate(fn ($m) => print("$m\n"));
echo $applied ? 'Applied ' . count($applied) . " migration(s) to `$name`.\n" : "`$name` is up to date.\n";
