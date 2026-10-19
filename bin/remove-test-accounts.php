<?php

declare(strict_types=1);

/**
 * Disables the acceptance-testing accounts (every account with an @basemall.test address) before going live:
 * staff access, roles and extra permissions are removed, the accounts are suspended and signed out everywhere, their
 * passwords are replaced with unusable ones, and the test seller store is closed. Nothing is deleted, so orders,
 * the audit log and the sign-in history made during testing stay intact and consistent.
 * Usage: php bin/remove-test-accounts.php --confirm
 */

require dirname(__DIR__) . '/bootstrap.php';

use Waimart\Core\App;
use Waimart\Services\AuditLogger;

if (!in_array('--confirm', $argv, true)) {
    fwrite(STDERR, "Disables every @basemall.test account and closes the test store. Run with --confirm.\n");
    exit(1);
}
$db = App::db();
$ids = array_map('intval', array_column($db->all("SELECT id FROM users WHERE email LIKE '%@basemall.test'"), 'id'));
if (!$ids) {
    echo "No test accounts found.\n";
    exit(0);
}
$in = implode(',', $ids);
$db->transaction(function () use ($db, $ids, $in) {
    $db->exec("DELETE FROM user_roles WHERE user_id IN ($in)");
    $db->exec("DELETE FROM user_permissions WHERE user_id IN ($in)");
    foreach ($ids as $id) {
        $db->exec("UPDATE users SET is_staff = 0, status = 'suspended', sessions_revoked_at = NOW(), mfa_secret = NULL, mfa_enabled_at = NULL, password_hash = ? WHERE id = ?",
            [password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $id]);
        AuditLogger::log('staff.test_account_disabled', 'user', $id, null, ['reason' => 'test accounts removed before go-live'], null);
    }
    $db->exec("UPDATE sellers s SET s.status = 'closed', s.suspension_reason = 'Test store closed before go-live'
                WHERE s.is_platform = 0 AND EXISTS (SELECT 1 FROM seller_users su WHERE su.seller_id = s.id AND su.user_id IN ($in) AND su.role = 'owner')");
});
echo count($ids) . " test account(s) disabled and the test store closed.\n";
