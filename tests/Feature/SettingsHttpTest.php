<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\Settings;
use Waimart\Services\SettingsService;

/** Basemall Stage 2 over HTTP: settings pages and permissions, and each setting's effect on the shop. */
final class SettingsHttpTest extends FeatureTestCase
{
    /** @var array<string,string> */
    private array $snapshot = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshot = array_column($this->db->all('SELECT setting_key, setting_value FROM settings'), 'setting_value', 'setting_key');
    }

    protected function tearDown(): void
    {
        foreach ($this->snapshot as $k => $v) {
            Settings::set($k, (string) $v);
        }
        parent::tearDown();
    }

    public function testEveryPageNeedsSettingsManage(): void
    {
        $paths = array_merge(array_map(fn ($g) => "/admin/settings/$g", array_keys(SettingsService::GROUPS)), ['/admin/settings/transfer', '/admin/settings/export']);
        foreach (['manager', 'support', 'finance'] as $role) {
            $this->post('/logout', [], '/');
            $this->loginStaff($role);
            foreach ($paths as $p) {
                $this->assertSame(403, $this->get($p)['status'], "$role GET $p");
            }
            $this->assertSame(403, $this->post('/admin/settings/maintenance', ['maintenance_mode' => '1'], '/admin')['status'], "$role cannot close the shop");
        }
        $this->assertSame('0', (string) Settings::get('maintenance_mode'));

        $this->post('/logout', [], '/');
        $this->loginStaff('super_admin');
        $this->assertStringEndsWith('/admin/settings/general', (string) ($this->get('/admin/settings')['headers']['location'] ?? ''));
        foreach ($paths as $p) {
            $group = substr($p, strlen('/admin/settings/'));
            if (isset(SettingsService::PAGES[$group])) {   // edited on its module page (payment methods, delivery settings)
                $this->assertStringEndsWith(SettingsService::PAGES[$group], (string) ($this->get($p)['headers']['location'] ?? ''), $p);
                continue;
            }
            $this->assertSame(200, $this->get($p)['status'], $p);
        }
        $this->assertSame(404, $this->get('/admin/settings/nonsense')['status']);
    }

    public function testContactSocialAndSeoSettingsShowOnTheShop(): void
    {
        $this->loginStaff('super_admin');
        $this->assertSame(303, $this->post('/admin/settings/contact', ['store_phone' => '0700 123 456', 'store_email' => 'care@basemall.test',
            'whatsapp_number' => '0712 345 678', 'business_address' => "Moi Avenue\nNairobi", 'business_hours' => 'Mon–Sat, 8 am – 6 pm', 'legal_name' => 'Basemall Ltd'], '/admin/settings/contact')['status']);
        $this->post('/admin/settings/social', ['social_instagram' => 'https://instagram.com/basemall'], '/admin/settings/social');
        $this->post('/admin/settings/seo', ['seo_title' => 'Basemall: shop everything', 'seo_description' => 'Phones, fashion and groceries delivered across Kenya.'], '/admin/settings/seo');

        $home = $this->get('/')['body'];
        $this->assertStringContainsString('<title>Basemall: shop everything</title>', $home);
        $this->assertStringContainsString('content="Phones, fashion and groceries delivered across Kenya."', $home);
        $this->assertStringNotContainsString('name="robots"', $home);
        $this->assertStringContainsString('href="https://wa.me/254712345678"', $home);
        $this->assertStringContainsString('href="https://instagram.com/basemall"', $home);
        $this->assertStringContainsString('&copy; ' . date('Y') . ' Basemall Ltd.', $home);
        $contact = $this->get('/contact')['body'];
        foreach (['0700 123 456', 'care@basemall.test', 'Moi Avenue<br />', 'Mon–Sat, 8 am – 6 pm'] as $t) {
            $this->assertStringContainsString($t, $contact);
        }

        // A bad value is refused with a message and nothing changes.
        $bad = $this->post('/admin/settings/social', ['social_instagram' => 'javascript:alert(1)'], '/admin/settings/social');
        $this->assertStringContainsString('Instagram must be a valid http(s) URL', html_entity_decode($this->follow($bad)['body']));
        $bad = $this->post('/admin/settings/social', ['social_instagram' => 'http://instagram.com/basemall'], '/admin/settings/social');
        $this->assertStringContainsString('Instagram must start with https://', html_entity_decode($this->follow($bad)['body']));
        Settings::flush();
        $this->assertSame('https://instagram.com/basemall', Settings::get('social_instagram'), 'unchanged after refusals');
        $this->post('/admin/settings/seo', ['seo_noindex' => '1'], '/admin/settings/seo');
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $this->get('/')['body']);
    }

    public function testMaintenanceModeAndAnnouncement(): void
    {
        $this->loginStaff('super_admin');
        $this->post('/admin/settings/maintenance', ['maintenance_mode' => '1', 'maintenance_message' => 'Back at 10 am with new deals.',
            'announcement_enabled' => '1', 'announcement_text' => 'Free pickup this week', 'announcement_link' => '/flash-sales'], '/admin/settings/maintenance');
        $staffView = $this->get('/')['body'];
        $this->assertStringContainsString('Maintenance mode is on', $staffView, 'staff see the shop, with a reminder');
        $this->assertStringContainsString('Free pickup this week', $staffView);
        $this->assertStringContainsString('href="/flash-sales"', $staffView);

        $this->post('/logout', [], '/');
        foreach (['/', '/cart', '/category/x', '/track-order', '/register'] as $p) {
            $r = $this->get($p);
            $this->assertSame(503, $r['status'], "customers: $p");
            $this->assertStringContainsString('Back at 10 am with new deals.', $r['body']);
            $this->assertSame('3600', (string) ($r['headers']['retry-after'] ?? ''));
        }
        $this->assertSame(503, $this->post('/cart/add', ['variant_id' => '1', 'qty' => '1'], '/login')['status'], 'nothing can be bought');
        foreach (['/login', '/admin/login', '/health', '/manifest.webmanifest'] as $p) {
            $this->assertNotSame(503, $this->get($p)['status'], "stays open: $p");
        }
        $seller = $this->db->insert('sellers', ['name' => 'Kamba Crafts', 'slug' => 'kamba-crafts']);
        $u = $this->db->insert('users', ['email' => 'kamba@example.com', 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT), 'first_name' => 'K', 'last_name' => 'C']);
        $this->db->insert('seller_users', ['seller_id' => $seller, 'user_id' => $u, 'role' => 'owner']);
        $this->post('/login', ['login' => 'kamba@example.com', 'password' => 'Secret123'], '/login');
        $this->assertSame(200, $this->get('/seller')['status'], 'sellers keep working during maintenance');

        Settings::set('maintenance_mode', '0');
        $this->assertSame(200, $this->get('/')['status']);
    }

    public function testClosedRegistration(): void
    {
        $this->loginStaff('super_admin');
        $this->post('/admin/settings/checkout', ['guest_email_required' => '1'], '/admin/settings/checkout');   // allow_registration unticked
        $this->post('/logout', [], '/');
        $page = $this->get('/register')['body'];
        $this->assertStringContainsString('New accounts cannot be created', $page);
        $this->assertStringNotContainsString('name="password_confirmation"', $page);
        $this->assertStringNotContainsString('href="/register"', $this->get('/login')['body'], 'no link to a closed page');
        $r = $this->post('/register', ['first_name' => 'A', 'last_name' => 'B', 'email' => 'closed@example.com', 'phone' => '0711 222 333',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => '1'], '/login');
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM users WHERE email = 'closed@example.com'"), 'server refuses even a crafted request');
        $this->assertStringContainsString('cannot be created', $this->follow($r)['body']);
    }

    public function testExportAndImport(): void
    {
        $this->loginStaff('super_admin');
        $exp = $this->get('/admin/settings/export');
        $this->assertStringContainsString('application/json', (string) ($exp['headers']['content-type'] ?? ''));
        $this->assertStringContainsString('attachment; filename="basemall-settings-', (string) ($exp['headers']['content-disposition'] ?? ''));
        $data = json_decode($exp['body'], true);
        $this->assertArrayNotHasKey('mpesa_till_number', $data['settings']);

        $data['settings']['products_per_page'] = '48';
        $file = tempnam(sys_get_temp_dir(), 'set') . '.json';
        file_put_contents($file, json_encode($data));
        $r = $this->post('/admin/settings/import', [], '/admin/settings/transfer', ['file' => $file]);
        @unlink($file);
        $this->assertSame(303, $r['status']);
        $this->assertStringContainsString('Settings imported: 1 changed.', $this->follow($r)['body']);
        Settings::flush();
        $this->assertSame('48', Settings::get('products_per_page'));
    }
}
