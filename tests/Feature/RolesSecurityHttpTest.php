<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\Settings;
use Waimart\Services\MfaService;

/** Basemall Stage 5 over HTTP: two-step sign-in on both sign-in pages, required steps, roles screens and their permissions. */
final class RolesSecurityHttpTest extends FeatureTestCase
{
    protected function tearDown(): void
    {
        Settings::set('staff_mfa_required', '0');
        parent::tearDown();
    }

    /** @return array{0:array<string,mixed>,1:string} the staff member and their authenticator secret */
    private function staffWithMfa(string $role): array
    {
        $u = $this->makeStaff($role);
        $secret = MfaService::newSecret();
        (new MfaService())->enable((int) $u['id'], $secret, MfaService::code($secret, MfaService::currentStep()), $u['password']);
        // Let the next code be accepted (the current one was used to turn it on).
        $this->db->exec('UPDATE users SET mfa_last_step = ? WHERE id = ?', [MfaService::currentStep() - 2, $u['id']]);
        return [$u, $secret];
    }

    public function testTwoStepSignInOnBothPages(): void
    {
        [$u, $secret] = $this->staffWithMfa('manager');
        $r = $this->post('/admin/login', ['login' => $u['email'], 'password' => $u['password']], '/admin/login');
        $this->assertStringEndsWith('/admin/login/two-step', (string) $r['headers']['location']);
        $this->assertStringEndsWith('/admin/login', (string) ($this->get('/admin/orders')['headers']['location'] ?? ''), 'not signed in yet');
        $bad = $this->post('/admin/login/two-step', ['code' => '000000'], '/admin/login/two-step');
        $this->assertStringContainsString('That code is not right', html_entity_decode($this->follow($bad)['body']));
        $ok = $this->post('/admin/login/two-step', ['code' => MfaService::code($secret, MfaService::currentStep())], '/admin/login/two-step');
        $this->assertStringEndsWith('/admin/orders', (string) $ok['headers']['location'], 'back to the page they asked for');
        $this->assertSame(200, $this->get('/admin/orders')['status']);

        // The customer sign-in page is not a way around the second step.
        $this->post('/logout', [], '/admin');
        $this->db->exec('UPDATE users SET mfa_last_step = ? WHERE id = ?', [MfaService::currentStep() - 2, $u['id']]);
        $r = $this->post('/login', ['login' => $u['email'], 'password' => $u['password']], '/login');
        $this->assertStringEndsWith('/admin/login/two-step', (string) $r['headers']['location']);
        $this->assertStringEndsWith('/admin/login', (string) ($this->get('/admin')['headers']['location'] ?? ''));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM auth_events WHERE user_id = ? AND event = 'mfa_failed'", [$u['id']]));
    }

    public function testRequiredStepsComeFirst(): void
    {
        $u = $this->loginStaff('finance');
        $this->db->exec('UPDATE users SET must_change_password = 1 WHERE id = ?', [$u['id']]);
        $this->assertStringEndsWith('/admin/account', (string) ($this->get('/admin/payments')['headers']['location'] ?? ''));
        $this->assertStringContainsString('Please choose a new password before you continue', $this->get('/admin/account')['body']);
        $this->post('/admin/account/password', ['current_password' => $u['password'], 'password' => 'Brand-new-9', 'password_confirmation' => 'Brand-new-9'], '/admin/account');
        $this->assertSame(200, $this->get('/admin/payments')['status'], 'free to continue, and still signed in');

        // Settings → Security: two-step sign-in required for staff.
        Settings::set('staff_mfa_required', '1');
        $this->assertStringEndsWith('/admin/account', (string) ($this->get('/admin/payments')['headers']['location'] ?? ''));
        $this->post('/admin/account/two-step/start', [], '/admin/account');
        $page = $this->get('/admin/account')['body'];
        preg_match('/aria-label="Setup key">([A-Z2-7 ]+)</', $page, $m);
        $secret = str_replace(' ', '', $m[1] ?? '');
        $this->assertSame(32, strlen($secret), 'the setup key is shown');
        $done = $this->post('/admin/account/two-step', ['code' => MfaService::code($secret, MfaService::currentStep()), 'password' => 'Brand-new-9'], '/admin/account');
        $after = $this->follow($done)['body'];
        $this->assertStringContainsString('Your recovery codes', $after);
        $this->assertSame(10, preg_match_all('/<li class="col">[A-Z0-9]{5}-[A-Z0-9]{5}<\/li>/', $after));
        $this->assertSame(200, $this->get('/admin/payments')['status']);
        $this->assertStringNotContainsString('Your recovery codes', $this->get('/admin/account')['body'], 'shown once');
    }

    public function testRolesScreensAndPermissions(): void
    {
        foreach (['manager', 'support', 'finance', 'content_marketing', 'delivery_logistics'] as $role) {
            $this->post('/logout', [], '/');
            $this->loginStaff($role);
            foreach (['/admin/roles', '/admin/roles/new', '/admin/audit/sign-ins'] as $p) {
                $expect = $p === '/admin/audit/sign-ins' && $role === 'manager' ? 200 : 403;   // managers hold audit.view
                $this->assertSame($expect, $this->get($p)['status'], "$role GET $p");
            }
            $this->assertSame(403, $this->post('/admin/roles', ['name' => 'Mine', 'permissions' => ['settings.manage']], '/admin')['status']);
            $this->assertSame(200, $this->get('/admin/account')['status'], 'everyone has their own account page');
        }
        $this->post('/logout', [], '/');
        $this->loginStaff('content_marketing');
        $this->assertSame(200, $this->get('/admin/content/pages')['status']);
        $this->assertSame(403, $this->get('/admin/orders')['status']);
        $this->post('/logout', [], '/');
        $this->loginStaff('delivery_logistics');
        $this->assertSame(200, $this->get('/admin/orders')['status']);
        $this->assertSame(403, $this->get('/admin/payments')['status']);

        // A super admin creates a role; whoever gets it can do exactly that.
        $this->post('/logout', [], '/');
        $this->loginStaff('super_admin');
        $this->assertStringContainsString('Permission matrix', $this->get('/admin/roles')['body']);
        $r = $this->post('/admin/roles', ['name' => 'Review moderator', 'description' => 'Reviews only', 'permissions' => ['reviews.moderate', 'dashboard.view']], '/admin/roles/new');
        $this->assertSame(303, $r['status']);
        $mod = $this->makeStaff('review_moderator');
        $this->post('/logout', [], '/');
        $this->post('/admin/login', ['login' => $mod['email'], 'password' => $mod['password']], '/admin/login');
        $this->assertSame(200, $this->get('/admin/reviews')['status']);
        $this->assertSame(403, $this->get('/admin/orders')['status']);
        $this->assertSame(403, $this->get('/admin/staff')['status']);
    }

    public function testSignInActivityPage(): void
    {
        $victim = $this->makeStaff('support');
        $this->post('/admin/login', ['login' => $victim['email'], 'password' => 'not-it-123'], '/admin/login');
        $this->loginStaff('super_admin');
        $page = $this->get('/admin/audit/sign-ins')['body'];
        $this->assertStringContainsString('Sign-in failed', $page);
        $this->assertStringContainsString(substr($victim['email'], 0, 2) . '•••@waimart.test', $page);
        $this->assertStringNotContainsString('not-it-123', $page);
    }
}
