<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Settings;
use Waimart\Services\Marketing\Automation;
use Waimart\Services\Marketing\CartRecovery;

/**
 * Abandoned cart recovery: detection after the inactivity period (server-side activity, so closing the browser or
 * signing out needs no event), postponement on new activity, cancellation (order, empty cart, items gone), separate
 * email and SMS outcomes, retries with a limit, guests reached through a consented newsletter lead, recovered orders.
 */
final class AbandonedCartTest extends DbTestCase
{
    private Automation $auto;
    private int $user;
    /** @var array<string,?string> settings changed by a test, restored afterwards */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->auto = new Automation($this->db);
        foreach (['mail_driver', 'smtp_host', 'smtp_port', 'smtp_encryption', 'marketing_max_attempts'] as $k) {
            $this->saved[$k] = Settings::get($k);
        }
        foreach (['marketing_quiet_start' => '00:00', 'marketing_quiet_end' => '00:00', 'marketing_paused' => '0', 'marketing_max_per_day' => '5',
                  'marketing_max_per_week' => '10', 'cart_abandon_minutes' => '60', 'marketing_max_attempts' => '3', 'cart_recovery_guests' => '1'] as $k => $v) {
            Settings::set($k, $v);
        }
        $this->db->exec("UPDATE marketing_automations SET is_enabled = 1, channels = 'email,sms', delay_hours = 1, delay2_hours = NULL WHERE code = 'cart_recovery'");
        $this->user = $this->db->insert('users', ['email' => 'wanjiru@example.com', 'phone' => '254712000222', 'password_hash' => 'x',
            'first_name' => 'Wanjiru', 'last_name' => 'M', 'marketing_email' => 1, 'marketing_sms' => 1]);
    }

    protected function tearDown(): void
    {
        $this->db->exec('UPDATE marketing_automations SET is_enabled = 0');
        foreach (['marketing_quiet_start' => '20:00', 'marketing_quiet_end' => '08:00', 'marketing_max_per_day' => '1', 'marketing_max_per_week' => '3',
                  'cart_abandon_minutes' => '60'] as $k => $v) {
            Settings::set($k, $v);
        }
        foreach ($this->saved as $k => $v) {
            if ($v === null) {
                $this->db->exec('DELETE FROM settings WHERE setting_key = ?', [$k]);
            } else {
                Settings::set($k, $v);
            }
        }
        Settings::flush();
        parent::tearDown();
    }

    private function cart(?int $userId, string $idleFor, ?string $visitor = null): array
    {
        $v = $this->makeVariant(5, '1200.00');
        $cart = $this->db->insert('carts', ['user_id' => $userId, 'token_hash' => $userId === null ? hash('sha256', random_bytes(8)) : null, 'visitor_id' => $visitor]);
        $this->db->insert('cart_items', ['cart_id' => $cart, 'variant_id' => $v['variant_id'], 'quantity' => 1]);
        $this->db->exec("UPDATE carts SET last_activity_at = NOW() - INTERVAL $idleFor WHERE id = ?", [$cart]);
        return ['cart' => $cart] + $v;
    }

    private function msgs(): array
    {
        return $this->db->all("SELECT id, channel, status, reason, attempts, user_id, lead_id, link_token_hash, recovered_order_id FROM marketing_messages WHERE automation = 'cart_recovery' ORDER BY id");
    }

    public function testRemindsOnlyAfterTheInactivityPeriodOncePerChannelWithASecureLink(): void
    {
        $c = $this->cart($this->user, '30 MINUTE');
        $this->assertSame(['cart_recovery' => 0], array_intersect_key($this->auto->enqueue(), ['cart_recovery' => 1]), '30 minutes idle: too early');

        $this->db->exec('UPDATE carts SET last_activity_at = NOW() - INTERVAL 61 MINUTE WHERE id = ?', [$c['cart']]);
        $this->auto->enqueue();
        $this->auto->enqueue();
        $this->assertCount(2, $this->msgs(), 'one email and one SMS, never queued twice');
        $out = $this->auto->process();
        $this->assertSame(2, $out['logged'] + $out['sent'], 'email and SMS each recorded separately');
        foreach ($this->msgs() as $m) {
            $this->assertNotNull($m['link_token_hash'], 'each reminder has its own link token');
        }
        $email = (string) $this->db->value("SELECT body FROM notifications WHERE template = 'mkt_cart' AND channel = 'email'");
        $this->assertStringContainsString('Test Product × 1 — KSh 1,200', $email);
        $this->assertMatchesRegularExpression('#/cart/recover/\d+/[A-Za-z0-9_-]{22}#', $email);
        $sms = (string) $this->db->value("SELECT body FROM notifications WHERE template = 'mkt_cart' AND channel = 'sms'");
        $this->assertMatchesRegularExpression('#/cart/recover/\d+/[A-Za-z0-9_-]{22}#', $sms);
        $this->assertStringNotContainsString(hash('sha256', 'x'), $sms);
        $this->assertSame('You left something in your cart! Complete your order today.', $this->db->value("SELECT subject FROM notifications WHERE template = 'mkt_cart' AND channel = 'email'"));
    }

    public function testNewActivityPostponesAndOrderEmptyCartOrUnavailableItemsCancel(): void
    {
        $c = $this->cart($this->user, '61 MINUTE');
        $this->db->exec("UPDATE marketing_automations SET channels = 'email' WHERE code = 'cart_recovery'");
        $this->auto->enqueue();
        // The customer comes back (opens the cart) before the reminder goes out: postponed, a new cycle starts later.
        $this->db->exec('UPDATE carts SET last_activity_at = NOW() WHERE id = ?', [$c['cart']]);
        $this->auto->process();
        $this->assertSame('cancelled', $this->msgs()[0]['status']);
        $this->assertStringContainsString('came back', (string) $this->msgs()[0]['reason']);
        $this->auto->enqueue();
        $this->assertCount(1, $this->msgs(), 'nothing new until another full hour without activity');
        $this->db->exec('UPDATE carts SET last_activity_at = NOW() - INTERVAL 2 HOUR WHERE id = ?', [$c['cart']]);
        $this->auto->enqueue();
        $this->assertCount(2, $this->msgs(), 'a new reminder for the new period of inactivity');

        // Items no longer on sale: stopped.
        $this->db->exec('UPDATE products SET is_active = 0 WHERE id = ?', [$c['product_id']]);
        $this->auto->process();
        $this->assertSame(['cancelled', 'Items are no longer available'], [$this->msgs()[1]['status'], $this->msgs()[1]['reason']]);

        // Emptied cart: stopped.
        $this->db->exec('DELETE FROM marketing_messages');
        $this->db->exec('UPDATE products SET is_active = 1 WHERE id = ?', [$c['product_id']]);
        $this->auto->enqueue();
        $this->db->exec('DELETE FROM cart_items WHERE cart_id = ?', [$c['cart']]);
        $this->auto->process();
        $this->assertSame(['cancelled', 'Cart is empty now'], [$this->msgs()[0]['status'], $this->msgs()[0]['reason']]);
    }

    public function testFailedSendingIsRetriedUpToTheLimitThenMarkedFailed(): void
    {
        $this->cart($this->user, '61 MINUTE');
        $this->db->exec("UPDATE marketing_automations SET channels = 'email' WHERE code = 'cart_recovery'");
        Settings::set('marketing_max_attempts', '2');
        Settings::set('mail_driver', 'smtp');
        Settings::set('smtp_host', '127.0.0.1');
        Settings::set('smtp_port', '1');          // nothing listens here: a real, immediate failure
        Settings::set('smtp_encryption', 'none');
        $this->auto->enqueue();
        $this->assertSame(1, $this->auto->process()['retry']);
        $this->assertSame(['queued', 1], [$this->msgs()[0]['status'], (int) $this->msgs()[0]['attempts']]);
        $this->assertStringStartsWith('Retrying', (string) $this->msgs()[0]['reason']);
        $this->db->exec('UPDATE marketing_messages SET scheduled_at = NOW()');
        $this->assertSame(1, $this->auto->process()['failed']);
        $this->assertSame(['failed', 2], [$this->msgs()[0]['status'], (int) $this->msgs()[0]['attempts']], 'never reported as sent');
    }

    public function testGuestsAreRemindedOnlyThroughAConsentedNewsletterLead(): void
    {
        $visitor = str_repeat('b', 32);
        $this->cart(null, '61 MINUTE', $visitor);
        $this->cart(null, '61 MINUTE', str_repeat('c', 32));     // unknown browser: nobody to contact
        $this->auto->enqueue();
        $this->assertCount(0, $this->msgs(), 'no lead, no reminder');
        $lead = $this->db->insert('marketing_leads', ['email' => 'guest@example.com', 'name' => 'Otieno', 'source' => 'newsletter', 'visitor_id' => $visitor, 'consent_email' => 1]);
        $this->auto->enqueue();
        $this->assertCount(2, $this->msgs());
        $this->auto->process();
        $m = $this->msgs();
        $this->assertSame([(int) $lead, 'logged'], [(int) $m[0]['lead_id'], $m[0]['status']]);
        $this->assertSame(['suppressed', 'No consent for sms'], [$m[1]['status'], $this->db->value('SELECT reason FROM marketing_messages WHERE id = ?', [$m[1]['id']])]);
        $this->assertStringContainsString('/unsubscribe/l' . $lead . '/email/', (string) $this->db->value("SELECT body FROM notifications WHERE template = 'mkt_cart'"));
    }

    public function testAnOrderAfterAReminderCountsAsRecoveredOnce(): void
    {
        $c = $this->cart($this->user, '61 MINUTE');
        $this->db->exec("UPDATE marketing_automations SET channels = 'email' WHERE code = 'cart_recovery'");
        $this->auto->enqueue();
        $this->auto->process();
        $order = $this->makeOrder($c['variant_id'], 1, ['user_id' => $this->user, 'placed_at' => date('Y-m-d H:i:s'), 'payment_status' => 'verified']);
        $row = $this->db->one('SELECT * FROM orders WHERE id = ?', [$order['order_id']]);
        (new CartRecovery($this->db))->orderPlaced($row);
        (new CartRecovery($this->db))->orderPlaced($row);
        $this->assertSame((string) $order['order_id'], (string) $this->msgs()[0]['recovered_order_id']);
        $s = (new CartRecovery($this->db))->stats(date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'));
        $this->assertSame(1, $s['recovered']);
        $this->assertSame((float) $row['grand_total'], $s['revenue']);
        $this->assertSame((float) $row['grand_total'], $s['paid_revenue']);
        $this->assertSame(1.0, $s['rate']);
    }
}
