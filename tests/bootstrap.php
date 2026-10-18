<?php

declare(strict_types=1);

/**
 * Test bootstrap: always targets the waimart_test database and rebuilds it
 * from the migrations once per run so tests start from a known schema.
 */

putenv('WAIMART_ENV=testing');
$_ENV['WAIMART_ENV'] = 'testing';

require dirname(__DIR__) . '/bootstrap.php';

use Waimart\Core\App;
use Waimart\Core\Migrator;

if (config('db.database') !== config('db.test_database')) {
    fwrite(STDERR, "Refusing to run tests against a non-test database.\n");
    exit(1);
}

// Fail fast instead of hanging if something holds a lock (server defaults: 50 s row locks,
// 86 400 s metadata locks). Concurrency tests only ever wait milliseconds for each other.
App::db()->pdo()->exec('SET SESSION lock_wait_timeout = 20');
App::db()->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 15');

$migrator = new Migrator(App::db(), App::root() . '/database/migrations');
$migrator->dropAll();
$migrator->migrate();
