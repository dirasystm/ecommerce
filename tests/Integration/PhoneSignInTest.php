<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Auth;
use Waimart\Core\ValidationException;
use Waimart\Services\AccountService;
use Waimart\Services\AuthService;

/** Sign-in with email or phone number: identification, rate limits, uniqueness and account changes. */
final class PhoneSignInTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        Auth::reset();
    }

    private function user(string $email, ?string $phone, bool $staff = false, string $status = 'active'): int
    {
        return $this->db->insert('users', ['email' => $email, 'phone' => $phone, 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT),
            'first_name' => 'Wanjiru', 'last_name' => 'Kamau', 'is_staff' => $staff ? 1 : 0, 'status' => $status]);
    }

    private function fails(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail("expected: $needle");
        } catch (ValidationException $e) {
            $this->assertStringContainsString($needle, $e->getMessage() . json_encode($e->errors));
        }
    }

    public function testIdentifierIsRecognisedInEveryCommonForm(): void
    {
        $this->assertSame(['email', 'jane@example.com'], AuthService::identify('  Jane@Example.COM '));
        foreach (['0712345678', '0712 345 678', '+254 712 345 678', '254712345678', '712345678'] as $p) {
            $this->assertSame(['phone', '254712345678'], AuthService::identify($p), $p);
        }
        $this->assertSame([null, 'jane'], AuthService::identify('jane'));
        $this->assertSame([null, ''], AuthService::identify(''));
        $this->assertSame([null, "0712' or 1=1 --"], AuthService::identify("0712' OR 1=1 --"), 'not a phone, not an email: no lookup at all');
    }

    public function testSignInWithEmailOrPhone(): void
    {
        $id = $this->user('wanjiru@example.com', '254712345678');
        $auth = new AuthService();
        foreach (['wanjiru@example.com', 'WANJIRU@example.com', '0712 345 678', '+254712345678', '254712345678'] as $login) {
            $_SESSION = [];
            Auth::reset();
            $this->assertSame($id, (int) $auth->attempt($login, 'Secret123', '10.0.0.' . random_int(1, 250))['id'], $login);
            $this->assertSame($id, Auth::id());
        }
        $this->fails(fn () => $auth->attempt('0712 345 678', 'wrong-pass1', '10.0.1.1'), AuthService::GENERIC_ERROR);
        $this->fails(fn () => $auth->attempt('0799 999 999', 'Secret123', '10.0.1.2'), AuthService::GENERIC_ERROR);
        $this->fails(fn () => $auth->attempt('not-an-identifier', 'Secret123', '10.0.1.3'), AuthService::GENERIC_ERROR);
    }

    public function testEveryFormOfOnePhoneShareOneAttemptLimit(): void
    {
        $this->user('wanjiru@example.com', '254712345678');
        $auth = new AuthService();
        $forms = ['0712345678', '+254712345678', '254712345678', '0712 345 678', '712345678'];
        foreach ($forms as $i => $f) {
            $this->fails(fn () => $auth->attempt($f, 'wrong-pass1', "10.0.2.$i"), AuthService::GENERIC_ERROR);
        }
        // Five wrong passwords across five spellings: that phone is locked for 15 minutes, even with the right password.
        $this->fails(fn () => $auth->attempt('0712345678', 'Secret123', '10.0.2.9'), 'Too many sign-in attempts');
        // (The email has its own counter; every attempt also counts towards the per-network limit of 30 per 15 minutes.)
    }

    public function testThePerNetworkLimitCoversEmailAndPhoneTogether(): void
    {
        $this->user('wanjiru@example.com', '254712345678');
        $auth = new AuthService();
        for ($i = 0; $i < 30; $i++) {
            try {
                $auth->attempt($i % 2 ? 'nobody' . $i . '@example.com' : '07' . sprintf('%08d', 20000000 + $i), 'wrong-pass1', '10.0.9.9');
            } catch (ValidationException) {
            }
        }
        $this->fails(fn () => $auth->attempt('wanjiru@example.com', 'Secret123', '10.0.9.9'), 'Too many sign-in attempts');
    }

    public function testStaffSignInByPhoneAndBlockedAccounts(): void
    {
        $staff = $this->user('ops@example.com', '254733000111', true);
        $this->user('shopper@example.com', '254733000222');
        $this->user('suspended@example.com', '254733000333', false, 'suspended');
        $auth = new AuthService();
        $this->assertSame($staff, (int) $auth->attempt('0733 000 111', 'Secret123', '10.0.3.1', true)['id']);
        $this->fails(fn () => $auth->attempt('0733 000 222', 'Secret123', '10.0.3.2', true), AuthService::GENERIC_ERROR);   // customer at the staff login
        $this->fails(fn () => $auth->attempt('0733 000 333', 'Secret123', '10.0.3.3'), AuthService::GENERIC_ERROR);         // suspended
    }

    public function testPhoneNumbersAreUniqueAndProtected(): void
    {
        $svc = new AccountService($this->db);
        $reg = fn (string $email, string $phone) => $svc->register(['first_name' => 'A', 'last_name' => 'B', 'email' => $email, 'phone' => $phone,
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => '1'], '10.0.4.' . random_int(1, 250));
        $a = $reg('a@example.com', '0712 345 678');
        $this->fails(fn () => $reg('b@example.com', '+254712345678'), 'phone number already exists');
        $this->assertDbRejects(fn () => $this->db->insert('users', ['email' => 'c@example.com', 'phone' => '254712345678', 'password_hash' => 'x', 'first_name' => 'C', 'last_name' => 'D']), 'uq_users_phone');
        $this->db->insert('users', ['email' => 'nophone1@example.com', 'phone' => null, 'password_hash' => 'x', 'first_name' => 'N', 'last_name' => 'P']);
        $this->db->insert('users', ['email' => 'nophone2@example.com', 'phone' => null, 'password_hash' => 'x', 'first_name' => 'N', 'last_name' => 'P']);   // many without a phone is fine

        $b = $reg('b@example.com', '0722 000 111');
        // Changing the phone (a sign-in identifier) needs the current password, and must not take someone else's.
        $this->fails(fn () => $svc->updateProfile((int) $b['id'], ['first_name' => 'A', 'last_name' => 'B', 'email' => 'b@example.com', 'phone' => '0722 000 999']), 'current password');
        $this->fails(fn () => $svc->updateProfile((int) $b['id'], ['first_name' => 'A', 'last_name' => 'B', 'email' => 'b@example.com', 'phone' => '0712 345 678', 'current_password' => 'Secret123']), 'used by another account');
        $svc->updateProfile((int) $b['id'], ['first_name' => 'A', 'last_name' => 'B', 'email' => 'b@example.com', 'phone' => '0722 000 999', 'current_password' => 'Secret123']);
        $this->assertSame('254722000999', $this->db->value('SELECT phone FROM users WHERE id = ?', [$b['id']]));
        $svc->updateProfile((int) $a['id'], ['first_name' => 'Renamed', 'last_name' => 'B', 'email' => 'a@example.com', 'phone' => '0712345678']);   // same phone, other spelling: no password needed
        $this->assertSame('Renamed', $this->db->value('SELECT first_name FROM users WHERE id = ?', [$a['id']]));
    }
}
