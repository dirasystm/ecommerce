<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Services\OrderWorkflow;
use Waimart\Services\StationReturnService;
use Waimart\Tests\Integration\PlacesOrders;

/**
 * Pickup station arrivals and returns: station staff mark a parcel as arrived (the customer is told the station, the
 * code, to bring ID and the last day), a reminder goes the day before, and a parcel not collected within the hold
 * period is returned automatically (order cancelled, refund recorded when paid, stock held until received back).
 */
final class StationReturnsHttpTest extends FeatureTestCase
{
    use PlacesOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFulfilment();
    }

    protected function tearDown(): void
    {
        $this->tearDownFulfilment();
        parent::tearDown();
    }

    /** A pickup order on its way to the station (being prepared). @return array<string,mixed> */
    private function onItsWay(string $payment = 'cod'): array
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 2]], $payment, 'pickup', ['customer_phone' => '0745111222', 'customer_email' => 'pickup.buyer@example.com']);
        if ($payment !== 'cod') {
            $this->db->exec("UPDATE orders SET payment_status = 'verified' WHERE id = ?", [$o['id']]);
        }
        $wf = new OrderWorkflow($this->db);
        $wf->transition((int) $o['id'], 'confirmed', ['type' => 'staff', 'id' => $this->staffUser()]);
        $wf->transition((int) $o['id'], 'processing', ['type' => 'staff', 'id' => $this->staffUser()]);
        return $o + ['variant_id' => $v];
    }

    private function message(int $orderId, string $template, string $channel): string
    {
        return (string) $this->db->value('SELECT body FROM notifications WHERE order_id = ? AND template = ? AND channel = ? ORDER BY id DESC LIMIT 1', [$orderId, $template, $channel]);
    }

    /** @return array{on_hand:int,reserved:int} */
    private function stock(int $variantId): array
    {
        return array_map('intval', $this->db->one('SELECT on_hand, reserved FROM inventory WHERE variant_id = ?', [$variantId]));
    }

    public function testStationStaffMarkArrivalAndTheCustomerIsToldWhereWhatToBringAndUntilWhen(): void
    {
        $o = $this->onItsWay();
        $this->loginStaff('delivery_logistics');   // station staff
        $list = $this->get('/admin/collections?tab=arriving')['body'];
        $this->assertStringContainsString($o['order_number'], $list, 'the parcel is listed as on its way');

        $r = $this->post('/admin/collections/' . $o['id'] . '/arrived', [], '/admin/collections?tab=arriving');
        $this->assertStringContainsString('is ready for collection', $this->follow($r)['body']);
        $this->assertSame('ready_for_collection', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));

        $sms = $this->message((int) $o['id'], 'order_ready_for_collection', 'sms');
        $email = $this->message((int) $o['id'], 'order_ready_for_collection', 'email');
        $by = date('D j M', strtotime('+7 days'));
        foreach ([$sms, $email] as $m) {
            $this->assertStringContainsString('Station', $m, 'the station');
            $this->assertStringContainsString('Moi Ave', $m);
            $this->assertStringContainsString('ID', $m, 'bring ID');
            $this->assertStringContainsString($by, $m, 'the last day to collect');
            $this->assertStringContainsString('returned', $m, 'what happens otherwise');
            $this->assertStringContainsString('[redacted]', $m, 'the code is never stored in the log');
        }
        $this->assertStringContainsString('national ID or passport', $email);
        $this->assertLessThanOrEqual(160 * 2, mb_strlen($sms), 'fits in two SMS');

        // Station staff can now send a lost code again (it goes to the customer only).
        $this->assertContains($this->post('/admin/collections/' . $o['id'] . '/reissue', [], '/admin/collections/' . $o['id'])['status'], [302, 303]);
        $this->assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM notifications WHERE order_id = ? AND template = 'order_ready_for_collection' AND channel = 'sms'", [$o['id']]),
            'a new code was sent');
        $this->assertStringContainsString($by, $this->message((int) $o['id'], 'order_ready_for_collection', 'sms'), 'same last day as before');
    }

    public function testReminderTheDayBeforeThenAutomaticReturnWithRefundAndStockHeldUntilReceived(): void
    {
        $o = $this->onItsWay('mpesa_till');
        (new StationReturnService($this->db))->arrived((int) $o['id'], $this->staffUser());
        $before = $this->stock($o['variant_id']);
        $this->assertSame(2, $before['reserved']);
        $svc = new StationReturnService($this->db);

        // Day 6: one reminder, never twice.
        $this->db->exec('UPDATE order_fulfilment SET ready_at = NOW() - INTERVAL 145 HOUR WHERE order_id = ?', [$o['id']]);
        $this->assertSame(1, $svc->remindDue());
        $this->assertSame(0, $svc->remindDue(), 'only once');
        $this->assertStringContainsString('Collect by', $this->message((int) $o['id'], 'order_collection_reminder', 'sms'));
        $this->assertSame(0, $svc->returnDue(), 'not yet due');

        // Day 8: returned automatically through the scheduled job.
        $this->db->exec('UPDATE order_fulfilment SET ready_at = NOW() - INTERVAL 8 DAY WHERE order_id = ?', [$o['id']]);
        $stats = (new \Waimart\Services\ExpiryService($this->db))->run();
        $this->assertSame(1, $stats['station_returns']);
        $order = $this->db->one('SELECT * FROM orders WHERE id = ?', [$o['id']]);
        $this->assertSame('cancelled', $order['status']);
        $this->assertSame('Not collected within 7 days: returned to the warehouse', $order['cancel_reason']);
        $this->assertSame('refund_due', $order['payment_status'], 'paid by M-Pesa: refund owed');
        $this->assertSame($order['grand_total'], $this->db->value('SELECT amount FROM refunds WHERE order_id = ?', [$o['id']]));
        $this->assertSame('invalidated', $this->db->value('SELECT collection_code_status FROM order_fulfilment WHERE order_id = ?', [$o['id']]), 'the code no longer works');
        $this->assertStringContainsString('was not collected within 7 days', $this->message((int) $o['id'], 'order_not_collected', 'sms'));
        $this->assertStringContainsString('refund', $this->message((int) $o['id'], 'order_not_collected', 'email'));
        $this->assertSame($before, $this->stock($o['variant_id']), 'stock stays held while the parcel is on its way back');
        $this->assertSame(0, $svc->returnDue(), 'returned once');

        // Received back at the warehouse: the stock is available again.
        $this->loginStaff('delivery_logistics');
        $list = $this->get('/admin/collections?tab=returns')['body'];
        $this->assertStringContainsString($o['order_number'], $list);
        $this->assertStringContainsString('automatic', $list);
        $this->assertStringContainsString('Refund due', $list);
        $rid = (int) $this->db->value('SELECT id FROM station_returns WHERE order_id = ?', [$o['id']]);
        $r = $this->post("/admin/collections/returns/$rid/received", [], '/admin/collections?tab=returns');
        $this->assertStringContainsString('Received back', $this->follow($r)['body']);
        $this->assertSame(['on_hand' => 5, 'reserved' => 0], $this->stock($o['variant_id']));
        $this->assertSame('received', $this->db->value('SELECT status FROM station_returns WHERE id = ?', [$rid]));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'order.station_return_received' AND entity_id = ?", [$o['id']]));
    }

    public function testStaffCanSendAParcelBackEarlyButNotAnOrderThatIsNotWaiting(): void
    {
        $o = $this->onItsWay();
        (new StationReturnService($this->db))->arrived((int) $o['id'], $this->staffUser());
        $this->loginStaff('delivery_logistics');
        $desk = '/admin/collections/' . $o['id'];
        $this->assertStringContainsString('Send it back to the warehouse', $this->get($desk)['body']);
        $r = $this->post("$desk/return", ['reason' => 'refused', 'note' => 'Customer called: no longer needed'], $desk);
        $this->assertStringContainsString('is being returned to the warehouse', $this->follow($r)['body']);
        $this->assertSame('cancelled', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
        $this->assertSame('cod_pending', $this->db->value('SELECT payment_status FROM orders WHERE id = ?', [$o['id']]), 'cash order: nothing to refund');
        $this->assertNull($this->db->value('SELECT id FROM refunds WHERE order_id = ?', [$o['id']]));

        $other = $this->onItsWay();   // still on its way: cannot be "returned from the station"
        $this->post('/admin/collections/' . $other['id'] . '/return', ['reason' => 'refused'], '/admin/collections/' . $other['id']);
        $this->assertSame('processing', $this->db->value('SELECT status FROM orders WHERE id = ?', [$other['id']]));
    }

    public function testStaffWithoutTheCollectionDeskCannotUseIt(): void
    {
        $o = $this->onItsWay();
        $this->loginStaff('content_marketing');
        $this->assertSame(403, $this->post('/admin/collections/' . $o['id'] . '/arrived', [], '/admin')['status']);
        $this->assertSame('processing', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
    }
}
