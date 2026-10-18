<?php

declare(strict_types=1);

namespace Waimart\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;

final class RoutingAndSecurityTest extends HttpTestCase
{
    public function testHomeLoadsWithSecurityHeaders(): void
    {
        $r = $this->request('GET', '/');
        $this->assertSame(200, $r['status']);
        $this->assertStringContainsString('Waimart', $r['body']);
        $this->assertSame('nosniff', $r['headers']['x-content-type-options'] ?? null);
        $this->assertSame('DENY', $r['headers']['x-frame-options'] ?? null);
        $this->assertStringContainsString("frame-ancestors 'none'", $r['headers']['content-security-policy'] ?? '');
        $this->assertStringContainsString('HttpOnly', $r['headers']['set-cookie'] ?? '');
        $this->assertStringContainsString('SameSite=Lax', $r['headers']['set-cookie'] ?? '');
    }

    public function testHealthEndpoint(): void
    {
        $r = $this->request('GET', '/health');
        $this->assertSame(200, $r['status']);
        $this->assertSame(['status' => 'ok'], json_decode($r['body'], true));
    }

    public function testUnknownRouteIs404(): void
    {
        $r = $this->request('GET', '/definitely-not-a-page');
        $this->assertSame(404, $r['status']);
        $this->assertStringContainsString('Page not found', $r['body']);
    }

    public function testPostWithoutCsrfIsRejected(): void
    {
        $r = $this->request('POST', '/', ['x' => '1']);
        $this->assertContains($r['status'], [405, 419]);
        $this->assertNotSame(200, $r['status']);
    }

    public static function privatePaths(): array
    {
        return [
            ['/config/config.local.php'], ['/config/config.php'], ['/app/helpers.php'], ['/bootstrap.php'],
            ['/composer.json'], ['/storage/logs/'], ['/database/migrations/001_foundation.sql'], ['/vendor/autoload.php'],
            ['/.git/config'], ['/docs/ARCHITECTURE.md'], ['/bin/install.php'], ['/tests/bootstrap.php'],
            ['/README.md'], ['/storage/backups/'], ['/bin/restore.php'],
        ];
    }

    #[DataProvider('privatePaths')]
    public function testPrivateFilesAreNotServed(string $path): void
    {
        $r = $this->request('GET', $path);
        $this->assertContains($r['status'], [403, 404], "$path returned {$r['status']}");
        $this->assertStringNotContainsString('password', strtolower($r['body']));
        $this->assertStringNotContainsString('<?php', $r['body']);
    }

    public function testStaticAssetServed(): void
    {
        $r = $this->request('GET', '/assets/img/logo-full.png');
        $this->assertSame(200, $r['status']);
        $this->assertSame('image/png', $r['headers']['content-type'] ?? null);
    }

    public function testPhpInUploadsIsNeverExecuted(): void
    {
        // Even if an attacker managed to place a PHP file under uploads, Apache must not run it.
        $dir = dirname(__DIR__, 2) . '/public/uploads/products';
        @mkdir($dir, 0775, true);
        $file = $dir . '/zz-security-probe.php';
        file_put_contents($file, '<?php echo "EXECUTED-" . (6*7);');
        try {
            $r = $this->request('GET', '/uploads/products/zz-security-probe.php');
            $this->assertContains($r['status'], [403, 404]);
            $this->assertStringNotContainsString('EXECUTED-42', $r['body']);
        } finally {
            @unlink($file);
        }
    }

    public function testErrorsDoNotLeakStackTraces(): void
    {
        $r = $this->request('GET', '/%00');
        $this->assertStringNotContainsString('Stack trace', $r['body']);
        $this->assertStringNotContainsString('C:\\xampp', $r['body']);
    }
}
