<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\App;

/** Cross-cutting security properties verified end to end (Phase 12). */
final class SecurityHardeningTest extends FeatureTestCase
{
    private function sessionCookie(): ?string
    {
        $jar = (string) file_get_contents((new \ReflectionProperty(FeatureTestCase::class, 'jar'))->getValue($this));
        return preg_match('/waimart_sid\s+(\S+)/', $jar, $m) ? $m[1] : null;
    }

    public function testSessionIdRotatesOnLoginAndLogout(): void
    {
        $this->db->insert('users', ['email' => 'rot@example.com', 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT), 'first_name' => 'R', 'last_name' => 'O']);
        $this->get('/login');
        $before = $this->sessionCookie();
        $this->post('/login', ['email' => 'rot@example.com', 'password' => 'Secret123'], '/login');
        $after = $this->sessionCookie();
        $this->assertNotNull($before);
        $this->assertNotSame($before, $after, 'session fixation: ID must change at sign-in');
        $this->post('/logout', [], '/account');
        $this->assertNotSame($after, $this->sessionCookie(), 'ID changes at sign-out');
    }

    public function testBackRedirectIgnoresForeignReferer(): void
    {
        // A validation failure redirects "back" — but never to another site.
        $page = $this->get('/register')['body'];
        preg_match('/name="_csrf" value="([^"]+)"/', $page, $m);
        $ch = curl_init($this->base . '/register');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_POST => true,
            CURLOPT_COOKIEFILE => (new \ReflectionProperty(FeatureTestCase::class, 'jar'))->getValue($this),
            CURLOPT_REFERER => 'https://evil.example/phish', CURLOPT_POSTFIELDS => http_build_query(['_csrf' => html_entity_decode($m[1]), 'email' => 'bad'])]);
        $raw = (string) curl_exec($ch);
        preg_match('/^Location:\s*(\S+)/mi', $raw, $loc);
        $this->assertStringNotContainsString('evil.example', $loc[1] ?? '');
        $this->assertStringStartsWith('/', $loc[1] ?? '');
    }

    public function testEmailedLinksIgnoreTheHostHeader(): void
    {
        $this->db->insert('users', ['email' => 'host@example.com', 'password_hash' => 'x', 'first_name' => 'H', 'last_name' => 'O']);
        $page = $this->get('/forgot-password')['body'];
        preg_match('/name="_csrf" value="([^"]+)"/', $page, $m);
        $ch = curl_init($this->base . '/forgot-password');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Host: evil.example'],
            CURLOPT_COOKIEFILE => (new \ReflectionProperty(FeatureTestCase::class, 'jar'))->getValue($this),
            CURLOPT_POSTFIELDS => http_build_query(['_csrf' => html_entity_decode($m[1]), 'email' => 'host@example.com'])]);
        curl_exec($ch);
        $log = (string) file_get_contents(App::root() . '/storage/logs/mail-' . date('Y-m-d') . '.log');
        $last = substr($log, (int) strrpos($log, 'host@example.com'));
        $this->assertStringContainsString((string) config('app.url'), $last, 'links use the configured site URL');
        $this->assertStringNotContainsString('evil.example', $last);
    }

    /** Audit L-1: a rate-limited response says how long to wait. */
    public function testRateLimitedResponsesSayWhenToRetry(): void
    {
        $page = $this->get('/forgot-password')['body'];
        preg_match('/name="_csrf" value="([^"]+)"/', $page, $m);
        $last = [];
        for ($i = 0; $i < 11; $i++) {      // the route allows 10 per 5 minutes
            $last = $this->http('POST', '/forgot-password', ['_csrf' => html_entity_decode($m[1]), 'login' => 'nobody@example.com', 'channel' => 'email'], [], '/forgot-password');
        }
        $this->assertSame(429, $last['status']);
        $this->assertSame('300', $last['headers']['retry-after'] ?? null);
    }

    public function testSecurityHeadersOnAdminAndStorefront(): void
    {
        foreach (['/', '/admin/login', '/product/does-not-exist'] as $path) {
            $h = $this->get($path)['headers'];
            $this->assertSame('DENY', $h['x-frame-options'] ?? null, $path);
            $this->assertArrayNotHasKey('x-powered-by', $h, "$path must not advertise the PHP version (audit A-1)");
            $this->assertSame('nosniff', $h['x-content-type-options'] ?? null, $path);
            $this->assertStringContainsString("script-src 'self'", $h['content-security-policy'] ?? '', $path);
            $this->assertStringNotContainsString("'unsafe-eval'", $h['content-security-policy'] ?? '', $path);
        }
    }

    public function testErrorsInProductionModeRevealNothing(): void
    {
        // The testing env runs with debug on (config.local.php); production must not leak details.
        $this->assertFalse((bool) (require App::root() . '/config/config.php')['app']['debug'], 'shipped default must be debug = false');
    }
}
