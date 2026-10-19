<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\Auth;
use Waimart\Core\Router;

/**
 * Basemall Stage 6: the role matrix, from the live route table. For every role and every admin page (and every admin
 * action without an id), the server's answer must match the role's permissions exactly: allowed pages open (never a
 * server error), everything else is 403 — including actions posted directly, which never change anything.
 */
final class AdminRoleMatrixHttpTest extends FeatureTestCase
{
    /** @return list<array{method:string,pattern:string,perms:list<string>}> admin routes without parameters */
    private function adminRoutes(): array
    {
        $router = new Router();
        require dirname(__DIR__, 2) . '/routes/web.php';
        $out = [];
        foreach ($router->all() as $r) {
            if (!str_starts_with($r['pattern'], '/admin/') && $r['pattern'] !== '/admin' || str_contains($r['pattern'], '{') || str_starts_with($r['pattern'], '/admin/login')) {
                continue;
            }
            $perms = [];
            foreach ($r['middleware'] as $mw) {
                if (str_starts_with($mw, 'can:')) {
                    $perms[] = substr($mw, 4);
                }
            }
            $out[] = ['method' => $r['method'], 'pattern' => $r['pattern'], 'perms' => $perms];
        }
        return $out;
    }

    public function testEveryRoleGetsExactlyItsPages(): void
    {
        $routes = $this->adminRoutes();
        $gets = array_values(array_filter($routes, fn ($r) => $r['method'] === 'GET'));
        $this->assertGreaterThan(50, count($gets), 'admin pages found in the route table');
        $roles = array_column($this->db->all('SELECT slug FROM roles ORDER BY id'), 'slug');
        $this->assertCount(8, $roles);
        $checked = 0;
        foreach ($roles as $role) {
            $this->post('/logout', [], '/');
            $u = $this->loginStaff($role);
            $mine = Auth::permissionsFor((int) $u['id']);
            foreach ($gets as $r) {
                $allowed = !array_diff($r['perms'], $mine);
                $status = $this->get($r['pattern'])['status'];
                if ($allowed) {
                    $this->assertContains($status, [200, 301, 302, 303], "$role GET {$r['pattern']} should open (got $status)");
                } else {
                    $this->assertSame(403, $status, "$role GET {$r['pattern']} needs " . implode(', ', $r['perms']));
                }
                $checked++;
            }
        }
        $this->assertSame(count($gets) * count($roles), $checked);
    }

    public function testActionsOutsideARoleAreRefusedAndChangeNothing(): void
    {
        $posts = array_values(array_filter($this->adminRoutes(), fn ($r) => $r['method'] === 'POST' && $r['perms']));
        $before = $this->fingerprint();
        $refused = 0;
        foreach (['support', 'fulfilment_staff', 'content_marketing', 'delivery_logistics'] as $role) {
            $this->post('/logout', [], '/');
            $u = $this->loginStaff($role);
            $mine = Auth::permissionsFor((int) $u['id']);
            foreach ($posts as $r) {
                if (!array_diff($r['perms'], $mine)) {
                    continue;
                }
                $this->assertSame(403, $this->post($r['pattern'], ['name' => 'Hacked', 'maintenance_mode' => '1', 'permissions' => ['settings.manage']], '/admin/account')['status'],
                    "$role POST {$r['pattern']}");
                $refused++;
            }
        }
        $this->assertGreaterThan(40, $refused);
        $this->assertSame($before, $this->fingerprint(), 'nothing changed');
    }

    private function fingerprint(): string
    {
        return md5(json_encode([
            $this->db->all('SELECT setting_key, setting_value FROM settings ORDER BY setting_key'),
            $this->db->all('SELECT * FROM roles ORDER BY id'), $this->db->all('SELECT * FROM role_permissions ORDER BY role_id, permission_id'),
            $this->db->all('SELECT * FROM payment_methods ORDER BY code'), $this->db->all('SELECT * FROM user_permissions'),
            $this->db->value('SELECT COUNT(*) FROM content_pages'), $this->db->value('SELECT COUNT(*) FROM message_templates'),
        ]));
    }
}
