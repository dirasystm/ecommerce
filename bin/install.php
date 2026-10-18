<?php

declare(strict_types=1);

/**
 * One-time installer (safe to re-run):
 *   - creates databases `waimart` and `waimart_test` if missing
 *   - creates a least-privilege MySQL user `waimart`@`localhost`
 *   - writes config/config.local.php with the DB password and a random app key
 *     (existing values are kept)
 *
 * Usage: php bin/install.php [--root-user=root] [--root-password=]
 */

$root = dirname(__DIR__);
$opts = getopt('', ['root-user::', 'root-password::']);
$rootUser = $opts['root-user'] ?? 'root';
$rootPass = $opts['root-password'] ?? '';

$base = require $root . '/config/config.php';
$localFile = $root . '/config/config.local.php';
$local = is_file($localFile) ? require $localFile : [];

$dbName = $local['db']['database'] ?? $base['db']['database'];
$testName = $local['db']['test_database'] ?? $base['db']['test_database'];
$appUser = $local['db']['username'] ?? $base['db']['username'];
$appPass = $local['db']['password'] ?? '';
$isNewPassword = $appPass === '';
if ($isNewPassword) {
    $appPass = rtrim(strtr(base64_encode(random_bytes(24)), '+/', 'Ab'), '=');
}

foreach ([$dbName, $testName, $appUser] as $ident) {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $ident)) {
        fwrite(STDERR, "Unsafe identifier: $ident\n");
        exit(1);
    }
}

try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $rootUser, $rootPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "Cannot connect to MySQL as $rootUser: {$e->getMessage()}\n");
    exit(1);
}

foreach ([$dbName, $testName] as $db) {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "Database `$db` ready.\n";
}

$exists = (bool) $pdo->query("SELECT COUNT(*) FROM mysql.user WHERE user = " . $pdo->quote($appUser) . " AND host = 'localhost'")->fetchColumn();
if (!$exists) {
    $pdo->exec("CREATE USER '$appUser'@'localhost' IDENTIFIED BY " . $pdo->quote($appPass));
    $pdo->exec("CREATE USER IF NOT EXISTS '$appUser'@'127.0.0.1' IDENTIFIED BY " . $pdo->quote($appPass));
    echo "MySQL user `$appUser` created.\n";
} elseif ($isNewPassword) {
    $pdo->exec("ALTER USER '$appUser'@'localhost' IDENTIFIED BY " . $pdo->quote($appPass));
    $pdo->exec("CREATE USER IF NOT EXISTS '$appUser'@'127.0.0.1' IDENTIFIED BY " . $pdo->quote($appPass));
    $pdo->exec("ALTER USER '$appUser'@'127.0.0.1' IDENTIFIED BY " . $pdo->quote($appPass));
    echo "MySQL user `$appUser` password reset (config.local.php had none).\n";
}
foreach (['localhost', '127.0.0.1'] as $host) {
    // Least privilege: data + schema changes on our two databases only.
    foreach ([$dbName, $testName] as $db) {
        $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, LOCK TABLES
                    ON `$db`.* TO '$appUser'@'$host'");
    }
}
$pdo->exec('FLUSH PRIVILEGES');

$local['app']['key'] = $local['app']['key'] ?? base64_encode(random_bytes(32));
$local['app']['env'] = $local['app']['env'] ?? 'development';
$local['app']['debug'] = $local['app']['debug'] ?? true;
$local['db']['password'] = $appPass;

$export = "<?php\n\n// Local secrets and overrides. NOT committed to version control.\nreturn " . var_export($local, true) . ";\n";
file_put_contents($localFile, $export);
echo "Wrote config/config.local.php\n";
echo "Next: php bin/migrate.php && php bin/create-admin.php --email=you@example.com\n";
