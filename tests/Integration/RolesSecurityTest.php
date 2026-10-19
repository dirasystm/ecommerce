<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Auth;
use Waimart\Core\Crypto;
use Waimart\Core\Session;
use Waimart\Core\ValidationException;
use Waimart\Services\AccountService;
use Waimart\Services\AuthService;
use Waimart\Services\MfaService;
use Waimart\Services\RoleService;
use Waimart\Services\StaffService;

/**
 * Basemall Stage 5: roles and the permission matrix, personal permissions, no self-escalation, two-step sign-in,
 * forced password change, signing out everywhere, sign-in activity and the append-only logs.
 */
final class RolesSecurityTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        Auth::reset();
    }

    /** @param list<string> $roles */
    private function staff(string $name, array $roles, string $password = 'Secret123'): int
    {
        $id = $this->db->insert('users', ['email' => strtolower($name) . '-' . bin2hex(random_bytes(3)) . '@example.com', 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'first_name' => $name, 'last_name' => 'Staff', 'is_staff' => 1]);
        foreach ($roles as $r) {
            $this->db->exec('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?', [$id, $r]);
        }
        return $id;
    }

    private function fails(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail("expected: $needle");
        } catch (ValidationException $e) {
            $this->assertStringContainsString($needle, $e->getMessage() . ' ' . json_encode($e->errors, JSON_UNESCAPED_UNICODE));
        }
    }

    private function email(int $id): string
    {
        return (string) $this->db->value('SELECT email FROM users WHERE id = ?', [$id]);
    }

    // ------------------------------------------------------------------ roles and permissions

    public function testNewRolesAndMatrixKeepShippedPermissions(): void
    {
        $rs = new RoleService();
        $byslug = array_column($rs->roles(), null, 'slug');
        $this->assertSame(['content.manage', 'coupons.manage', 'dashboard.view', 'messages.manage'], $byslug['content_marketing']['permissions']);
        $this->assertSame(['dashboard.view', 'orders.collect', 'orders.deliver', 'orders.view', 'pickup_points.manage'], $byslug['delivery_logistics']['permissions']);
        $this->assertNotContains('roles.manage', $byslug['manager']['permissions'], 'only super admins manage roles by default');
        $this->assertSame((int) $this->db->value('SELECT COUNT(*) FROM permissions'), count($byslug['super_admin']['permissions']));
        $this->assertSame(8, count(array_filter($byslug, fn ($r) => (int) $r['is_system'] === 1)));
        $catalog = $rs->catalog();
        $this->assertSame(count($byslug['super_admin']['permissions']), array_sum(array_map('count', $catalog)), 'every permission appears once in the matrix');
        $this->assertArrayNotHasKey('Other', $catalog);
    }

    public function testRolesCannotBeUsedToEscalate(): void
    {
        $boss = $this->staff('Boss', ['super_admin']);
        $rs = new RoleService();
        // A role administrator who is not a super admin.
        $roleAdminRole = $rs->create(['name' => 'Role admin', 'permissions' => ['roles.manage', 'users.manage', 'orders.view']], $boss);
        $ra = $this->staff('Ra', ['role_admin']);
        $perms = Auth::permissionsFor($ra);
        sort($perms);
        $this->assertSame(['orders.view', 'roles.manage', 'users.manage'], $perms);

        $this->fails(fn () => $rs->create(['name' => 'Sneaky', 'permissions' => ['orders.view', 'settings.manage']], $ra), 'only give permissions you have yourself: settings.manage');
        $this->fails(fn () => $rs->update($roleAdminRole, ['name' => 'Role admin', 'permissions' => ['roles.manage', 'users.manage', 'orders.view']], $ra), 'You have this role yourself');
        $super = (int) $this->db->value("SELECT id FROM roles WHERE slug = 'super_admin'");
        $this->fails(fn () => $rs->update($super, ['name' => 'Super', 'permissions' => ['orders.view']], $boss), 'cannot be changed');
        $manager = (int) $this->db->value("SELECT id FROM roles WHERE slug = 'manager'");
        $this->fails(fn () => $rs->delete($manager, $boss), 'Built-in roles cannot be deleted');
        $this->fails(fn () => $rs->delete($roleAdminRole, $boss), '1 person has this role');

        // Giving someone a role gives its permissions: not allowed beyond your own.
        $sa = new StaffService();
        $victim = $this->staff('Vic', ['support']);
        $this->fails(fn () => $sa->updateRoles($victim, ['manager'], $ra), 'permissions you do not have yourself');
        $sa->updateRoles($victim, ['support', 'role_admin'], $boss);
        $this->fails(fn () => $sa->updateRoles($ra, ['super_admin'], $ra), 'cannot change your own roles');

        // A role the actor can fully give is fine, and edits apply to everyone with it.
        $viewer = $rs->create(['name' => 'Order viewer', 'description' => 'Read-only orders', 'permissions' => ['orders.view']], $ra);
        $sa->updateRoles($victim, ['order_viewer'], $ra);
        $this->assertSame(['orders.view'], Auth::permissionsFor($victim));
        $rs->update($viewer, ['name' => 'Order viewer', 'permissions' => ['orders.view', 'users.manage']], $ra);
        $this->assertContains('users.manage', Auth::permissionsFor($victim));
        $this->assertSame(3, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action IN ('role.created','role.updated')"));
        $this->fails(fn () => $rs->create(['name' => 'Order viewer', 'permissions' => ['orders.view']], $boss), 'already has this name');
        $this->fails(fn () => $rs->create(['name' => 'Empty', 'permissions' => []], $boss), 'at least one permission');
    }

    public function testPersonalPermissions(): void
    {
        $boss = $this->staff('Boss', ['super_admin']);
        $mgr = $this->staff('Mgr', ['manager']);
        $sa = new StaffService();
        $sa->setGrants($mgr, ['users.manage', 'settings.manage'], $boss);
        $this->assertContains('settings.manage', Auth::permissionsFor($mgr));
        $this->fails(fn () => $sa->setGrants($mgr, [], $mgr), 'cannot change your own permissions');

        // The manager (now with users.manage) can give what they hold, not more, and cannot strip what only others can change.
        $clerk = $this->staff('Clerk', ['inventory_clerk']);
        $this->fails(fn () => $sa->setGrants($clerk, ['secrets.manage'], $mgr), 'only give permissions you have yourself: secrets.manage');
        $sa->setGrants($clerk, ['orders.manage'], $mgr);
        $this->assertSame(['orders.manage'], $sa->grantsOf($clerk));
        $sa->setGrants($clerk, ['orders.manage', 'secrets.manage'], $boss);
        $sa->setGrants($clerk, [], $mgr);                       // removes only what the manager holds
        $this->assertSame(['secrets.manage'], $sa->grantsOf($clerk));
        $this->assertSame(4, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'staff.permissions_changed'"));
        // Super admins' accounts are managed by super admins only.
        $this->fails(fn () => $sa->revokeSessions($boss, $mgr), 'Only a super administrator');
        $this->fails(fn () => $sa->setGrants($boss, ['orders.view'], $mgr), 'Only a super administrator');
    }

    // ------------------------------------------------------------------ two-step sign-in

    public function testTotpMatchesTheStandard(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';   // RFC 6238 test key "12345678901234567890"
        $this->assertSame('287082', MfaService::code($secret, MfaService::currentStep(59)));
        $this->assertSame('081804', MfaService::code($secret, MfaService::currentStep(1111111109)));
        $this->assertSame('005924', MfaService::code($secret, MfaService::currentStep(1234567890)));
        $this->assertSame(MfaService::currentStep(1234567890), MfaService::matchStep($secret, '005 924', null, 1234567890));
        $this->assertSame(MfaService::currentStep(1234567890) - 1, MfaService::matchStep($secret, MfaService::code($secret, MfaService::currentStep(1234567860)), null, 1234567890), 'one step of clock drift');
        $this->assertNull(MfaService::matchStep($secret, '005924', MfaService::currentStep(1234567890), 1234567890), 'a used code is refused');
        $this->assertNull(MfaService::matchStep($secret, '12345a', null));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', MfaService::newSecret());
    }

    public function testSigningInWithTwoStepSignIn(): void
    {
        $id = $this->staff('Amina', ['manager']);
        $mfa = new MfaService();
        $secret = MfaService::newSecret();
        $this->fails(fn () => $mfa->enable($id, $secret, '000000', 'Secret123'), 'That code is not right');
        $this->fails(fn () => $mfa->enable($id, $secret, MfaService::code($secret, MfaService::currentStep()), 'wrong'), 'password is incorrect');
        $codes = $mfa->enable($id, $secret, MfaService::code($secret, MfaService::currentStep()), 'Secret123');
        $this->assertCount(10, $codes);
        $stored = (string) $this->db->value('SELECT mfa_secret FROM users WHERE id = ?', [$id]);
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertSame($secret, Crypto::decrypt($stored));

        // Password alone does not sign in, on either sign-in page.
        $auth = new AuthService();
        $u = $auth->attempt($this->email($id), 'Secret123', '127.0.0.1');
        $this->assertTrue($u['mfa_required']);
        $this->assertFalse(Auth::check());
        $this->assertSame($id, AuthService::pendingMfaUser());
        $this->fails(fn () => $auth->completeMfa('123456', '127.0.0.1'), 'That code is not right');
        $this->assertFalse(Auth::check());
        // The code used to turn it on was the current one: it cannot be replayed; wait for the next step instead.
        $this->db->exec('UPDATE users SET mfa_last_step = ? WHERE id = ?', [MfaService::currentStep() - 2, $id]);
        $auth->completeMfa(MfaService::code($secret, MfaService::currentStep()), '127.0.0.1');
        $this->assertSame($id, Auth::id());
        $this->assertNull(AuthService::pendingMfaUser());

        // Recovery codes work once each.
        Auth::logout();
        $auth->attempt($this->email($id), 'Secret123', '127.0.0.1', true);
        $auth->completeMfa(strtolower($codes[0]), '127.0.0.1');
        $this->assertSame($id, Auth::id());
        Auth::logout();
        $auth->attempt($this->email($id), 'Secret123', '127.0.0.1', true);
        $this->fails(fn () => $auth->completeMfa($codes[0], '127.0.0.1'), 'That code is not right');
        $this->assertSame(9, $mfa->unusedRecoveryCodes($id));

        // The pending step expires.
        $_SESSION['mfa_pending']['at'] = time() - 301;
        $this->fails(fn () => $auth->completeMfa(MfaService::code($secret, MfaService::currentStep()), '127.0.0.1'), 'expired');

        $events = array_column($this->db->all('SELECT event FROM auth_events WHERE user_id = ? ORDER BY id', [$id]), 'event');
        foreach (['mfa_enabled', 'mfa_challenged', 'mfa_failed', 'mfa_succeeded', 'login_succeeded', 'recovery_code_used'] as $e) {
            $this->assertContains($e, $events);
        }

        // Turning it off needs the password, and is refused when staff must use it.
        \Waimart\Core\Settings::set('staff_mfa_required', '1');
        \Waimart\Core\Settings::flush();
        try {
            $this->fails(fn () => $mfa->disable($id, 'Secret123'), 'required for staff');
        } finally {
            \Waimart\Core\Settings::set('staff_mfa_required', '0');
            \Waimart\Core\Settings::flush();
        }
        $this->fails(fn () => $mfa->disable($id, 'nope'), 'password is incorrect');
        $boss = $this->staff('Boss', ['super_admin']);
        (new StaffService())->resetMfa($id, $boss);
        $this->assertFalse(MfaService::enabled((array) $this->db->one('SELECT * FROM users WHERE id = ?', [$id])));
        $this->assertSame(0, $mfa->unusedRecoveryCodes($id));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'staff.mfa_reset'"));
    }

    public function testWrongCodesAreLimited(): void
    {
        $id = $this->staff('Juma', ['support']);
        $secret = MfaService::newSecret();
        (new MfaService())->enable($id, $secret, MfaService::code($secret, MfaService::currentStep()), 'Secret123');
        $auth = new AuthService();
        $auth->attempt($this->email($id), 'Secret123', '127.0.0.1', true);
        for ($i = 0; $i < 5; $i++) {
            $this->fails(fn () => $auth->completeMfa('000000', '127.0.0.1'), 'not right');
        }
        $this->fails(fn () => $auth->completeMfa(MfaService::code($secret, MfaService::currentStep() + 1), '127.0.0.1'), 'Too many wrong codes');
    }

    // ------------------------------------------------------------------ sessions, passwords, activity, append-only logs

    public function testSignOutEverywhereAndForcedPasswordChange(): void
    {
        $boss = $this->staff('Boss', ['super_admin']);
        $id = $this->staff('Wanjiku', ['finance']);
        (new AuthService())->attempt($this->email($id), 'Secret123', '127.0.0.1', true);
        $this->assertSame($id, Auth::id());
        Session::set('auth_at', time() - 10);                       // this session started a moment ago
        (new StaffService())->revokeSessions($id, $boss);
        Auth::reset();
        $this->assertNull(Auth::user(), 'signed out by "sign out everywhere"');

        (new StaffService())->requirePasswordChange($id, true, $boss);
        $this->assertSame(1, (int) $this->db->value('SELECT must_change_password FROM users WHERE id = ?', [$id]));
        $this->fails(fn () => (new AccountService())->changePassword($id, ['current_password' => 'Secret123', 'password' => 'Secret123', 'password_confirmation' => 'Secret123']), 'different from your current one');
        (new AccountService())->changePassword($id, ['current_password' => 'Secret123', 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456']);
        $this->assertSame(0, (int) $this->db->value('SELECT must_change_password FROM users WHERE id = ?', [$id]));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM auth_events WHERE user_id = ? AND event = 'password_changed'", [$id]));
    }

    public function testFailedSignInsAreRecordedMaskedAndLogsAreAppendOnly(): void
    {
        $id = $this->staff('Otieno', ['support']);
        $email = $this->email($id);
        $this->fails(fn () => (new AuthService())->attempt($email, 'WrongPass1', '10.0.0.7', true), 'Incorrect');
        $this->fails(fn () => (new AuthService())->attempt('nobody@example.com', 'WrongPass1', '10.0.0.7', true), 'Incorrect');
        $rows = $this->db->all("SELECT user_id, identifier, is_staff FROM auth_events WHERE event = 'login_failed' ORDER BY id");
        $this->assertCount(2, $rows);
        $this->assertSame([$id, 'ot•••@example.com', 1], [(int) $rows[0]['user_id'], $rows[0]['identifier'], (int) $rows[0]['is_staff']]);
        $this->assertSame([null, 'no•••@example.com', 1], [$rows[1]['user_id'], $rows[1]['identifier'], (int) $rows[1]['is_staff']], 'unknown accounts at the staff sign-in are recorded too');
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM auth_events WHERE CONCAT_WS('|', identifier, user_agent) LIKE '%WrongPass%'"));

        \Waimart\Services\AuditLogger::log('test.entry', 'test', 1, null, ['x' => 1], $id);
        $this->assertDbRejects(fn () => $this->db->exec("UPDATE audit_logs SET action = 'tampered'"), 'append-only');
        $this->assertDbRejects(fn () => $this->db->exec('DELETE FROM audit_logs'), 'append-only');
        $this->assertDbRejects(fn () => $this->db->exec('DELETE FROM auth_events'), 'append-only');
        $this->assertDbRejects(fn () => $this->db->exec("UPDATE auth_events SET event = 'x'"), 'append-only');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'test.entry'"));
    }
}
