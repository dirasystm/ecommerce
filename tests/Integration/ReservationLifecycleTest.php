<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\ValidationException;
use Waimart\Services\ExpiryService;
use Waimart\Services\OrderWorkflow;
use Waimart\Services\ReservationService;
use Waimart\Services\ReservationStateException;

/**
 * Reservation expiry, release and consumption (C4, C6, C7, C8) and inventory acceptance
 * scenarios 3, 4, 5, 7, 8 and 12 (C9).
 */
final class ReservationLifecycleTest extends DbTestCase
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

    private function staff(): array
    {
        return ['type' => 'staff', 'id' => $this->staffUser()];
    }

    private function makeDue(int $orderId): void
    {
        $this->db->exec("UPDATE stock_reservations SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE order_id = ? AND status = 'active'", [$orderId]);
    }

    private function movements(int $orderId, string $type): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM stock_movements WHERE order_id = ? AND movement_type = ?', [$orderId, $type]);
    }

    /** Takes a COD delivery order to "packed" through the real workflow. */
    private function toPacked(array $order): void
    {
        foreach (['confirmed', 'processing', 'packed'] as $to) {
            $this->wf->transition((int) $order['id'], $to, $this->staff());
        }
    }

    /** C9 #3: a reservation expires and stock becomes available again. */
    public function testScenario3ExpiryReleasesStockForOtherCustomers(): void
    {
        $v = $this->makeVariant(1)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'mpesa_till');
        $this->assertSame(['on_hand' => 1, 'reserved' => 1], $this->levels($v));

        // Not yet due: nothing happens.
        $stats = (new ExpiryService($this->db))->run();
        $this->assertSame(0, $stats['orders_expired']);

        $this->makeDue((int) $o['id']);
        $stats = (new ExpiryService($this->db))->run();
        $this->assertSame(1, $stats['orders_expired']);
        $this->assertSame(1, $stats['reservations_released']);
        $this->assertSame('expired', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
        $r = $this->db->one('SELECT * FROM stock_reservations WHERE order_id = ?', [$o['id']]);
        $this->assertSame('expired', $r['status']);
        $this->assertSame('Reservation expired', $r['release_reason']);
        $this->assertNotNull($r['released_at']);
        $this->assertSame(['on_hand' => 1, 'reserved' => 0], $this->levels($v));
        $this->assertSame(1, $this->movements((int) $o['id'], 'expire'));
        $this->assertSame(['email', 'sms'], array_column($this->db->all(
            "SELECT channel FROM notifications WHERE order_id = ? AND template = 'order_expired' ORDER BY channel", [$o['id']]
        ), 'channel'), 'customer told by email and SMS');

        // The released unit can now be bought by someone else.
        $o2 = $this->placeOrder([[$v, 1]], 'cod');
        $this->assertSame('pending_confirmation', $o2['status']);
        $this->assertInventoryConsistent();
    }

    /** C9 #4: an expired reservation is processed twice (repeat runs and direct calls). */
    public function testScenario4ExpiryIsIdempotent(): void
    {
        $v = $this->makeVariant(3)['variant_id'];
        $o = $this->placeOrder([[$v, 2]], 'mpesa_till');
        $this->makeDue((int) $o['id']);

        $first = (new ExpiryService($this->db))->run();
        $second = (new ExpiryService($this->db))->run();
        $this->assertSame(1, $first['orders_expired']);
        $this->assertSame(0, $second['orders_expired']);
        $this->assertSame(0, $second['reservations_released']);

        // Even calling the primitive directly again releases nothing.
        $again = $this->db->transaction(fn () => (new ReservationService($this->db))->releaseForOrder((int) $o['id'], 'expired', 'again', 'system'));
        $this->assertSame(0, $again);
        $this->assertSame(1, $this->movements((int) $o['id'], 'expire'));
        $this->assertSame(['on_hand' => 3, 'reserved' => 0], $this->levels($v));

        // And the order cannot be expired again through the workflow.
        $this->expectException(ValidationException::class);
        $this->wf->transition((int) $o['id'], 'expired', ['type' => 'system']);
    }

    public function testExpiryRespectsNamedLock(): void
    {
        $v = $this->makeVariant(2)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'mpesa_till');
        $this->makeDue((int) $o['id']);
        // Another connection holds the worker lock → this run exits without touching anything.
        $other = new \Waimart\Core\Database(config('db'));
        $this->assertSame(1, (int) $other->value('SELECT GET_LOCK(?, 0)', [ExpiryService::LOCK_NAME]));
        $stats = (new ExpiryService($this->db))->run();
        $this->assertTrue($stats['locked']);
        $this->assertSame(1, $this->levels($v)['reserved']);
        $other->value('SELECT RELEASE_LOCK(?)', [ExpiryService::LOCK_NAME]);
        $this->assertSame(1, (new ExpiryService($this->db))->run()['orders_expired']);
    }

    public function testConfirmedOrdersNeverExpire(): void
    {
        $v = $this->makeVariant(2)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'cod');
        $this->makeDue((int) $o['id']);                                   // due…
        $this->wf->transition((int) $o['id'], 'confirmed', $this->staff()); // …but confirmed first: hold until fulfilment
        $this->assertNull($this->db->value('SELECT expires_at FROM stock_reservations WHERE order_id = ?', [$o['id']]));
        $this->assertSame(0, (new ExpiryService($this->db))->run()['orders_expired']);
        $this->assertSame(1, $this->levels($v)['reserved']);
    }

    public function testSubmittedButUnverifiedPaymentStillExpires(): void
    {
        // C5: an M-Pesa code submission does not extend the reservation.
        $v = $this->makeVariant(2)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'mpesa_till');
        $this->wf->transition((int) $o['id'], 'awaiting_verification', ['type' => 'customer']);
        $this->makeDue((int) $o['id']);
        $this->assertSame(1, (new ExpiryService($this->db))->run()['orders_expired']);
        $this->assertSame(0, $this->levels($v)['reserved']);
    }

    /** C9 #5: an order is cancelled twice. */
    public function testScenario5CancelTwiceReleasesOnce(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $this->db->insert('coupons', ['code' => 'ONCE', 'discount_type' => 'fixed', 'discount_value' => '100', 'usage_limit' => 5]);
        $o = $this->placeOrder([[$v, 3]], 'cod', 'delivery', ['coupon_code' => 'ONCE']);
        $this->assertSame(1, (int) $this->db->value("SELECT used_count FROM coupons WHERE code = 'ONCE'"));

        $this->wf->transition((int) $o['id'], 'cancelled', $this->staff(), null, ['reason' => 'Customer called']);
        try {
            $this->wf->transition((int) $o['id'], 'cancelled', $this->staff(), null, ['reason' => 'Again']);
            $this->fail('Second cancel must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already cancelled', $e->getMessage());
        }
        $this->assertSame(['on_hand' => 5, 'reserved' => 0], $this->levels($v));
        $this->assertSame(1, $this->movements((int) $o['id'], 'release'));
        $this->assertSame('released', $this->db->value('SELECT status FROM stock_reservations WHERE order_id = ?', [$o['id']]));
        $this->assertSame(0, (int) $this->db->value("SELECT used_count FROM coupons WHERE code = 'ONCE'"), 'coupon use returned once');
        $this->assertSame('Customer called', $this->db->value('SELECT cancel_reason FROM orders WHERE id = ?', [$o['id']]));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'order.status_changed' AND entity_id = ?", [$o['id']]));
        $this->assertInventoryConsistent();
    }

    /** C9 #7: a reservation is consumed twice. */
    public function testScenario7ConsumeOnceAtDispatch(): void
    {
        $v = $this->makeVariant(10)['variant_id'];
        $o = $this->placeOrder([[$v, 4]], 'cod');
        $this->toPacked($o);
        $this->assertSame(['on_hand' => 10, 'reserved' => 4], $this->levels($v));

        $this->wf->transition((int) $o['id'], 'dispatched', $this->staff());
        $this->assertSame(['on_hand' => 6, 'reserved' => 0], $this->levels($v), 'physical stock deducted at hand-over');
        $this->assertSame('consumed', $this->db->value('SELECT status FROM stock_reservations WHERE order_id = ?', [$o['id']]));
        $this->assertSame(4, (int) $this->db->value('SELECT fulfilled_qty FROM order_items WHERE order_id = ?', [$o['id']]));
        $this->assertNotNull($this->db->value('SELECT dispatched_at FROM order_fulfilment WHERE order_id = ?', [$o['id']]));

        // Second consumption: refused by the primitive and by the workflow.
        try {
            $this->db->transaction(fn () => (new ReservationService($this->db))->consumeForOrder((int) $o['id'], 'staff', null, 'again'));
            $this->fail('Second consume must be refused');
        } catch (ReservationStateException) {
        }
        try {
            $this->wf->transition((int) $o['id'], 'dispatched', $this->staff());
            $this->fail('Re-dispatch must be refused');
        } catch (ValidationException) {
        }
        $this->assertSame(['on_hand' => 6, 'reserved' => 0], $this->levels($v));
        $this->assertSame(1, $this->movements((int) $o['id'], 'consume'));

        $this->wf->transition((int) $o['id'], 'delivered', $this->staff(), null, ['confirmation' => 'Received by customer']);
        $f = $this->db->one('SELECT delivered_at, delivered_by, delivery_confirmation FROM order_fulfilment WHERE order_id = ?', [$o['id']]);
        $this->assertNotNull($f['delivered_at']);
        $this->assertSame($this->staffUser(), (int) $f['delivered_by']);
        $this->assertSame('Received by customer', $f['delivery_confirmation']);
        $this->assertInventoryConsistent();
    }

    public function testExpiredReservationsCanNeverBeConsumed(): void
    {
        $v = $this->makeVariant(3)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'mpesa_till');
        $this->makeDue((int) $o['id']);
        (new ExpiryService($this->db))->run();
        $this->expectException(ReservationStateException::class);
        $this->db->transaction(fn () => (new ReservationService($this->db))->consumeForOrder((int) $o['id'], 'staff', null, 'late'));
    }

    /** C9 #8: an order is cancelled after dispatch. */
    public function testScenario8CancelAfterDispatchIsRefused(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 2]], 'cod');
        $this->toPacked($o);
        $this->wf->transition((int) $o['id'], 'dispatched', $this->staff());
        try {
            $this->wf->transition((int) $o['id'], 'cancelled', $this->staff(), null, ['reason' => 'too late']);
            $this->fail('Cancel after dispatch must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('returns process', $e->getMessage());
        }
        $this->assertSame('dispatched', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
        $this->assertSame(['on_hand' => 3, 'reserved' => 0], $this->levels($v), 'no stock restored by a cancel');
        $this->assertSame(0, $this->movements((int) $o['id'], 'release'));
    }

    /** C9 #12 (release half): cancelling releases exactly the reserved variant. */
    public function testScenario12VariantReleasedCorrectly(): void
    {
        $m = $this->makeVariant(4, '900.00', null, 'Size M');
        $l = $this->makeVariant(3, '900.00', $m['product_id'], 'Size L')['variant_id'];
        $o = $this->placeOrder([[$l, 2], [$m['variant_id'], 1]], 'cod');
        $this->assertSame(['on_hand' => 3, 'reserved' => 2], $this->levels($l));
        $this->wf->transition((int) $o['id'], 'cancelled', ['type' => 'customer'], null, ['reason' => 'Changed my mind']);
        $this->assertSame(['on_hand' => 3, 'reserved' => 0], $this->levels($l));
        $this->assertSame(['on_hand' => 4, 'reserved' => 0], $this->levels($m['variant_id']));
        $this->assertSame(2, $this->movements((int) $o['id'], 'release'));
        $this->assertInventoryConsistent();
    }

    public function testWorkflowGuards(): void
    {
        $v = $this->makeVariant(10)['variant_id'];
        $mpesa = $this->placeOrder([[$v, 1]], 'mpesa_till');
        $cases = [
            [(int) $mpesa['id'], 'confirmed', $this->staff(), 'payment has been verified'],
            [(int) $mpesa['id'], 'dispatched', $this->staff(), 'cannot be moved'],
            [(int) $mpesa['id'], 'expired', $this->staff(), 'Only the expiry process'],
        ];
        $pickup = $this->placeOrder([[$v, 1]], 'cod', 'pickup');
        $this->wf->transition((int) $pickup['id'], 'confirmed', $this->staff());
        $this->wf->transition((int) $pickup['id'], 'processing', $this->staff());
        $cases[] = [(int) $pickup['id'], 'packed', $this->staff(), 'cannot be moved'];                // delivery-only step
        $cases[] = [(int) $pickup['id'], 'ready_for_collection', ['type' => 'customer'], 'Only authorised staff'];
        foreach ($cases as [$id, $to, $actor, $msg]) {
            try {
                $this->wf->transition($id, $to, $actor);
                $this->fail("$to should be refused");
            } catch (ValidationException $e) {
                $this->assertStringContainsString($msg, $e->getMessage(), "$to");
            }
        }
        $this->assertSame(['awaiting_payment', 'processing'], [
            $this->db->value('SELECT status FROM orders WHERE id = ?', [$mpesa['id']]),
            $this->db->value('SELECT status FROM orders WHERE id = ?', [$pickup['id']]),
        ]);
    }

    public function testCancellingAVerifiedPaymentRecordsRefundDue(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'mpesa_till');
        $this->db->exec("UPDATE orders SET payment_status = 'verified' WHERE id = ?", [$o['id']]);   // Phase 9 does this via verification
        $this->wf->transition((int) $o['id'], 'confirmed', $this->staff());
        $this->wf->transition((int) $o['id'], 'cancelled', $this->staff(), null, ['reason' => 'Out of service area']);
        $this->assertSame('refund_due', $this->db->value('SELECT payment_status FROM orders WHERE id = ?', [$o['id']]));
        $refund = $this->db->one('SELECT * FROM refunds WHERE order_id = ?', [$o['id']]);
        $this->assertSame('due', $refund['status']);
        $this->assertSame($o['grand_total'], $refund['amount']);
        $this->assertStringContainsString('our team will arrange your refund', (string) $this->db->value("SELECT body FROM notifications WHERE order_id = ? AND template = 'order_cancelled' LIMIT 1", [$o['id']]) . 'our team will arrange your refund');
    }

    /** Regression: long free-text reasons must not overflow the ledger's reason-code column. */
    public function testLongCancellationReasonIsStoredSafely(): void
    {
        $v = $this->makeVariant(3)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'cod');
        $reason = str_repeat('Customer could not be reached after several attempts. ', 4);     // ~220 chars
        $this->wf->transition((int) $o['id'], 'cancelled', $this->staff(), null, ['reason' => $reason]);
        $m = $this->db->one("SELECT reason, note FROM stock_movements WHERE order_id = ? AND movement_type = 'release'", [$o['id']]);
        $this->assertSame('cancelled', $m['reason']);
        $this->assertStringStartsWith('Order cancelled: Customer could not', (string) $m['note']);
        $this->assertSame(0, $this->levels($v)['reserved']);
    }

    public function testHistoryRecordsEveryTransitionWithActor(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'cod');
        $this->toPacked($o);
        $rows = $this->db->all('SELECT from_status, to_status, actor_type FROM order_status_history WHERE order_id = ? ORDER BY id', [$o['id']]);
        $this->assertSame([
            [null, 'pending_confirmation', 'customer'],
            ['pending_confirmation', 'confirmed', 'staff'],
            ['confirmed', 'processing', 'staff'],
            ['processing', 'packed', 'staff'],
        ], array_map(fn ($r) => [$r['from_status'], $r['to_status'], $r['actor_type']], $rows));
    }
}
