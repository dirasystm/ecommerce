<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\Settings;

/** Basemall Stage 1 over HTTP: the Branding page, its permission, live effect on the shop, and no old branding left. */
final class BrandingHttpTest extends FeatureTestCase
{
    /** @var array<string,?string> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['store_name', 'store_tagline', 'brand_primary', 'brand_secondary', 'order_number_prefix'] as $k) {
            $this->saved[$k] = Settings::get($k);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            Settings::set($k, (string) $v);
        }
        parent::tearDown();
    }

    public function testOnlySettingsManagersCanChangeBranding(): void
    {
        foreach (['manager', 'support', 'finance', 'inventory_clerk', 'fulfilment_staff'] as $role) {
            $this->post('/logout', [], '/');
            $this->loginStaff($role);
            $this->assertSame(403, $this->get('/admin/settings/branding')['status'], "$role cannot open Branding");
            $this->assertSame(403, $this->post('/admin/settings/branding', ['store_name' => 'Hijacked', 'store_tagline' => '', 'order_number_prefix' => 'HJ',
                'brand_primary' => '#000000', 'brand_secondary' => '#000000'], '/admin')['status'], "$role cannot save Branding");
        }
        $this->assertSame('Basemall', (string) Settings::get('store_name'));
    }

    public function testBrandingChangesAreLiveAcrossTheShop(): void
    {
        $this->loginStaff('super_admin');
        $page = $this->get('/admin/settings/branding');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('value="Basemall"', $page['body']);
        $r = $this->post('/admin/settings/branding', ['store_name' => 'Basemall Kenya', 'store_tagline' => 'Shop smarter', 'order_number_prefix' => 'BK',
            'brand_primary' => '#1A4D8F', 'brand_secondary' => '#FFD200'], '/admin/settings/branding');
        $this->assertSame(303, $r['status']);

        $home = $this->get('/')['body'];
        $this->assertStringContainsString('<title>Basemall Kenya — Shop smarter</title>', $home);
        $this->assertStringContainsString('--wm-green:#1A4D8F', $home, 'palette from settings');
        $this->assertStringContainsString('content="#1A4D8F"', $home, 'theme colour');
        $this->assertStringContainsString('alt="Basemall Kenya"', $home, 'logo alt text');
        $this->assertStringContainsString('og:site_name" content="Basemall Kenya"', $home);
        $manifest = json_decode($this->get('/manifest.webmanifest')['body'], true);
        $this->assertSame(['Basemall Kenya', '#1A4D8F'], [$manifest['name'], $manifest['theme_color']]);
        $this->assertStringContainsString('BK-XXXXXXXX', $this->get('/admin/collections')['body'], 'order prefix hint follows the setting');

        // A colour too light for white text is refused with an explanation; nothing changes.
        $bad = $this->post('/admin/settings/branding', ['store_name' => 'Basemall Kenya', 'store_tagline' => '', 'order_number_prefix' => 'BK',
            'brand_primary' => '#FFE600', 'brand_secondary' => '#FFD200'], '/admin/settings/branding');
        $this->assertStringContainsString('too light', $this->follow($bad)['body']);
        Settings::flush();
        $this->assertSame('#1A4D8F', Settings::get('brand_primary'));
    }

    public function testNoPageStillSaysWaimart(): void
    {
        $product = $this->makeVariant(5, '1500.00');
        $slug = (string) $this->db->value('SELECT slug FROM products WHERE id = ?', [$product['product_id']]);
        $public = ['/', '/login', '/register', '/forgot-password', '/track-order', '/help', '/how-to-pay', '/delivery-pickup', '/returns-policy',
                   '/contact', '/about', '/terms', '/privacy', '/pickup-stations', '/sell', '/sell/terms', '/categories', '/cart', '/flash-sales',
                   '/store/basemall-official', "/product/$slug", '/no-such-page', '/admin/login', '/manifest.webmanifest'];
        foreach ($public as $path) {
            $r = $this->get($path);
            $this->assertStringNotContainsString('Waimart', $r['body'], "GET $path");
            $this->assertStringContainsString('Basemall', $r['body'], "GET $path names the store");
        }
        $this->loginStaff('super_admin');
        foreach (['/admin', '/admin/orders', '/admin/sellers', '/admin/settings', '/admin/settings/branding', '/admin/payouts', '/admin/reports/marketplace'] as $path) {
            $this->assertStringNotContainsString('Waimart', $this->get($path)['body'], "GET $path");
        }
        $this->post('/logout', [], '/');
        $seller = $this->db->insert('sellers', ['name' => 'Kamba Crafts', 'slug' => 'kamba-crafts']);
        $u = $this->db->insert('users', ['email' => 'kamba-owner@example.com', 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT), 'first_name' => 'K', 'last_name' => 'O']);
        $this->db->insert('seller_users', ['seller_id' => $seller, 'user_id' => $u, 'role' => 'owner']);
        $this->post('/login', ['email' => 'kamba-owner@example.com', 'password' => 'Secret123'], '/login');
        foreach (['/seller', '/seller/orders', '/seller/products', '/seller/finance', '/seller/disputes', '/seller/reports', '/seller/profile'] as $path) {
            $r = $this->get($path);
            $this->assertSame(200, $r['status'], $path);
            $this->assertStringNotContainsString('Waimart', $r['body'], "GET $path");
        }
    }
}
