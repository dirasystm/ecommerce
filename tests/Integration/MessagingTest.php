<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Settings;
use Waimart\Repositories\OrderRepository;
use Waimart\Services\MessageTemplates;
use Waimart\Services\NotificationService;
use Waimart\Services\SmsGateway;

/** SMS through the gateway (recorded in the message log with the gateway's id), and tracking numbers. */
final class MessagingTest extends DbTestCase
{
    use PlacesOrders;

    /** @var list<array<string,mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFulfilment();
        Settings::set('sms_driver', '');
    }

    protected function tearDown(): void
    {
        Settings::set('sms_driver', '');
        $this->tearDownFulfilment();
        parent::tearDown();
    }

    private function gateway(string $answer): SmsGateway
    {
        return new SmsGateway('tok', 'https://bulksms.talksasa.com/api/v3/', 'ECOMMERCE', function ($m, $u, $h, $b) use ($answer) {
            $this->sent[] = json_decode((string) $b, true);
            return [200, $answer];
        });
    }

    public function testSmsStaysInTheLogWhileTheGatewayIsOff(): void
    {
        $id = (new NotificationService())->sms('254712345678', 'Hello', 'test');
        $this->assertSame('logged', $this->db->value('SELECT status FROM notifications WHERE id = ?', [$id]));
    }

    public function testSentSmsKeepsTheGatewayIdAndFailedSmsTheReason(): void
    {
        $ok = (new NotificationService($this->gateway('{"status":"success","data":{"uid":"u-77","status":"Sent"}}')))
            ->sms('254712345678', 'Your code is 4821', 'order_claim_code', ['secrets' => ['4821']]);
        $row = $this->db->one('SELECT * FROM notifications WHERE id = ?', [$ok]);
        $this->assertSame(['sent', 'u-77', 'Sent', 1], [$row['status'], $row['provider_ref'], $row['provider_status'], (int) $row['attempts']]);
        $this->assertSame('Your code is 4821', $this->sent[0]['message'], 'the phone gets the real code');
        $this->assertSame('Your code is [redacted]', $row['body'], 'the stored copy does not');

        $bad = (new NotificationService($this->gateway('{"status":"error","message":"Insufficient balance"}')))->sms('254712345678', 'Hi', 'test');
        $row = $this->db->one('SELECT * FROM notifications WHERE id = ?', [$bad]);
        $this->assertSame(['failed', 'Insufficient balance'], [$row['status'], $row['last_error']]);
    }

    public function testTemplatesUseTheGatewayWhenSwitchedOn(): void
    {
        Settings::set('sms_driver', 'talksasa');
        $this->assertTrue(SmsGateway::enabled());
        // No token saved: the SMS is recorded as failed with a clear reason, and nothing breaks.
        $n = (new NotificationService())->sms('254712345678', 'Hi', 'test');
        $row = $this->db->one('SELECT status, last_error FROM notifications WHERE id = ?', [$n]);
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('not set up', (string) $row['last_error']);
    }

    public function testSmsOnlyFallsBackToEmailWhileSmsIsSwitchedOff(): void
    {
        Settings::set('notify_channels', 'sms');
        Settings::set('sms_driver', '');
        $this->assertSame('both', NotificationService::channelMode(), 'SMS off: customers still get email');
        $n = (new NotificationService())->sendTemplate('order_confirmed', ['order_number' => 'BM-1', 'store' => 'Ecommerce', 'customer_name' => 'A'],
            ['email' => 'a@example.com', 'phone' => '254712345678']);
        $this->assertSame(2, $n);
        $this->assertSame(['email', 'sms'], array_column($this->db->all("SELECT channel FROM notifications ORDER BY channel"), 'channel'));

        Settings::set('sms_driver', 'talksasa');
        $this->assertSame('sms', NotificationService::channelMode());
        Settings::set('notify_channels', 'both');
        Settings::set('sms_driver', '');
    }

    public function testTrackingNumberFindsTheOrderOnlyWithItsPhone(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $order = $this->placeOrder([[$v, 1]], 'cod', 'delivery', ['customer_phone' => '0712345678']);
        $so = (int) $this->db->value('SELECT id FROM seller_orders WHERE order_id = ?', [$order['id']]);
        $this->db->exec("UPDATE seller_orders SET tracking_reference = 'G4S-99887' WHERE id = ?", [$so]);

        $repo = new OrderRepository($this->db);
        $this->assertSame((int) $order['id'], (int) $repo->byTrackingNumber('g4s-99887', '254712345678')['id'], 'case does not matter');
        $this->assertNull($repo->byTrackingNumber('G4S-99887', '254700000000'), 'wrong phone');
        $this->assertNull($repo->byTrackingNumber('', '254712345678'));

        $this->assertSame(' Tracking number: G4S-99887.', MessageTemplates::trackingNote((int) $order['id']));
        $this->assertSame(' Tracking number: G4S-99887.', MessageTemplates::trackingNote((int) $order['id'], $so));
        $msg = (new MessageTemplates())->render('order_dispatched', ['order_number' => 'BM-1', 'store' => 'Ecommerce', 'tracking_note' => ' Tracking number: G4S-99887.']);
        $this->assertStringContainsString('on its way. Tracking number: G4S-99887.', (string) $msg['sms']);
    }
}
