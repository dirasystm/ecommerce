<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\ValidationException;
use Waimart\Services\CodService;
use Waimart\Services\CollectionService;
use Waimart\Services\OrderWorkflow;
use Waimart\Services\RefundService;
use Waimart\Services\ReturnService;
use Waimart\Services\ReviewService;

/** Returns with inspection (C7, C9 #9), refunds, verified reviews (Phase 11). */
final class ReturnsRefundsReviewsTest extends DbTestCase
{
    use PlacesOrders;

    private int $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFulfilment();
        $this->staff = $this->staffUser();
    }

    protected function tearDown(): void
    {
        $this->tearDownFulfilment();
        parent::tearDown();
    }

    private function delivered(array $lines, ?int $userId = null): array
    {
        $o = $this->placeOrder($lines, 'cod');
        if ($userId) {
            $this->db->exec('UPDATE orders SET user_id = ? WHERE id = ?', [$userId, $o['id']]);
        }
        $wf = new OrderWorkflow($this->db);
        foreach (['confirmed', 'processing', 'packed', 'dispatched', 'delivered'] as $to) {
            $wf->transition((int) $o['id'], $to, ['type' => 'staff', 'id' => $this->staff]);
        }
        (new CodService($this->db))->record((int) $o['id'], (string) $o['grand_total'], $this->staff, null);
        return (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$o['id']]);
    }

    private function items(int $orderId): array
    {
        return array_column($this->db->all('SELECT * FROM order_items WHERE order_id = ?', [$orderId]), null, 'variant_id');
    }

    private function refused(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail("Expected refusal: $needle");
        } catch (ValidationException $e) {
            $this->assertStringContainsString($needle, json_encode($e->errors) . $e->getMessage());
        }
    }

    /** C9 #9: a returned product passes or fails inspection. */
    public function testScenario9InspectionDecidesRestockAndRefundIsSeparate(): void
    {
        $a = $this->makeVariant(10, '1000.00')['variant_id'];
        $b = $this->makeVariant(10, '500.00')['variant_id'];
        $o = $this->delivered([[$a, 2], [$b, 1]]);
        $deliveredAt = $this->db->value('SELECT delivered_at FROM order_fulfilment WHERE order_id = ?', [$o['id']]);
        $this->assertSame([8, 9], [$this->levels($a)['on_hand'], $this->levels($b)['on_hand']]);
        $it = $this->items((int) $o['id']);

        $svc = new ReturnService($this->db);
        $ret = $svc->request((int) $o['id'], [$it[$a]['id'] => 1, $it[$b]['id'] => 1], 'defective', 'Kettle does not heat', null);
        $this->assertSame('return_requested', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
        $this->assertSame([8, 9], [$this->levels($a)['on_hand'], $this->levels($b)['on_hand']], 'no automatic restock on request');

        $svc->approve((int) $ret['id'], $this->staff, null);
        $this->refused(fn () => $svc->inspect((int) $ret['id'], 1, 'restock', null, $this->staff), 'after the return has been received');
        $svc->receive((int) $ret['id'], $this->staff);
        $this->assertSame([8, 9], [$this->levels($a)['on_hand'], $this->levels($b)['on_hand']], 'receiving does not restock either');

        $ri = array_column($this->db->all('SELECT ri.id, oi.variant_id FROM return_items ri JOIN order_items oi ON oi.id = ri.order_item_id WHERE ri.return_id = ?', [$ret['id']]), 'id', 'variant_id');
        $svc->inspect((int) $ret['id'], (int) $ri[$a], 'restock', 'Sealed, resaleable', $this->staff);   // passes
        $svc->inspect((int) $ret['id'], (int) $ri[$b], 'write_off', 'Water damage', $this->staff);       // fails
        $this->assertSame(9, $this->levels($a)['on_hand'], 'passed item restocked');
        $this->assertSame(9, $this->levels($b)['on_hand'], 'failed item NOT restocked');
        $this->assertSame(['return_restock', 'return_writeoff'], array_column($this->db->all(
            "SELECT movement_type FROM stock_movements WHERE return_item_id IS NOT NULL ORDER BY movement_type"), 'movement_type'));
        $this->refused(fn () => $svc->inspect((int) $ret['id'], (int) $ri[$a], 'restock', null, $this->staff), 'already been inspected');
        $this->assertSame(9, $this->levels($a)['on_hand'], 'never restocked twice');

        $this->refused(fn () => $svc->complete((int) $ret['id'], $this->staff, '9999', null), 'cannot exceed');
        $this->refused(fn () => $svc->complete((int) $ret['id'], $this->staff, '0', null), 'no refund');
        $svc->complete((int) $ret['id'], $this->staff, '1000', 'Refund for the kettle only; radio damaged by customer');

        $after = (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$o['id']]);
        $this->assertSame(['delivered', 'refund_due'], [$after['status'], $after['payment_status']], 'partial return → back to delivered');
        $this->assertSame($deliveredAt, $this->db->value('SELECT delivered_at FROM order_fulfilment WHERE order_id = ?', [$o['id']]), 'original delivery time preserved');
        $this->assertSame('1000.00', $this->db->value("SELECT amount FROM refunds WHERE return_id = ?", [$ret['id']]));
        $this->assertSame([1, 1], [(int) $this->items((int) $o['id'])[$a]['returned_qty'], (int) $this->items((int) $o['id'])[$b]['returned_qty']]);

        // The remaining unit can be returned later; once everything is back the order is "returned".
        $ret2 = $svc->request((int) $o['id'], [$it[$a]['id'] => 1], 'changed_mind', null, null);
        $svc->approve((int) $ret2['id'], $this->staff, null);
        $svc->receive((int) $ret2['id'], $this->staff);
        $ri2 = (int) $this->db->value('SELECT id FROM return_items WHERE return_id = ?', [$ret2['id']]);
        $svc->inspect((int) $ret2['id'], $ri2, 'restock', null, $this->staff);
        $svc->complete((int) $ret2['id'], $this->staff, '1000', null);
        $this->assertSame('returned', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
        $this->assertSame(10, $this->levels($a)['on_hand']);
    }

    public function testEligibilityAndQuantityRules(): void
    {
        $v = $this->makeVariant(10)['variant_id'];
        $notYet = $this->placeOrder([[$v, 1]], 'cod');
        $svc = new ReturnService($this->db);
        $item = (int) $this->db->value('SELECT id FROM order_items WHERE order_id = ?', [$notYet['id']]);
        $this->refused(fn () => $svc->request((int) $notYet['id'], [$item => 1], 'damaged', null, null), 'no longer be returned');

        $o = $this->delivered([[$v, 2]]);
        $it = (int) $this->db->value('SELECT id FROM order_items WHERE order_id = ?', [$o['id']]);
        $this->refused(fn () => $svc->request((int) $o['id'], [$it => 3], 'damaged', null, null), 'up to what you received');
        $this->refused(fn () => $svc->request((int) $o['id'], [$it => 1], 'other', '', null), 'tell us more');
        $svc->request((int) $o['id'], [$it => 1], 'damaged', null, null);
        $this->assertFalse($svc->eligible((array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$o['id']])), 'one open return at a time');

        $old = $this->delivered([[$v, 1]]);
        $this->db->exec('UPDATE orders SET completed_at = NOW() - INTERVAL 8 DAY WHERE id = ?', [$old['id']]);
        $this->assertFalse($svc->eligible((array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$old['id']])), 'outside the 7-day window');
    }

    public function testRejectedReturnRestoresPickupOrderWithoutTouchingStock(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'cod', 'pickup');
        $wf = new OrderWorkflow($this->db);
        foreach (['confirmed', 'processing'] as $to) {
            $wf->transition((int) $o['id'], $to, ['type' => 'staff', 'id' => $this->staff]);
        }
        $cs = new CollectionService($this->db);
        $cs->markReady((int) $o['id'], $this->staff);
        $cs->verifyAndCollect((int) $o['id'], (string) CollectionService::codeFor((array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$o['id']])), $this->staff, true);
        $stock = $this->levels($v);

        $svc = new ReturnService($this->db);
        $ret = $svc->request((int) $o['id'], [(int) $this->db->value('SELECT id FROM order_items WHERE order_id = ?', [$o['id']]) => 1], 'changed_mind', null, null);
        $this->refused(fn () => $svc->reject((int) $ret['id'], $this->staff, ''), 'Explain');
        $svc->reject((int) $ret['id'], $this->staff, 'Opened hygiene product');
        $this->assertSame('collected', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
        $this->assertSame($stock, $this->levels($v), 'no stock movement on rejection');
    }

    public function testRefundPaymentSettlesOrder(): void
    {
        $v = $this->makeVariant(5, '1000.00')['variant_id'];
        $o = $this->delivered([[$v, 1]]);
        $svc = new ReturnService($this->db);
        $ret = $svc->request((int) $o['id'], [(int) $this->db->value('SELECT id FROM order_items WHERE order_id = ?', [$o['id']]) => 1], 'defective', null, null);
        $svc->approve((int) $ret['id'], $this->staff, null);
        $svc->receive((int) $ret['id'], $this->staff);
        $svc->inspect((int) $ret['id'], (int) $this->db->value('SELECT id FROM return_items WHERE return_id = ?', [$ret['id']]), 'write_off', null, $this->staff);
        $svc->complete((int) $ret['id'], $this->staff, '1000', null);
        $refund = (int) $this->db->value('SELECT id FROM refunds WHERE order_id = ?', [$o['id']]);

        $r = new RefundService($this->db);
        $this->refused(fn () => $r->markPaid($refund, $this->staff, 'carrier_pigeon', 'X1234', null), 'how the refund was paid');
        $this->refused(fn () => $r->markPaid($refund, $this->staff, 'mpesa', '!', null), 'reference');
        $r->markPaid($refund, $this->staff, 'mpesa', 'sgh7k2l9xq', 'B2C');
        $this->assertSame(['paid', 'SGH7K2L9XQ'], array_values($this->db->one('SELECT status, reference FROM refunds WHERE id = ?', [$refund])));
        $this->assertSame('refunded', $this->db->value('SELECT payment_status FROM orders WHERE id = ?', [$o['id']]));
        $this->refused(fn () => $r->markPaid($refund, $this->staff, 'mpesa', 'AGAIN1234', null), 'already paid');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'refund.paid'"));
    }

    public function testCancellingTheOnlyRefundRestoresCodStatus(): void
    {
        $v = $this->makeVariant(5, '1000.00')['variant_id'];
        $o = $this->delivered([[$v, 1]]);
        $this->db->exec("UPDATE orders SET payment_status = 'refund_due' WHERE id = ?", [$o['id']]);
        $id = $this->db->insert('refunds', ['order_id' => $o['id'], 'amount' => '100', 'reason' => 'Goodwill', 'created_by' => $this->staff]);
        (new RefundService($this->db))->cancel($id, $this->staff, 'Customer declined');
        $this->assertSame('cod_collected', $this->db->value('SELECT payment_status FROM orders WHERE id = ?', [$o['id']]));
    }

    public function testVerifiedPurchaseReviewsAndModeratedRatings(): void
    {
        $buyer = $this->db->insert('users', ['email' => 'b@x.co', 'password_hash' => 'x', 'first_name' => 'Baraka', 'last_name' => 'Otieno']);
        $other = $this->db->insert('users', ['email' => 'o@x.co', 'password_hash' => 'x', 'first_name' => 'O', 'last_name' => 'X']);
        $p = $this->makeVariant(10);
        $o = $this->delivered([[$p['variant_id'], 1]], $buyer);
        $item = (int) $this->db->value('SELECT id FROM order_items WHERE order_id = ?', [$o['id']]);
        $svc = new ReviewService($this->db);

        $this->refused(fn () => $svc->submit($other, $item, ['rating' => '5']), 'only items you have received');
        $pending = $this->placeOrder([[$p['variant_id'], 1]], 'cod');
        $this->db->exec('UPDATE orders SET user_id = ? WHERE id = ?', [$buyer, $pending['id']]);
        $this->refused(fn () => $svc->submit($buyer, (int) $this->db->value('SELECT id FROM order_items WHERE order_id = ?', [$pending['id']]), ['rating' => '5']), 'only items you have received');
        $this->refused(fn () => $svc->submit($buyer, $item, ['rating' => '9']), 'Rating');

        $id = $svc->submit($buyer, $item, ['rating' => '4', 'title' => 'Good value', 'body' => 'Works well']);
        $this->refused(fn () => $svc->submit($buyer, $item, ['rating' => '5']), 'only items you have received');
        $this->assertSame([0, '0.00'], [(int) $this->db->value('SELECT rating_count FROM products WHERE id = ?', [$p['product_id']]), $this->db->value('SELECT rating_avg FROM products WHERE id = ?', [$p['product_id']])], 'pending reviews do not count');

        $svc->moderate($id, 'approved', $this->staff, null);
        $this->assertSame([1, '4.00'], [(int) $this->db->value('SELECT rating_count FROM products WHERE id = ?', [$p['product_id']]), $this->db->value('SELECT rating_avg FROM products WHERE id = ?', [$p['product_id']])]);
        $this->assertSame('Baraka', $svc->approvedFor($p['product_id'])[0]['first_name']);
        $svc->moderate($id, 'rejected', $this->staff, 'Contains phone number');
        $this->assertSame(0, (int) $this->db->value('SELECT rating_count FROM products WHERE id = ?', [$p['product_id']]));
    }
}
