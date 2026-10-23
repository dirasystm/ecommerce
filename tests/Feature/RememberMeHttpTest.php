<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Services\RememberMe;

/**
 * "Keep me signed in": a customer stays signed in after closing the browser or app, until they sign out. Signing
 * out, a password change or "sign out everywhere" ends it; staff are never remembered; a forged cookie gets nothing.
 */
final class RememberMeHttpTest extends FeatureTestCase
{
    /** Closing the browser or app: the session cookie is gone, persistent cookies stay. */
    private function closeBrowser(): void
    {
        $session = (string) config('session.name', 'waimart_sid');
        $lines = file($this->jar, FILE_IGNORE_NEW_LINES) ?: [];
        $keep = array_filter($lines, fn ($l) => !preg_match('/\t' . preg_quote($session, '/') . '\t/', $l));
        file_put_contents($this->jar, implode("\n", $keep) . "\n");
    }

    private function keepCookie(): ?string
    {
        foreach (file($this->jar, FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            $f = explode("\t", $l);
            if (count($f) === 7 && $f[5] === RememberMe::cookieName()) {
                return urldecode($f[6]);
            }
        }
        return null;
    }

    private function setKeepCookie(string $value): void
    {
        $host = parse_url($this->base, PHP_URL_HOST);
        file_put_contents($this->jar, "#HttpOnly_$host\tFALSE\t/\tFALSE\t" . (time() + 86400) . "\t" . RememberMe::cookieName() . "\t$value\n", FILE_APPEND);
    }

    private function signedIn(): bool
    {
        return $this->get('/account')['status'] === 200;
    }

    private function customer(string $email): void
    {
        $this->db->insert('users', ['email' => $email, 'phone' => '2547' . random_int(10000000, 99999999),
            'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT), 'first_name' => 'Keep', 'last_name' => 'Me']);
    }

    public function testStaysSignedInAfterClosingTheBrowserUntilSignOut(): void
    {
        $this->customer('keep@example.com');
        $this->assertStringContainsString('Keep me signed in on this device', $this->get('/login')['body']);
        $this->post('/login', ['login' => 'keep@example.com', 'password' => 'Secret123', 'remember' => '1'], '/login');
        $cookie = $this->keepCookie();
        $this->assertNotNull($cookie, 'a keep-signed-in cookie is set');
        $row = $this->db->one('SELECT * FROM remember_tokens ORDER BY id DESC LIMIT 1');
        $this->assertStringNotContainsString(explode(':', $cookie)[1], json_encode($row), 'only a hash is stored');

        $this->closeBrowser();
        $this->assertTrue($this->signedIn(), 'reopening the site: still signed in');
        $this->closeBrowser();
        $this->assertTrue($this->signedIn(), 'and again the next time');

        $this->post('/logout', [], '/account');
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM remember_tokens WHERE id = ?', [$row['id']]), 'signing out ends it');
        $this->closeBrowser();
        $this->setKeepCookie($cookie);   // even if the old cookie were still around
        $this->assertFalse($this->signedIn());
    }

    public function testUntickedMeansThisVisitOnly(): void
    {
        $this->customer('once@example.com');
        $this->post('/login', ['login' => 'once@example.com', 'password' => 'Secret123', 'remember' => '0'], '/login');
        $this->assertTrue($this->signedIn());
        $this->assertNull($this->keepCookie());
        $this->closeBrowser();
        $this->assertFalse($this->signedIn());
    }

    public function testRegistrationKeepsTheNewCustomerSignedIn(): void
    {
        $this->post('/register', ['first_name' => 'New', 'last_name' => 'Buyer', 'email' => 'newbuyer@example.com', 'phone' => '0712555111',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => '1', 'remember' => '1'], '/register');
        $this->closeBrowser();
        $this->assertTrue($this->signedIn());
    }

    public function testPasswordChangeOrSignOutEverywhereEndsIt(): void
    {
        foreach (['password_changed_at', 'sessions_revoked_at'] as $i => $col) {
            $email = "ends$i@example.com";
            $this->customer($email);
            $this->post('/login', ['login' => $email, 'password' => 'Secret123', 'remember' => '1'], '/login');
            $this->db->exec("UPDATE users SET $col = NOW() + INTERVAL 2 SECOND WHERE email = ?", [$email]);
            $this->closeBrowser();
            $this->assertFalse($this->signedIn(), "$col ends the remembered sign-in");
            $this->assertNull($this->keepCookie(), 'and the cookie is cleared');
        }
    }

    public function testForgedCookieAndStaffGetNothing(): void
    {
        $this->customer('forge@example.com');
        $this->post('/login', ['login' => 'forge@example.com', 'password' => 'Secret123', 'remember' => '1'], '/login');
        $cookie = (string) $this->keepCookie();
        $this->post('/logout', [], '/account');
        $this->customer('victim@example.com');
        $this->post('/login', ['login' => 'victim@example.com', 'password' => 'Secret123', 'remember' => '1'], '/login');
        $real = (string) $this->keepCookie();
        $this->closeBrowser();
        $this->jar = tempnam(sys_get_temp_dir(), 'wmjar');   // another device
        $this->setKeepCookie(explode(':', $real)[0] . ':' . explode(':', $cookie)[1]);   // right selector, wrong validator
        $this->assertFalse($this->signedIn(), 'a guessed or mixed-up cookie signs nobody in');
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM remember_tokens t JOIN users u ON u.id = t.user_id WHERE u.email = 'victim@example.com'"),
            'and the attacked token is withdrawn');

        $this->jar = tempnam(sys_get_temp_dir(), 'wmjar');
        $staff = $this->makeStaff('support');
        $this->post('/login', ['login' => $staff['email'], 'password' => $staff['password'], 'remember' => '1'], '/login');
        $this->assertNull($this->keepCookie(), 'staff are never remembered');
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ?', [$staff['id']]));
    }
}
