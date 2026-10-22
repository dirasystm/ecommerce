<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Settings;
use Waimart\Services\Marketing\Automation;
use Waimart\Services\Marketing\Consent;

/** Automations: idempotent queuing, one send per message, consent, relevance, limits, quiet hours. */
final class MarketingAutomationTest extends DbTestCase
{
    private Automation $auto;
    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auto = new Automation($this->db);
        Settings::set('marketing_quiet_start', '00:00');      // no quiet hours during the test
        Settings::set('marketing_quiet_end', '00:00');
        Settings::set('marketing_paused', '0');
        Settings::set('marketing_max_per_day', '1');
        Settings::set('marketing_max_per_week', '3');
        $this->user = $this->db->insert('users', ['email' => 'buyer@example.com', 'phone' => '254712000111', 'password_hash' => 'x',
            'first_name' => 'Achieng', 'last_name' => 'O', 'marketing_email' => 1, 'marketing_sms' => 0]);
    }

    protected function tearDown(): void
    {
        $this->db->exec('UPDATE marketing_automations SET is_enabled = 0');
        Settings::set('marketing_quiet_start', '20:00');
        Settings::set('marketing_quiet_end', '08:00');
        Settings::set('marketing_paused', '0');
        parent::tearDown();
    }

    private function enable(string $code, string $channels = 'email', int $delay = 0, ?int $d2 = null, ?int $d3 = null): void
    {
        $this->db->exec('UPDATE marketing_automations SET is_enabled = 1, channels = ?, delay_hours = ?, delay2_hours = ?, delay3_hours = ? WHERE code = ?', [$channels, $delay, $d2, $d3, $code]);
    }

    private function cartWithItem(string $updatedAgo = '4 HOUR'): int
    {
        $v = $this->makeVariant(5, '1500.00')['variant_id'];
        $cart = $this->db->insert('carts', ['user_id' => $this->user]);
        $this->db->insert('cart_items', ['cart_id' => $cart, 'variant_id' => $v, 'quantity' => 2]);
        $this->db->exec("UPDATE carts SET updated_at = NOW() - INTERVAL $updatedAgo WHERE id = ?", [$cart]);
        return $cart;
    }

    private function messages(): array
    {
        return $this->db->all('SELECT automation, channel, status, reason, trigger_key FROM marketing_messages ORDER BY id');
    }

    public function testCartReminderIsQueuedOnceAndSentOnceWithRealItemsAndUnsubscribeLink(): void
    {
        $this->cartWithItem();
        $this->enable('cart_recovery', 'email,sms', 3, 24);
        $this->assertSame(['cart_recovery' => 4], $this->auto->enqueue(), 'two steps × two channels; SMS consent is decided at send time');
        $this->auto->enqueue();
        $this->assertCount(4, $this->messages(), 'running again queues nothing new (2 steps × 2 channels, unique)');

        $first = $this->auto->process();
        $this->assertSame(1, $first['logged'], 'step 1 email recorded (development mail log)');
        $this->assertSame(1, $first['suppressed'], 'step 1 SMS: no SMS consent');
        $this->assertSame(['sent' => 0, 'logged' => 0, 'failed' => 0, 'retry' => 0, 'suppressed' => 0, 'cancelled' => 0, 'deferred' => 0], $this->auto->process(), 'nothing is sent twice');

        $mail = (string) $this->db->value("SELECT body FROM notifications WHERE template = 'mkt_cart' ORDER BY id DESC LIMIT 1");
        $this->assertStringContainsString('× 2 — KSh 1,500', $mail, 'the real cart item and current price');
        $this->assertStringContainsString('/unsubscribe/u' . $this->user . '/email/', $mail);
    }

    public function testOrderingOptingOutOrSwitchingOffStopsMessages(): void
    {
        $cart = $this->cartWithItem();
        $this->enable('cart_recovery', 'email', 3);
        $this->auto->enqueue();
        // The customer orders before the reminder goes out.
        $this->makeOrder((int) $this->db->value('SELECT variant_id FROM cart_items WHERE cart_id = ?', [$cart]), 1, ['user_id' => $this->user, 'placed_at' => date('Y-m-d H:i:s')]);
        $this->auto->process();
        $this->assertSame(['cancelled', 'Customer has ordered'], [$this->messages()[0]['status'], $this->messages()[0]['reason']]);

        $this->db->exec('DELETE FROM marketing_messages');
        $this->db->exec('UPDATE orders SET placed_at = NOW() - INTERVAL 10 DAY');      // that order is now older than the cart
        $this->db->exec('UPDATE carts SET updated_at = NOW() - INTERVAL 5 HOUR WHERE id = ?', [$cart]);
        $this->auto->enqueue();
        (new Consent($this->db))->setForUser($this->user, ['email' => false], 'preferences');
        $this->auto->process();
        $this->assertSame('suppressed', $this->messages()[0]['status'], 'opted out after queuing: not sent');

        $this->db->exec('DELETE FROM marketing_messages');
        (new Consent($this->db))->setForUser($this->user, ['email' => true], 'preferences');
        $this->auto->enqueue();
        $this->auto->save('cart_recovery', ['is_enabled' => '', 'channels' => ['email'], 'delay_hours' => '3'], $this->user);
        $this->assertSame('cancelled', $this->messages()[0]['status'], 'switching an automation off cancels what it queued');
    }

    public function testDailyLimitQuietHoursAndGlobalPause(): void
    {
        $this->enable('welcome');
        $this->auto->enqueue();
        $this->db->insert('marketing_messages', ['automation' => 'reengage', 'trigger_key' => 'earlier', 'channel' => 'email', 'user_id' => $this->user,
            'status' => 'sent', 'scheduled_at' => date('Y-m-d H:i:s'), 'sent_at' => date('Y-m-d H:i:s')]);
        $this->assertSame(1, $this->auto->process()['deferred'], 'one marketing message a day already sent: the welcome waits');
        $this->assertSame('Daily limit reached', $this->db->value("SELECT reason FROM marketing_messages WHERE automation = 'welcome'"));

        Settings::set('marketing_paused', '1');
        $this->assertSame(1, $this->auto->process()['paused'] ?? 0, 'global pause stops everything');
        Settings::set('marketing_paused', '0');

        Settings::set('marketing_quiet_start', '20:00');
        Settings::set('marketing_quiet_end', '08:00');
        $night = (new \DateTimeImmutable('2026-10-22 22:30', new \DateTimeZone('Africa/Nairobi')))->getTimestamp();
        $this->assertSame(9.5 * 3600, (float) Automation::quietWait($night), '22:30 waits until 08:00');
        $day = (new \DateTimeImmutable('2026-10-22 10:00', new \DateTimeZone('Africa/Nairobi')))->getTimestamp();
        $this->assertSame(0, Automation::quietWait($day));
    }

    public function testPriceDropOnlyForARealDropAndOnlyOnce(): void
    {
        Settings::set('price_drop_min_percent', '5');
        $v = $this->makeVariant(5, '1000.00');
        $this->db->insert('wishlist_items', ['user_id' => $this->user, 'product_id' => $v['product_id'], 'price_at_add' => '1000.00']);
        $this->enable('price_drop');
        $this->db->exec('UPDATE product_variants SET price = 980.00 WHERE id = ?', [$v['variant_id']]);
        $this->assertSame(['price_drop' => 0], $this->auto->enqueue(), '2% is below the 5% threshold');
        $this->db->exec('UPDATE product_variants SET price = 850.00 WHERE id = ?', [$v['variant_id']]);
        $this->assertSame(['price_drop' => 1], $this->auto->enqueue());
        $this->auto->process();
        $body = (string) $this->db->value("SELECT body FROM notifications WHERE template = 'mkt_price_drop'");
        $this->assertStringContainsString('now KSh 850', $body);
        $this->assertStringContainsString('was KSh 1,000', $body, 'compared with the real price when saved');
        $this->assertSame('850.00', $this->db->value('SELECT last_alert_price FROM wishlist_items WHERE user_id = ?', [$this->user]));
        $this->db->exec('DELETE FROM marketing_messages');
        $this->assertSame(['price_drop' => 0], $this->auto->enqueue(), 'no second alert at the same price');
    }

    public function testWinBackStepsFollowTheLastOrderAndStopWhenTheCustomerOrders(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $old = $this->makeOrder($v, 1, ['user_id' => $this->user, 'status' => 'delivered', 'placed_at' => date('Y-m-d H:i:s', time() - 65 * 86400)]);
        $this->enable('reengage', 'email', 720, 1440, 2160);
        $this->auto->enqueue();
        $this->assertSame('u' . $this->user . ':o' . $old['order_id'] . ':2', $this->messages()[0]['trigger_key'], '65 days after the last order: the 60-day step');
        $this->makeOrder($v, 1, ['user_id' => $this->user, 'status' => 'confirmed', 'placed_at' => date('Y-m-d H:i:s')]);
        $this->auto->process();
        $this->assertSame(['cancelled', 'Customer has ordered again'], [$this->messages()[0]['status'], $this->messages()[0]['reason']]);
    }
}
