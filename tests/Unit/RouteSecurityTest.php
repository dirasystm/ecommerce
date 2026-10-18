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

    public function testEverySellerCentreRouteRequiresSellerMembershipAndNeverStaff(): void
    {
        $count = 0;
        foreach ($this->routes() as $r) {
            if ($r['pattern'] === '/seller' || str_starts_with($r['pattern'], '/seller/')) {
                $count++;
                $this->assertContains('seller', $r['middleware'], "{$r['method']} {$r['pattern']} must require a seller account");
                // Seller pages must not depend on, or grant, staff permissions.
                $this->assertEmpty(array_filter($r['middleware'], fn ($m) => $m === 'staff' || str_starts_with($m, 'can:')),
                    "{$r['method']} {$r['pattern']} must not mix staff permissions into the Seller Centre");
            }
        }
        $this->assertGreaterThan(0, $count);
    }

    public function testSellerApplicationRoutesRequireSignInExceptPublicPages(): void
    {
        $public = ['/sell', '/sell/terms'];
        foreach ($this->routes() as $r) {
            if (str_starts_with($r['pattern'], '/sell') && !str_starts_with($r['pattern'], '/seller') && !in_array($r['pattern'], $public, true)) {
                $this->assertContains('auth', $r['middleware'], "{$r['method']} {$r['pattern']} must require sign-in");
            }
        }
    }

    public function testSellerProductChangesNeedOwnerOrManagerExceptStock(): void
    {
        foreach ($this->routes() as $r) {
            if ($r['method'] !== 'POST' || !str_starts_with($r['pattern'], '/seller/products') || str_ends_with($r['pattern'], '/stock')) {
                continue;
            }
            $this->assertContains('seller_role:owner,manager', $r['middleware'], "POST {$r['pattern']} must be limited to owners and managers");
        }
    }

    public function testProductModerationNeedsModeratePermission(): void
    {
        foreach ($this->routes() as $r) {
            if (str_starts_with($r['pattern'], '/admin/moderation/products')) {
                $this->assertContains('can:products.moderate', $r['middleware'], "{$r['method']} {$r['pattern']}");
            }
        }
    }

    public function testSellerDecisionsNeedManagePermission(): void
    {
        foreach ($this->routes() as $r) {
            if ($r['method'] === 'POST' && str_starts_with($r['pattern'], '/admin/sellers')) {
                $this->assertContains('can:sellers.manage', $r['middleware'], "POST {$r['pattern']} must require sellers.manage");
            }
        }
    }

    public function testMarketplaceMoneyRoutesNeedTheirPermissions(): void
    {
        $seen = 0;
        foreach ($this->routes() as $r) {
            $p = $r['pattern'];
            $need = match (true) {
                str_starts_with($p, '/admin/commissions') => 'can:commissions.manage',
                str_starts_with($p, '/admin/payouts') => $r['method'] === 'POST' ? 'can:payouts.manage' : 'can:payouts.view',
                str_ends_with($p, '/ledger') && str_starts_with($p, '/admin/') => 'can:payouts.view',
                default => null,
            };
            if ($need !== null) {
                $seen++;
                $this->assertContains($need, $r['middleware'], "{$r['method']} $p must require $need");
            }
            if ($r['method'] === 'POST' && str_starts_with($p, '/seller/finance')) {
                $seen++;
                $this->assertContains('seller_role:owner', $r['middleware'], "POST $p: only store owners move money");
                $this->assertNotEmpty(array_filter($r['middleware'], fn ($m) => str_starts_with($m, 'throttle:')), "POST $p must be rate-limited");
            }
        }
        $this->assertSame(11, $seen, 'all commission, payout, ledger and seller payout routes are covered');
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
            'POST /sell/apply', 'POST /sell/application/documents', 'POST /sell/application/submit',
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
        $verbs = '/(delete|cancel|verify|reject|toggle|pay|resend|revoke|status|default|logout|add|remove|update|approve|suspend|reactivate|submit|reopen|review)(\/|$)/';
        foreach ($this->routes() as $r) {
            if ($r['method'] === 'GET') {
                $this->assertDoesNotMatchRegularExpression($verbs, $r['pattern'], "GET {$r['pattern']} looks state-changing");
            }
        }
    }
}
