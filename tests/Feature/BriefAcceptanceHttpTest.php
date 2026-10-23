<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\Settings;

/**
 * Ecommerce Stage 6: the brief's acceptance checks (section 11) that are not already a test of their own:
 * missing settings never show errors, the tagline and footer change from the admin, delivery charges change the
 * server's totals, and a suspended staff member's open session stops working.
 */
final class BriefAcceptanceHttpTest extends FeatureTestCase
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

    public function testMissingSettingsNeverShowErrors(): void
    {
        $variant = $this->makeVariant(5, '1500.00');
        $slug = (string) $this->db->value('SELECT slug FROM products WHERE id = ?', [$variant['product_id']]);
        // Every optional setting gone (as on a half-configured install): pages still work and show nothing misleading.
        $keep = ['reservation_minutes', 'reservation_minutes_cod', 'collection_hold_days', 'return_window_days', 'max_payment_submissions', 'products_per_page'];
        $this->db->exec('DELETE FROM settings WHERE setting_key NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')', $keep);
        foreach (['/', '/categories', '/help', '/about', '/contact', '/how-to-pay', '/delivery-pickup', '/pickup-stations', '/privacy', '/terms',
                  '/returns-policy', '/search?q=test', "/product/$slug", '/cart', '/login', '/register', '/track-order', '/sell', '/manifest.webmanifest'] as $p) {
            $r = $this->get($p);
            $this->assertSame(200, $r['status'], "GET $p with no settings");
            $this->assertDoesNotMatchRegularExpression('/Fatal error|Warning:|Notice:|Deprecated:|Uncaught|stack trace/i', $r['body'], $p);
        }
        $home = $this->get('/')['body'];
        $this->assertStringContainsString('Ecommerce', $home, 'the built-in brand name is the fallback');
        $this->assertStringNotContainsString('wa.me/', $home, 'no contact details are invented');
        $this->assertStringNotContainsString('wm-pay-badge mpesa', $home, 'no M-Pesa offered without a number');
        $this->loginStaff('super_admin');
        foreach (['/admin', '/admin/settings/general', '/admin/settings/branding', '/admin/payment-methods', '/admin/delivery-settings', '/admin/settings/email', '/admin/messages'] as $p) {
            $this->assertSame(200, $this->get($p)['status'], "GET $p with no settings");
        }
    }

    public function testTaglineAndFooterChangeFromTheAdmin(): void
    {
        $this->loginStaff('super_admin');
        $page = $this->get('/admin/settings/branding')['body'];
        preg_match_all('/<input[^>]+name="([a-z_]+)"[^>]*value="([^"]*)"/', $page, $m, PREG_SET_ORDER);
        $form = [];
        foreach ($m as [, $name, $value]) {
            if ($name !== '_csrf') {
                $form[$name] = html_entity_decode($value);
            }
        }
        $form['store_tagline'] = 'Everything you need, delivered.';
        $this->assertSame(303, $this->post('/admin/settings/branding', $form, '/admin/settings/branding')['status']);
        $this->post('/admin/settings/general', ['footer_note' => 'Open every day, 8 am to 8 pm.', 'products_per_page' => '24'], '/admin/settings/general');
        $this->post('/logout', [], '/');
        $home = $this->get('/')['body'];
        $this->assertStringContainsString('Everything you need, delivered.', $home);
        $this->assertStringContainsString('Open every day, 8 am to 8 pm.', $home);
    }

    public function testDeliveryChargesChangeTheServerTotal(): void
    {
        $zone = $this->db->insert('delivery_zones', ['name' => 'City', 'fee' => '250', 'min_days' => 1, 'max_days' => 2, 'cod_allowed' => 1]);
        $this->db->insert('delivery_zone_locations', ['zone_id' => $zone, 'county_id' => 47, 'town' => '']);
        $variant = $this->makeVariant(5, '1500.00');
        $slug = (string) $this->db->value('SELECT slug FROM products WHERE id = ?', [$variant['product_id']]);
        $this->post('/cart/add', ['variant_id' => (string) $variant['variant_id'], 'quantity' => '1'], "/product/$slug");
        $quote = fn () => json_decode($this->post('/checkout/quote', ['fulfilment_method' => 'delivery', 'county_id' => '47', 'town' => 'Kilimani'], '/checkout')['body'], true);
        $this->assertSame('KSh 1,750', $quote()['total_display']);

        $this->loginStaff('manager');
        $this->post("/admin/delivery-zones/$zone", ['name' => 'City', 'fee' => '400', 'min_days' => '1', 'max_days' => '2', 'cod_allowed' => '1', 'is_active' => '1'], "/admin/delivery-zones/$zone/edit");
        $this->assertSame('400.00', (string) $this->db->value('SELECT fee FROM delivery_zones WHERE id = ?', [$zone]));
        $this->post('/logout', [], '/');
        $this->post('/cart/add', ['variant_id' => (string) $variant['variant_id'], 'quantity' => '1'], "/product/$slug");
        $this->assertSame('KSh 3,400', $quote()['total_display'], '2 × 1,500 + the new fee');

        // The order itself is priced on the server: a posted fee or total is ignored.
        $page = $this->get('/checkout')['body'];
        preg_match('/name="_csrf" value="([^"]+)"/', $page, $c);
        preg_match('/name="checkout_token" value="([^"]+)"/', $page, $t);
        $this->http('POST', '/checkout', ['_csrf' => html_entity_decode($c[1]), 'checkout_token' => $t[1], 'customer_name' => 'Guest', 'customer_phone' => '0722111222',
            'customer_email' => 'g@example.com', 'fulfilment_method' => 'delivery', 'county_id' => '47', 'town' => 'Kilimani', 'location' => 'Argwings Kodhek',
            'payment_method' => 'cod', 'delivery_fee' => '0', 'grand_total' => '1'], [], '/checkout');
        $this->assertSame(['400.00', '3400.00'], array_values((array) $this->db->one('SELECT delivery_fee, grand_total FROM orders')));
    }

    public function testSuspendedStaffLoseTheirOpenSession(): void
    {
        $u = $this->loginStaff('manager');
        $this->assertSame(200, $this->get('/admin/orders')['status']);
        $boss = $this->makeStaff('super_admin');
        (new \Waimart\Services\StaffService())->setActive((int) $u['id'], false, (int) $boss['id']);
        $this->assertStringEndsWith('/admin/login', (string) ($this->get('/admin/orders')['headers']['location'] ?? ''), 'the next click is signed out');
        $this->post('/admin/content/pages/new', ['title' => 'x', 'body' => 'x', 'slug' => 'x', 'status' => 'published'], '/admin/login');
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM content_pages WHERE title = 'x'"));
    }
}
