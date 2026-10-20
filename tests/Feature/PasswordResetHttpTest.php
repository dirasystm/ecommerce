<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\App;

/**
 * Forgot password from every sign-in page: an email link or an SMS code, for customers, sellers and staff.
 * In testing, SMS and email are written to the mail log, where the code and link are read from.
 */
final class PasswordResetHttpTest extends FeatureTestCase
{
    private function user(string $email, string $phone, bool $staff = false): int
    {
        $id = $this->db->insert('users', ['email' => $email, 'phone' => $phone, 'password_hash' => password_hash('OldPass123', PASSWORD_DEFAULT),
            'first_name' => 'Amina', 'last_name' => 'Otieno', 'is_staff' => $staff ? 1 : 0]);
        if ($staff) {
            $this->db->exec("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'support'", [$id]);
        }
        return $id;
    }

    private function lastSmsCode(string $phone): ?string
    {
        $log = (string) @file_get_contents(App::root() . '/storage/logs/mail-' . date('Y-m-d') . '.log');
        $pos = strrpos($log, "SMS to $phone ====");
        return $pos !== false && preg_match('/reset code is (\d{6})/', substr($log, $pos), $m) ? $m[1] : null;
    }

    private function freshJar(): void
    {
        (new \ReflectionProperty(FeatureTestCase::class, 'jar'))->setValue($this, tempnam(sys_get_temp_dir(), 'wmjar'));
    }

    public function testEverySignInPageOffersPasswordReset(): void
    {
        $this->assertStringContainsString('href="/forgot-password"', $this->get('/login')['body']);
        $this->assertStringContainsString('href="/forgot-password?staff=1"', $this->get('/admin/login')['body']);

        $page = $this->get('/forgot-password')['body'];
        foreach (['Email or phone number', 'An email with a reset link', 'An SMS with a 6-digit code', 'Back to sign in'] as $text) {
            $this->assertStringContainsString($text, $page);
        }
        $this->assertStringContainsString('Back to staff sign in', $this->get('/forgot-password?staff=1')['body']);
    }

    public function testResetBySmsCodeWithAttemptLimitAndNoAccountEnumeration(): void
    {
        $this->user('amina@example.com', '254722000111');

        $known = $this->post('/forgot-password', ['login' => '0722 000 111', 'channel' => 'sms'], '/forgot-password');
        $this->assertSame('/forgot-password/code', $known['headers']['location']);
        $codePage = $this->follow($known)['body'];
        $this->assertStringContainsString('we have sent a 6-digit code', $codePage);
        $code = $this->lastSmsCode('254722000111');
        $this->assertNotNull($code, 'the code went out by SMS');
        $this->assertStringNotContainsString($code, (string) $this->db->value("SELECT GROUP_CONCAT(body) FROM notifications WHERE template = 'password_reset_code'"), 'hidden in the message log');

        // A wrong code is refused; the right one opens "set a new password".
        $wrong = $this->follow($this->post('/forgot-password/code', ['code' => $code === '111111' ? '222222' : '111111'], '/forgot-password/code'))['body'];
        $this->assertStringContainsString('That code is not right, or it has expired', $wrong);
        $ok = $this->post('/forgot-password/code', ['code' => substr($code, 0, 3) . ' ' . substr($code, 3)], '/forgot-password/code');
        $this->assertMatchesRegularExpression('#^/reset-password\?token=[A-Za-z0-9_-]{43}$#', $ok['headers']['location']);
        $token = substr($ok['headers']['location'], strlen('/reset-password?token='));
        $this->assertStringContainsString('Set a new password', $this->get('/reset-password?token=' . $token)['body']);
        $r = $this->post('/reset-password', ['token' => $token, 'password' => 'NewPass456', 'password_confirmation' => 'NewPass456'], '/reset-password?token=' . $token);
        $this->assertSame('/login', $r['headers']['location']);
        $this->assertStringContainsString('invalid, has already been used', $this->get('/reset-password?token=' . $token)['body'], 'single use');
        $this->assertSame(303, $this->post('/login', ['login' => '0722000111', 'password' => 'NewPass456'], '/login')['status']);
        $this->assertSame(200, $this->get('/account')['status'], 'signed in with the new password');
        $this->assertSame(['password_reset_code_sent', 'password_reset_code_failed', 'password_reset'],
            array_values(array_intersect(array_column($this->db->all('SELECT event FROM auth_events ORDER BY id'), 'event'), ['password_reset_code_sent', 'password_reset_code_failed', 'password_reset'])));

        // Five wrong codes and even the right one stops working.
        $this->post('/logout', [], '/account');
        $this->freshJar();
        $this->post('/forgot-password', ['login' => 'amina@example.com', 'channel' => 'sms'], '/forgot-password');
        $code = (string) $this->lastSmsCode('254722000111');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password/code', ['code' => sprintf('%06d', ((int) $code + 1 + $i) % 1000000)], '/forgot-password/code');
        }
        $locked = $this->follow($this->post('/forgot-password/code', ['code' => $code], '/forgot-password/code'))['body'];
        $this->assertStringContainsString('That code is not right, or it has expired', $locked);

        // An unknown number gets exactly the same journey, and no code works.
        $this->freshJar();
        $unknown = $this->post('/forgot-password', ['login' => '0799 999 999', 'channel' => 'sms'], '/forgot-password');
        $this->assertSame('/forgot-password/code', $unknown['headers']['location']);
        $this->assertStringContainsString('we have sent a 6-digit code', $this->follow($unknown)['body']);
        $this->assertNull($this->lastSmsCode('254799999999'));
        $this->assertStringContainsString('That code is not right', $this->follow($this->post('/forgot-password/code', ['code' => '123456'], '/forgot-password/code'))['body']);

        // The code page needs a request first; bad input is explained.
        $this->freshJar();
        $this->assertSame('/forgot-password', $this->get('/forgot-password/code')['headers']['location']);
        $bad = $this->follow($this->post('/forgot-password', ['login' => 'not-a-phone', 'channel' => 'sms'], '/forgot-password'))['body'];
        $this->assertStringContainsString('Enter the email address or phone number on your account', $bad);
    }

    public function testEmailLinkByPhoneNumberAndStaffReturnToStaffSignIn(): void
    {
        // A customer can type their phone and still choose the email link.
        $this->user('link@example.com', '254733000222');
        $r = $this->post('/forgot-password', ['login' => '0733000222', 'channel' => 'email'], '/forgot-password');
        $this->assertSame('/login', $r['headers']['location']);
        $log = (string) file_get_contents(App::root() . '/storage/logs/mail-' . date('Y-m-d') . '.log');
        $this->assertStringContainsString('EMAIL to link@example.com', $log);

        // Staff start from the staff sign-in page and end back there.
        $this->freshJar();
        $this->user('officer@example.com', '254744000333', true);
        $this->get('/forgot-password?staff=1');
        $r = $this->post('/forgot-password', ['login' => '0744000333', 'channel' => 'sms'], '/forgot-password?staff=1');
        $code = (string) $this->lastSmsCode('254744000333');
        $ok = $this->post('/forgot-password/code', ['code' => $code], '/forgot-password/code');
        $token = substr($ok['headers']['location'], strlen('/reset-password?token='));
        $done = $this->post('/reset-password', ['token' => $token, 'password' => 'StaffPass789', 'password_confirmation' => 'StaffPass789'], '/reset-password?token=' . $token);
        $this->assertSame('/admin/login', $done['headers']['location']);
        $this->assertSame(303, $this->post('/admin/login', ['login' => 'officer@example.com', 'password' => 'StaffPass789'], '/admin/login')['status']);
    }
}
