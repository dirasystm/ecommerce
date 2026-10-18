<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Auth;
use Waimart\Core\Crypto;
use Waimart\Core\ValidationException;
use Waimart\Services\AccountService;

final class AccountServiceTest extends DbTestCase
{
    private AccountService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AccountService($this->db);
        $_SESSION = [];
        Auth::reset();
    }

    private function register(string $email = 'jane@example.com'): array
    {
        return $this->svc->register([
            'first_name' => 'Jane', 'last_name' => 'Wanjiku', 'email' => $email, 'phone' => '0712 345 678',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => '1',
        ], '10.0.0.' . random_int(1, 250));
    }

    public function testRegisterNormalisesAndHashes(): void
    {
        $u = $this->register('Jane@Example.COM');
        $this->assertSame('jane@example.com', $u['email']);
        $this->assertSame('254712345678', $u['phone']);
        $this->assertTrue(password_verify('Secret123', $u['password_hash']));
        $this->assertSame(0, (int) $u['is_staff']);
    }

    public function testRegisterRejectsDuplicatesAndWeakInput(): void
    {
        $this->register();
        foreach ([
            'email' => ['email' => 'jane@example.com'],
            'password' => ['password' => 'short', 'password_confirmation' => 'short'],
            'password_confirmation' => ['password_confirmation' => 'Different1'],
            'phone' => ['phone' => '12345'],
            'accept_terms' => ['accept_terms' => ''],
        ] as $field => $override) {
            try {
                $this->svc->register($override + [
                    'first_name' => 'A', 'last_name' => 'B', 'email' => 'other@example.com', 'phone' => '0711000000',
                    'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => '1',
                ], '10.1.1.1');
                $this->fail("Expected $field error");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors);
            }
        }
    }

    public function testPasswordResetDoesNotRevealUnknownEmails(): void
    {
        $this->svc->requestPasswordReset('nobody@example.com', '1.1.1.1');
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM password_resets'));
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM notifications'));
    }

    public function testPasswordResetTokenIsHashedSingleUseAndRedactedInOutbox(): void
    {
        $u = $this->register();
        $this->svc->requestPasswordReset('jane@example.com', '1.1.1.1');
        $row = $this->db->one('SELECT * FROM password_resets WHERE user_id = ?', [$u['id']]);
        $this->assertSame(64, strlen($row['token_hash']));
        $body = (string) $this->db->value("SELECT body FROM notifications WHERE template = 'password_reset'");
        $this->assertStringContainsString('reset-password?token=[redacted]', $body, 'token never stored in the outbox');

        // Recreate a known token to exercise the reset path.
        $token = Crypto::token();
        $this->db->exec('UPDATE password_resets SET token_hash = ? WHERE id = ?', [Crypto::hash($token), $row['id']]);
        $this->assertTrue($this->svc->resetTokenValid($token));
        $this->svc->resetPassword($token, ['password' => 'NewSecret9', 'password_confirmation' => 'NewSecret9']);
        $this->assertTrue(password_verify('NewSecret9', (string) $this->db->value('SELECT password_hash FROM users WHERE id = ?', [$u['id']])));

        $this->assertFalse($this->svc->resetTokenValid($token));
        $this->expectException(ValidationException::class);
        $this->svc->resetPassword($token, ['password' => 'Another99', 'password_confirmation' => 'Another99']);
    }

    public function testExpiredAndSupersededTokensAreRejected(): void
    {
        $u = $this->register();
        $old = Crypto::token();
        $this->db->insert('password_resets', ['user_id' => $u['id'], 'token_hash' => Crypto::hash($old), 'expires_at' => date('Y-m-d H:i:s', time() - 60)]);
        $this->assertFalse($this->svc->resetTokenValid($old), 'expired');

        $first = Crypto::token();
        $this->db->insert('password_resets', ['user_id' => $u['id'], 'token_hash' => Crypto::hash($first), 'expires_at' => date('Y-m-d H:i:s', time() + 600)]);
        $this->svc->requestPasswordReset('jane@example.com', '2.2.2.2');      // issues a newer link
        $this->assertFalse($this->svc->resetTokenValid($first), 'older link is invalidated');
    }

    public function testPasswordChangeSignsOutOtherSessions(): void
    {
        $u = $this->register();
        // Another device that signed in a minute ago.
        $_SESSION = ['user_id' => $u['id'], 'auth_at' => time() - 60];
        Auth::reset();
        $this->assertTrue(Auth::check());

        $this->db->exec('UPDATE users SET password_changed_at = NOW() WHERE id = ?', [$u['id']]);
        Auth::reset();
        $this->assertFalse(Auth::check(), 'stale session rejected');

        // The device that changed the password stays signed in.
        $_SESSION = ['user_id' => $u['id'], 'auth_at' => time() - 60];
        Auth::reset();
        $this->db->exec('UPDATE users SET password_changed_at = NULL WHERE id = ?', [$u['id']]);
        $this->svc->changePassword($u['id'], ['current_password' => 'Secret123', 'password' => 'Changed99', 'password_confirmation' => 'Changed99']);
        $this->assertTrue(Auth::check());
    }

    public function testChangePasswordRequiresCurrentPassword(): void
    {
        $u = $this->register();
        $this->expectException(ValidationException::class);
        $this->svc->changePassword($u['id'], ['current_password' => 'wrong', 'password' => 'Changed99', 'password_confirmation' => 'Changed99']);
    }

    public function testEmailChangeRequiresPassword(): void
    {
        $u = $this->register();
        try {
            $this->svc->updateProfile($u['id'], ['first_name' => 'J', 'last_name' => 'W', 'email' => 'new@example.com', 'phone' => '0712345678']);
            $this->fail('Expected password requirement');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('current_password', $e->errors);
        }
        $this->svc->updateProfile($u['id'], ['first_name' => 'J', 'last_name' => 'W', 'email' => 'new@example.com', 'phone' => '0712345678', 'current_password' => 'Secret123']);
        $this->assertSame('new@example.com', $this->db->value('SELECT email FROM users WHERE id = ?', [$u['id']]));
    }

    public function testAddressesAreOwnerScopedWithSingleDefault(): void
    {
        $a = $this->register('a@example.com');
        $b = $this->register('b@example.com');
        $addr = ['recipient_name' => 'A', 'phone' => '0712345678', 'county_id' => '47', 'town' => 'Westlands', 'location' => 'Woodvale Grove'];

        $first = $this->svc->saveAddress($a['id'], null, $addr);
        $second = $this->svc->saveAddress($a['id'], null, $addr + ['is_default' => '1']);
        $this->assertSame([$second], array_map('intval', array_column($this->db->all('SELECT id FROM user_addresses WHERE user_id = ? AND is_default = 1', [$a['id']]), 'id')));

        // B cannot see, edit, default or delete A's address.
        $this->assertNull($this->svc->address($b['id'], $first));
        foreach ([
            fn () => $this->svc->saveAddress($b['id'], $first, $addr),
            fn () => $this->svc->setDefaultAddress($b['id'], $first),
            fn () => $this->svc->deleteAddress($b['id'], $first),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Cross-account access must fail');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM user_addresses WHERE user_id = ?', [$a['id']]));

        // Deleting the default promotes another address.
        $this->svc->deleteAddress($a['id'], $second);
        $this->assertSame(1, (int) $this->db->value('SELECT is_default FROM user_addresses WHERE id = ?', [$first]));
    }
}
