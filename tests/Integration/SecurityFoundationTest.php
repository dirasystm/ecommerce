<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Auth;
use Waimart\Core\Csrf;
use Waimart\Core\RateLimiter;
use Waimart\Core\Session;
use Waimart\Core\Settings;
use Waimart\Services\AuditLogger;

final class SecurityFoundationTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        Auth::reset();
    }

    public function testRateLimiterBlocksAfterMaxHitsAndClears(): void
    {
        $rl = new RateLimiter($this->db);
        for ($i = 1; $i <= 3; $i++) {
            $this->assertTrue($rl->attempt('login|1.2.3.4', 3, 60), "hit $i allowed");
        }
        $this->assertFalse($rl->attempt('login|1.2.3.4', 3, 60));
        $this->assertTrue($rl->tooMany('login|1.2.3.4', 3, 60));
        $this->assertTrue($rl->attempt('login|5.6.7.8', 3, 60), 'other keys unaffected');
        $rl->clear('login|1.2.3.4');
        $this->assertTrue($rl->attempt('login|1.2.3.4', 3, 60));
    }

    public function testRateLimiterWindowResets(): void
    {
        $rl = new RateLimiter($this->db);
        $rl->attempt('k', 1, 60);
        $this->assertFalse($rl->attempt('k', 1, 60));
        $this->db->exec('UPDATE rate_limits SET window_start = NOW() - INTERVAL 2 MINUTE');
        $this->assertTrue($rl->attempt('k', 1, 60));
    }

    public function testCsrfTokenVerification(): void
    {
        $t = Csrf::token();
        $this->assertTrue(Csrf::verify($t));
        $this->assertFalse(Csrf::verify('forged'));
        $this->assertFalse(Csrf::verify(null));
    }

    public function testFlashDataSurvivesExactlyOneRequest(): void
    {
        Session::flash('success', 'Saved');
        Session::ageFlash();                       // next request
        $this->assertSame('Saved', Session::getFlash('success'));
        Session::ageFlash();                       // request after that
        $this->assertNull(Session::getFlash('success'));
    }

    public function testPermissionsByRole(): void
    {
        $finance = $this->staff('finance');
        $support = $this->staff('support');
        $super = $this->staff('super_admin');

        $_SESSION['user_id'] = $finance;
        Auth::reset();
        $this->assertTrue(Auth::can('payments.verify'));
        $this->assertFalse(Auth::can('orders.collect'));

        $_SESSION['user_id'] = $support;
        Auth::reset();
        $this->assertFalse(Auth::can('payments.verify'));

        $_SESSION['user_id'] = $super;
        Auth::reset();
        $this->assertTrue(Auth::can('users.manage'));
        $this->assertTrue(Auth::can('settings.manage'));
    }

    public function testCustomersHaveNoStaffPermissions(): void
    {
        $id = $this->db->insert('users', ['email' => 'c@x.co', 'password_hash' => 'x', 'first_name' => 'C', 'last_name' => 'U']);
        $_SESSION['user_id'] = $id;
        Auth::reset();
        $this->assertTrue(Auth::check());
        $this->assertFalse(Auth::isStaff());
        $this->assertSame([], Auth::permissions());
    }

    public function testSuspendedUserIsLoggedOut(): void
    {
        $id = $this->db->insert('users', ['email' => 's@x.co', 'password_hash' => 'x', 'first_name' => 'S', 'last_name' => 'U', 'status' => 'suspended']);
        $_SESSION['user_id'] = $id;
        Auth::reset();
        $this->assertFalse(Auth::check());
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testAuditLogRedactsSecrets(): void
    {
        AuditLogger::log('test.action', 'order', 7, ['status' => 'a'], ['status' => 'b', 'collection_code' => 'K7M3PQ2X'], null);
        $row = $this->db->one('SELECT * FROM audit_logs ORDER BY id DESC LIMIT 1');
        $this->assertSame('test.action', $row['action']);
        $this->assertStringNotContainsString('K7M3PQ2X', $row['after_json']);
    }

    public function testReservationMinutesIsConfigurableAndClamped(): void
    {
        $this->assertSame(30, Settings::reservationMinutes());
        Settings::set('reservation_minutes', '45');
        $this->assertSame(45, Settings::reservationMinutes());
        Settings::set('reservation_minutes', '1');
        $this->assertSame(5, Settings::reservationMinutes());
        Settings::set('reservation_minutes', '30');
    }

    private function staff(string $role): int
    {
        $id = $this->db->insert('users', [
            'email' => $role . '@waimart.test', 'password_hash' => 'x', 'first_name' => 'S', 'last_name' => $role, 'is_staff' => 1,
        ]);
        $this->db->exec('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?', [$id, $role]);
        return $id;
    }
}
