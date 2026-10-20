<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Repositories\OrderRepository;
use Waimart\Services\OrderJourney;
use Waimart\Services\OrderNotifier;
use Waimart\Services\OrderWorkflow;
use Waimart\Services\PaymentService;

/** Order tracking: the steps, the office handling each one, arrival estimates and the message sent at every step. */
final class OrderJourneyTest extends DbTestCase
{
    use PlacesOrders;

    private OrderWorkflow $wf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFulfilment();
        $this->wf = new OrderWorkflow($this->db);
    }

    protected function tearDown(): void
    {
        $this->tearDownFulfilment();
        parent::tearDown();
    }

    private function staffWithRole(string $role): array
    {
        $id = $this->db->insert('users', ['email' => $role . '-' . bin2hex(random_bytes(3)) . '@waimart.test', 'password_hash' => 'x',
            'first_name' => 'Jane', 'last_name' => 'Officer', 'is_staff' => 1]);
        $this->db->exec('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?', [$id, $role]);
        return ['type' => 'staff', 'id' => $id];
    }

    /** @return array<string,mixed> */
    private function journey(int $orderId): array
    {
        $repo = new OrderRepository($this->db);
        return OrderJourney::build($repo->details((array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$orderId])));
    }

    /** @return array<string,array<string,mixed>> step key => step */
    private function steps(array $j): array
    {
        return array_column($j['steps'], null, 'key');
    }

    private function move(int $orderId, string $to, array $actor): void
    {
        $order = $this->wf->transition($orderId, $to, $actor);
        OrderNotifier::statusChanged($order);
    }

    public function testWorkingDaysSkipSundaysAndRangesReadNaturally(): void
    {
        $sat = new \DateTimeImmutable('2026-10-24 09:00');                       // Saturday
        $this->assertSame('2026-10-26', OrderJourney::addWorkingDays($sat, 1)->format('Y-m-d'), 'Saturday + 1 = Monday');
        $this->assertSame('2026-10-31', OrderJourney::addWorkingDays($sat, 6)->format('Y-m-d'));
        $this->assertSame('2026-10-26', OrderJourney::addWorkingDays(new \DateTimeImmutable('2026-10-25'), 0)->format('Y-m-d'), 'never a Sunday');
        $this->assertSame('Thu 22 – Sat 24 Oct', OrderJourney::formatRange(new \DateTimeImmutable('2026-10-22'), new \DateTimeImmutable('2026-10-24')));
        $this->assertSame('Fri 30 Oct – Mon 2 Nov', OrderJourney::formatRange(new \DateTimeImmutable('2026-10-30'), new \DateTimeImmutable('2026-11-02')));
        $this->assertSame('Thu 22 Oct', OrderJourney::formatRange(new \DateTimeImmutable('2026-10-22'), new \DateTimeImmutable('2026-10-22')));
    }

    public function testDeliveryJourneyShowsEachOfficeAndAMessageAtEveryStep(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'cod');
        $id = (int) $o['id'];

        $j = $this->journey($id);
        $s = $this->steps($j);
        $this->assertSame(['placed', 'payment', 'processing', 'packed', 'dispatched', 'out', 'delivered'], array_keys($s));
        $this->assertSame(['done', 'current', 'upcoming'], [$s['placed']['state'], $s['payment']['state'], $s['processing']['state']]);
        $this->assertSame('Customer care', $s['payment']['office']);
        $this->assertStringContainsString('may call you', $s['payment']['text']);
        $this->assertSame('Dispatch & logistics', $s['dispatched']['office'], 'upcoming steps say who will handle them');
        $this->assertSame('estimated', $j['eta']['state']);
        $this->assertStringContainsString('(once confirmed today)', $j['eta']['text']);
        $this->assertNotSame('7', (new \DateTimeImmutable($j['eta']['from']))->format('N'), 'never a Sunday');

        $this->move($id, 'confirmed', $this->staffWithRole('support'));
        $this->move($id, 'processing', $this->staffWithRole('fulfilment_staff'));
        $this->move($id, 'packed', $this->staffWithRole('fulfilment_staff'));
        $this->db->exec("UPDATE seller_orders SET tracking_reference = 'G4S-55' WHERE order_id = ?", [$id]);
        $this->move($id, 'dispatched', $this->staffWithRole('delivery_logistics'));

        $j = $this->journey($id);
        $s = $this->steps($j);
        $this->assertSame(['Customer care', 'Fulfilment centre', 'Fulfilment centre', 'Dispatch & logistics'],
            [$s['payment']['office'], $s['processing']['office'], $s['packed']['office'], $s['dispatched']['office']], 'offices from the staff roles');
        $this->assertNotNull($s['dispatched']['at']);
        $this->assertSame(['G4S-55'], $s['dispatched']['tracking']);
        $this->assertSame('current', $s['out']['state']);
        $this->assertStringNotContainsString('once confirmed', $j['eta']['text']);
        $this->assertStringNotContainsString('Officer', json_encode($j), 'staff names are never shown');

        // One message per step, each with the arrival estimate.
        $msgs = $this->db->all("SELECT template, channel, body FROM notifications WHERE order_id = ? AND template IN ('order_confirmed','order_processing','order_packed','order_dispatched') ORDER BY id", [$id]);
        $this->assertSame(['order_confirmed', 'order_processing', 'order_packed', 'order_dispatched'], array_values(array_unique(array_column($msgs, 'template'))));
        $this->assertEqualsCanonicalizing(['email', 'sms'], array_values(array_unique(array_column($msgs, 'channel'))));
        foreach ($msgs as $m) {
            $this->assertStringContainsString('Estimated arrival:', $m['body'], $m['template'] . ' ' . $m['channel']);
        }
        $this->assertStringContainsString('Tracking number: G4S-55.', (string) end($msgs)['body']);

        $this->move($id, 'out_for_delivery', $this->staffWithRole('delivery_logistics'));
        $this->assertSame('Today', $this->journey($id)['eta']['text']);
        $this->assertNotEmpty($this->db->value("SELECT id FROM notifications WHERE order_id = ? AND template = 'order_out_for_delivery'", [$id]));

        $this->move($id, 'delivered', $this->staffWithRole('delivery_logistics'));
        $j = $this->journey($id);
        $this->assertSame('done', $j['eta']['state']);
        $this->assertSame(array_fill(0, 7, 'done'), array_column($j['steps'], 'state'));
    }

    public function testMpesaPaymentStepIsTheFinanceOfficeAndSkippedStepsCountAsDone(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'mpesa_till');
        $s = $this->steps($this->journey((int) $o['id']));
        $this->assertSame('You', $s['payment']['office'], 'waiting for the customer to pay');

        $pay = new PaymentService($this->db);
        $sub = $pay->submit((int) $o['id'], ['transaction_code' => 'JRN1234567', 'payer_phone' => '0712345678', 'amount' => (string) $o['grand_total']], '1.1.1.1');
        $s = $this->steps($this->journey((int) $o['id']));
        $this->assertSame('Finance office', $s['payment']['office']);
        $this->assertStringContainsString('verifying your M-Pesa payment', $s['payment']['text']);
        $this->assertStringContainsString('Payment code submitted', $s['payment']['sub']);

        $finance = $this->staffWithRole('finance');
        $pay->verify((int) $sub['id'], (int) $finance['id'], (string) $o['grand_total'], null);
        $s = $this->steps($this->journey((int) $o['id']));
        $this->assertSame(['done', 'Finance office'], [$s['payment']['state'], $s['payment']['office']]);

        foreach (['processing', 'packed', 'dispatched', 'delivered'] as $to) {    // straight to delivered: "out for delivery" skipped
            $this->wf->transition((int) $o['id'], $to, $this->staffWithRole('manager'));
        }
        $s = $this->steps($this->journey((int) $o['id']));
        $this->assertSame(['done', null], [$s['out']['state'], $s['out']['at']]);
        $this->assertSame('Operations office', $s['delivered']['office']);
    }

    public function testCancelledOrdersStopAndPickupOrdersShowTheStationAndCollectBy(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $c = $this->placeOrder([[$v, 1]], 'cod');
        $this->wf->transition((int) $c['id'], 'cancelled', ['type' => 'customer'], null, ['reason' => 'Changed my mind']);
        $j = $this->journey((int) $c['id']);
        $this->assertSame('Order cancelled', $j['alert']['title']);
        $this->assertNull($j['eta']);
        $this->assertSame(['done', 'stopped', 'stopped'], array_slice(array_column($j['steps'], 'state'), 0, 3));

        $p = $this->placeOrder([[$v, 1]], 'cod', 'pickup');
        $j = $this->journey((int) $p['id']);
        $s = $this->steps($j);
        $this->assertSame(['placed', 'payment', 'processing', 'ready', 'collected'], array_keys($s));
        $this->assertSame('Pickup station: Station', $s['ready']['office']);
        $this->assertSame(['pickup', 'Expected ready for collection'], [$j['eta']['kind'], $j['eta']['label']]);

        $staff = $this->staffWithRole('fulfilment_staff');
        foreach (['confirmed', 'processing', 'ready_for_collection'] as $to) {
            $this->move((int) $p['id'], $to, $staff);
        }
        $j = $this->journey((int) $p['id']);
        $this->assertSame('ready', $j['eta']['state']);
        $this->assertStringStartsWith('Collect by ', $j['eta']['text']);
        $this->assertSame('current', $this->steps($j)['collected']['state']);
        $this->assertNotEmpty($this->db->value("SELECT id FROM notifications WHERE order_id = ? AND template = 'order_processing' AND body LIKE '%Expected ready for collection:%'", [$p['id']]));
    }
}
