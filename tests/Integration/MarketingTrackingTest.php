<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Settings;
use Waimart\Services\Marketing\Attribution;
use Waimart\Services\Marketing\Consent;
use Waimart\Services\Marketing\Signals;
use Waimart\Services\Marketing\Tracker;

/** Behaviour tracking respects every privacy switch; consent has a history; attribution follows orders. */
final class MarketingTrackingTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_COOKIE = [];
        Tracker::reset();
        Attribution::reset();
        Settings::set('tracking_enabled', '1');
        Settings::set('tracking_consent_mode', 'opt_in');
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
        Tracker::reset();
        Attribution::reset();
        Settings::set('tracking_consent_mode', 'opt_in');
        Settings::set('tracking_enabled', '1');
        parent::tearDown();
    }

    private function user(bool $personalisation = true): int
    {
        return $this->db->insert('users', ['email' => 'u' . bin2hex(random_bytes(3)) . '@example.com', 'password_hash' => 'x', 'first_name' => 'Amina',
            'last_name' => 'Otieno', 'personalisation' => $personalisation ? 1 : 0]);
    }

    private function events(): array
    {
        return $this->db->all('SELECT event, user_id, visitor_id, product_id, link_id, first_link_id FROM shop_events ORDER BY id');
    }

    public function testGuestsAreOnlyTrackedAfterAllowingAndViewsAreCountedOnce(): void
    {
        Tracker::record('product_viewed', ['product_id' => 5]);
        $this->assertSame([], $this->events(), 'opt-in mode: nothing before the guest allows it');

        $_COOKIE['bm_pc'] = '0';
        Tracker::record('product_viewed', ['product_id' => 5]);
        $this->assertSame([], $this->events(), 'guest said no');

        $_COOKIE['bm_pc'] = '1';
        Tracker::record('product_viewed', ['product_id' => 5]);
        Tracker::record('product_viewed', ['product_id' => 5]);
        Tracker::record('product_viewed', ['product_id' => 6]);
        $rows = $this->events();
        $this->assertCount(2, $rows, 'the same product viewed twice within 30 minutes counts once');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $rows[0]['visitor_id'], 'random first-party visitor id');

        Settings::set('tracking_consent_mode', 'notice');
        unset($_COOKIE['bm_pc']);
        $this->assertTrue(Tracker::allowed(), 'notice mode: tracked unless the guest opts out');
        Settings::set('tracking_enabled', '0');
        $this->assertFalse(Tracker::allowed(), 'master switch');
        Tracker::record('order_placed', ['order_id' => 1, 'user_id' => null]);
        $this->assertCount(2, $this->events(), 'switched off: nothing at all is recorded');
    }

    public function testCustomersWhoSayNoAreNotProfiledButOrdersStillCount(): void
    {
        $no = $this->user(false);
        Tracker::record('product_viewed', ['product_id' => 7, 'user_id' => $no]);
        Tracker::record('cart_added', ['product_id' => 7, 'user_id' => $no]);
        $this->assertSame([], $this->events(), 'no browsing profile');
        Tracker::record('order_placed', ['order_id' => 99, 'user_id' => $no, 'amount' => '1500.00']);
        $this->assertSame('order_placed', $this->events()[0]['event'], 'sales results are business records');
    }

    public function testGuestHistoryJoinsOnlyTheRightAccount(): void
    {
        $_COOKIE['bm_pc'] = '1';
        Tracker::record('product_viewed', ['product_id' => 11]);
        $visitor = Tracker::visitorId();
        $_COOKIE[Tracker::COOKIE] = $visitor;
        // Another account already owns an older event from this browser: it must not move.
        $other = $this->user();
        $this->db->insert('shop_events', ['visitor_id' => $visitor, 'user_id' => $other, 'event' => 'product_viewed', 'product_id' => 12]);

        $me = $this->user();
        Tracker::claimGuestHistory($me);
        $this->assertSame([[11, $me], [12, $other]], array_map(fn ($r) => [(int) $r['product_id'], (int) $r['user_id']], $this->events()));

        $private = $this->user(false);
        $this->db->insert('shop_events', ['visitor_id' => $visitor, 'user_id' => null, 'event' => 'product_viewed', 'product_id' => 13]);
        Tracker::claimGuestHistory($private);
        $this->assertNull($this->db->value('SELECT user_id FROM shop_events WHERE product_id = 13'), 'not joined when personalisation is off');
    }

    public function testConsentHistoryAndSignedUnsubscribe(): void
    {
        $u = $this->user();
        $c = new Consent($this->db);
        $c->setForUser($u, ['email' => true, 'sms' => true], 'preferences', '1.2.3.4');
        $c->setForUser($u, ['email' => true, 'sms' => true], 'preferences');     // no change, no new history
        $this->assertSame(['email' => true, 'sms' => true, 'personalisation' => true, 'frequency' => 'normal'], $c->forUser($u));
        $this->assertCount(2, $c->history($u));
        $this->assertSame(1, (int) $this->db->value('SELECT marketing_opt_in FROM users WHERE id = ?', [$u]), 'older flag mirrors email consent');

        $this->db->insert('marketing_messages', ['automation' => 'reengage', 'trigger_key' => 't1', 'channel' => 'email', 'user_id' => $u, 'scheduled_at' => date('Y-m-d H:i:s')]);
        $url = Consent::unsubscribeUrl('u', $u, 'email');
        $sig = substr($url, strrpos($url, '/') + 1);
        $this->assertFalse($c->unsubscribe('u', $u, 'email', str_repeat('a', 20)), 'forged link refused');
        $this->assertFalse($c->unsubscribe('u', $u + 1, 'email', $sig), 'link for one person cannot unsubscribe another');
        $this->assertTrue($c->unsubscribe('u', $u, 'email', $sig));
        $this->assertFalse($c->forUser($u)['email']);
        $this->assertTrue($c->forUser($u)['sms'], 'only that channel');
        $this->assertSame('cancelled', $this->db->value('SELECT status FROM marketing_messages WHERE trigger_key = ?', ['t1']), 'queued message cancelled at once');
        $this->assertSame('unsubscribe_link', $c->history($u)[0]['source']);
    }

    public function testOrderOutcomesKeepTheCampaignThatLedToTheOrder(): void
    {
        $u = $this->user();
        Attribution::touch(41);
        Attribution::touch(42);
        $this->assertSame(['first' => 41, 'last' => 42], Attribution::current());
        Signals::orderPlaced(['id' => 500, 'user_id' => $u, 'grand_total' => '2500.00']);
        Attribution::reset();      // later, staff confirm the order from another browser
        foreach (['confirmed', 'confirmed', 'delivered'] as $status) {
            Signals::orderStatus(['id' => 500, 'user_id' => $u, 'grand_total' => '2500.00', 'status' => $status]);
        }
        $rows = $this->events();
        $this->assertSame(['order_placed', 'order_confirmed', 'order_delivered'], array_column($rows, 'event'), 'each outcome counted once');
        foreach ($rows as $r) {
            $this->assertSame([42, 41], [(int) $r['link_id'], (int) $r['first_link_id']]);
        }
    }
}
