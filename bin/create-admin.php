<?php

declare(strict_types=1);

/**
 * Creates (or promotes) a super administrator.
 * Usage: php bin/create-admin.php --email=admin@example.com [--first=Admin] [--last=User] [--password=...]
 * When --password is omitted a strong random password is generated and printed once.
 */

require dirname(__DIR__) . '/bootstrap.php';

use Waimart\Core\App;
use Waimart\Core\Validator;
use Waimart\Services\AuditLogger;

$opts = getopt('', ['email:', 'first::', 'last::', 'password::']);
$email = strtolower(trim($opts['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/create-admin.php --email=admin@example.com\n");
    exit(1);
}
$password = $opts['password'] ?? null;
$generated = $password === null;
if ($generated) {
    $password = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'xy'), '=') . '7a';
}
if ($err = Validator::check(['password' => $password], ['password' => 'password'])) {
    fwrite(STDERR, $err['password'] . "\n");
    exit(1);
}

$db = App::db();
$db->transaction(function () use ($db, $email, $password, $opts) {
    $user = $db->one('SELECT id FROM users WHERE email = ?', [$email]);
    if ($user) {
        $id = (int) $user['id'];
        $db->exec('UPDATE users SET is_staff = 1, status = \'active\', password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
    } else {
        $id = $db->insert('users', [
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'first_name' => $opts['first'] ?? 'Store',
            'last_name' => $opts['last'] ?? 'Administrator',
            'is_staff' => 1,
            'email_verified_at' => date('Y-m-d H:i:s'),
        ]);
    }
    $db->exec("INSERT IGNORE INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'super_admin'", [$id]);
    AuditLogger::log('staff.super_admin_created', 'user', $id, null, ['email' => $email], null);
});

echo "Super administrator ready: $email\n";
if ($generated) {
    echo "Generated password (shown once — change it after first login): $password\n";
}
