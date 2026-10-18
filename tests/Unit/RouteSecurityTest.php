<?php

declare(strict_types=1);

namespace Waimart\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Waimart\Core\Router;

/**
 * Static guarantees over the WHOLE route table, so a future route cannot silently skip
 * authentication, permissions, CSRF or rate limiting.
 */
final class RouteSecurityTest extends TestCase
{
    /** @return list<array{method:string,pattern:string,middleware:list<string>}> */
    private function routes(): array
    {
        $router = new Router();
        require dirname(__DIR__, 2) . '/routes/web.php';
        return $router->all();
    }

    public function testEveryAdminRouteRequiresStaffAndAPermission(): void
    {
        $public = ['/admin/login'];
        foreach ($this->routes() as $r) {
            if (!str_starts_with($r['pattern'], '/admin') || in_array($r['pattern'], $public, true)) {
                continue;
            }
            $this->assertContains('staff', $r['middleware'], "{$r['method']} {$r['pattern']} must require staff");
            $perms = array_filter($r['middleware'], fn ($m) => str_starts_with($m, 'can:'));
            $this->assertNotEmpty($perms, "{$r['method']} {$r['pattern']} must declare a permission");
        }
    }

    public function testEveryAccountRouteRequiresSignIn(): void
    {
        foreach ($this->routes() as $r) {
            if (str_starts_with($r['pattern'], '/account') || str_starts_with($r['pattern'], '/wishlist')) {
                $this->assertContains('auth', $r['middleware'], "{$r['method']} {$r['pattern']} must require sign-in");
            }
        }
    }

    public function testNoRouteOptsOutOfCsrf(): void
    {
        foreach ($this->routes() as $r) {
            $this->assertNotContains('no_csrf', $r['middleware'], "{$r['method']} {$r['pattern']} must not disable CSRF");
        }
    }

    public function testSensitiveEndpointsAreRateLimited(): void
    {
        $mustThrottle = [
            'POST /login', 'POST /register', 'POST /forgot-password', 'POST /reset-password', 'POST /admin/login',
            'POST /track-order', 'POST /checkout', 'POST /cart/add', 'GET /search',
        ];
        $have = [];
        foreach ($this->routes() as $r) {
            if (array_filter($r['middleware'], fn ($m) => str_starts_with($m, 'throttle:'))) {
                $have[] = $r['method'] . ' ' . $r['pattern'];
            }
        }
        foreach ($mustThrottle as $route) {
            $this->assertContains($route, $have, "$route must be rate-limited");
        }
    }

    public function testStateChangingRoutesArePostOnly(): void
    {
        $verbs = '/(delete|cancel|verify|reject|toggle|pay|resend|revoke|status|default|logout|add|remove|update)(\/|$)/';
        foreach ($this->routes() as $r) {
            if ($r['method'] === 'GET') {
                $this->assertDoesNotMatchRegularExpression($verbs, $r['pattern'], "GET {$r['pattern']} looks state-changing");
            }
        }
    }
}
