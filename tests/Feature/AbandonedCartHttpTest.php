<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\Settings;
use Waimart\Services\Marketing\Automation;

/** Cart recovery links over HTTP, and the Abandoned carts admin page (stats, live tables, settings). */
final class AbandonedCartHttpTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['marketing_quiet_start' => '00:00', 'marketing_quiet_end' => '00:00', 'marketing_max_per_day' => '5', 'marketing_max_per_week' => '10', 'cart_abandon_minutes' => '60'] as $k => $v) {
            Settings::set($k, $v);
        }
        $this->db->exec("UPDATE marketing_automations SET is_enabled = 1, channels = 'email', delay_hours = 1, delay2_hours = NULL WHERE code = 'cart_recovery'");
    }

    protected function tearDown(): void
    {
        $this->db->exec('UPDATE marketing_automations SET is_enabled = 0');
        foreach (['marketing_quiet_start' => '20:00', 'marketing_quiet_end' => '08:00', 'marketing_max_per_day' => '1', 'marketing_max_per_week' => '3'] as $k => $v) {
            Settings::set($k, $v);
        }
        parent::tearDown();
    }

    private function freshJar(): void
    {
        (new \ReflectionProperty(FeatureTestCase::class, 'jar'))->setValue($this, tempnam(sys_get_temp_dir(), 'wmjar'));
    }

    /** Abandoned cart with a reminder sent; returns [cart id, link path]. */
    private function reminded(?int $userId, ?string $visitor = null): array
    {
        $v = $this->makeVariant(5, '1750.00');
        $this->db->exec('UPDATE products SET name = ? WHERE id = ?', ['Recovery Kettle', $v['product_id']]);
        $cart = $this->db->insert('carts', ['user_id' => $userId, 'token_hash' => $userId === null ? hash('sha256', random_bytes(8)) : null, 'visitor_id' => $visitor]);
        $this->db->insert('cart_items', ['cart_id' => $cart, 'variant_id' => $v['variant_id'], 'quantity' => 2]);
        $this->db->exec('UPDATE carts SET last_activity_at = NOW() - INTERVAL 2 HOUR WHERE id = ?', [$cart]);
        $auto = new Automation($this->db);
        $auto->enqueue();
        $auto->process();
        $body = (string) $this->db->value("SELECT body FROM notifications WHERE template = 'mkt_cart' ORDER BY id DESC LIMIT 1");
        $this->assertMatchesRegularExpression('#(/cart/recover/\d+/[A-Za-z0-9_-]{22})#', $body);
        preg_match('#(/cart/recover/\d+/[A-Za-z0-9_-]{22})#', $body, $m);
        return [$cart, $m[1]];
    }

    private function customer(string $email): int
    {
        return $this->db->insert('users', ['email' => $email, 'phone' => '2547' . random_int(10000000, 99999999), 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT),
            'first_name' => 'Njeri', 'last_name' => 'K', 'marketing_email' => 1]);
    }

    public function testCustomerLinkNeedsThatCustomerSignedInAndBadOrOldLinksAreRefused(): void
    {
        $uid = $this->customer('njeri@example.com');
        [, $link] = $this->reminded($uid);

        $r = $this->get($link);
        $this->assertSame(303, $r['status']);
        $this->assertStringEndsWith('/login', $r['headers']['location']);
        $this->assertNotNull($this->db->value("SELECT clicked_at FROM marketing_messages WHERE automation = 'cart_recovery'"), 'the click is recorded');
        $login = $this->post('/login', ['email' => 'njeri@example.com', 'password' => 'Secret123'], '/login');
        $this->assertStringEndsWith('/cart', $login['headers']['location'] ?? '', 'back to the cart after signing in');
        $cart = $this->follow($login)['body'];
        $this->assertStringContainsString('Recovery Kettle', $cart);

        // Another account cannot open it.
        $this->post('/logout', [], '/');
        $this->freshJar();
        $this->customer('other@example.com');
        $this->post('/login', ['email' => 'other@example.com', 'password' => 'Secret123'], '/login');
        $page = $this->follow($this->get($link))['body'];
        $this->assertStringContainsString('belongs to another account', $page);
        $this->assertStringNotContainsString('Recovery Kettle', $page);

        // Tampered or expired links.
        $this->freshJar();
        $bad = substr($link, 0, -1) . (str_ends_with($link, 'A') ? 'B' : 'A');
        $this->assertStringContainsString('not valid', $this->follow($this->get($bad))['body']);
        $this->db->exec("UPDATE marketing_messages SET sent_at = NOW() - INTERVAL 8 DAY WHERE automation = 'cart_recovery'");
        $this->assertStringContainsString('expired', $this->follow($this->get($link))['body']);
        $this->assertSame(404, $this->get('/cart/recover/1/short')['status'], 'malformed tokens never reach the code');
    }

    public function testGuestLinkReopensTheCartInThisBrowser(): void
    {
        $visitor = str_repeat('d', 32);
        $this->db->insert('marketing_leads', ['email' => 'guest2@example.com', 'name' => 'Kamau', 'source' => 'newsletter', 'visitor_id' => $visitor, 'consent_email' => 1]);
        [$cart, $link] = $this->reminded(null, $visitor);
        $this->assertStringNotContainsString('Recovery Kettle', $this->get('/cart')['body'], 'a new browser has an empty cart');
        $r = $this->get($link);
        $this->assertStringEndsWith('/cart', $r['headers']['location']);
        $page = $this->follow($r)['body'];
        $this->assertStringContainsString('Recovery Kettle', $page);
        $this->assertStringContainsString('Welcome back', $page);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM cart_items WHERE cart_id = ?', [$cart]), 'the same cart, nothing ordered');
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM orders'));
    }

    public function testAdminPageFiguresTablesAndSettings(): void
    {
        $this->reminded($this->customer('admin-view@example.com'));
        $this->loginStaff('content_marketing');
        $page = $this->get('/admin/marketing/abandoned-carts');
        $this->assertSame(200, $page['status'], substr(strip_tags($page['body']), 0, 300));
        foreach (['Abandoned carts', 'Recovered orders', 'Showing 1–1 of 1 carts', 'data-live-table="ac-carts"', 'Njeri K'] as $t) {
            $this->assertStringContainsString($t, $page['body'], $t);
        }
        $this->assertStringNotContainsString('admin-view@example.com', $page['body'], 'contact details masked without marketing.leads');
        $this->assertStringContainsString('No matching records found', $this->get('/admin/marketing/abandoned-carts?q=nobody-like-this')['body']);
        $log = $this->get('/admin/marketing/abandoned-carts?view=log&status=logged')['body'];
        $this->assertStringContainsString('Showing 1–1 of 1 reminders', $log);
        $this->assertStringContainsString('No matching records', $this->get('/admin/marketing/abandoned-carts?view=log&status=failed')['body']);

        $save = $this->post('/admin/marketing/abandoned-carts/settings', ['is_enabled' => '1', 'channels' => ['email', 'sms'], 'cart_abandon_minutes' => '90',
            'delay2_hours' => '24', 'cart_reminder_link_days' => '5', 'marketing_max_attempts' => '4'], '/admin/marketing/abandoned-carts?view=settings');
        $this->assertSame(303, $save['status']);
        Settings::flush();
        $this->assertSame(['90', '5', '4', '0'], [Settings::get('cart_abandon_minutes'), Settings::get('cart_reminder_link_days'), Settings::get('marketing_max_attempts'), Settings::get('cart_recovery_guests')]);
        $this->assertSame(['email,sms', '24'], array_values(array_map('strval', $this->db->one("SELECT channels, delay2_hours FROM marketing_automations WHERE code = 'cart_recovery'"))));
        $bad = $this->post('/admin/marketing/abandoned-carts/settings', ['is_enabled' => '1', 'channels' => ['email'], 'cart_abandon_minutes' => '5',
            'cart_reminder_link_days' => '5', 'marketing_max_attempts' => '4'], '/admin/marketing/abandoned-carts?view=settings');
        $this->assertSame(303, $bad['status'], 'sent back to the form with the error');
        Settings::flush();
        $this->assertSame('90', Settings::get('cart_abandon_minutes'), 'under 15 minutes is refused');
        Settings::set('cart_abandon_minutes', '60');
        Settings::set('cart_recovery_guests', '1');
        Settings::set('cart_reminder_link_days', '7');
        Settings::set('marketing_max_attempts', '3');
    }
}
