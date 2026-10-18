<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Crypto;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Services\CartService;
use Waimart\Services\InsufficientStockException;
use Waimart\Services\OrderService;
use Waimart\Services\ReservationService;

/**
 * Transactional order placement + reservation creation (C2, C3) and inventory acceptance
 * scenarios 2, 10, 11 and 12 (C9). Scenario 1 (true concurrency) is in ConcurrencyTest.
 */
final class OrderPlacementTest extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $zone = $this->db->insert('delivery_zones', ['name' => 'City', 'fee' => '200', 'min_days' => 1, 'max_days' => 2, 'cod_allowed' => 1]);
        $this->db->insert('delivery_zone_locations', ['zone_id' => $zone, 'county_id' => 47, 'town' => '']);
        Settings::set('mpesa_till_number', '123456');
        ReservationService::$faultInjector = null;
    }

    protected function tearDown(): void
    {
        ReservationService::$faultInjector = null;
        Settings::set('mpesa_till_number', '');
        Settings::set('cod_auto_confirm', '0');
        parent::tearDown();
    }

    private function input(array $o = []): array
    {
        return $o + [
            'customer_name' => 'Wanjiru Kamau', 'customer_phone' => '0712345678', 'customer_email' => 'w@example.com',
            'fulfilment_method' => 'delivery', 'county_id' => '47', 'town' => 'Kilimani', 'location' => 'Argwings Kodhek Rd',
            'payment_method' => 'mpesa_till',
        ];
    }

    private function cart(array $lines): CartService
    {
        $cart = new CartService(null, null, $this->db);
        foreach ($lines as [$variantId, $qty]) {
            $cart->add($variantId, $qty);
        }
        return $cart;
    }

    private function place(CartService $cart, array $in = [], ?string $key = null): array
    {
        return (new OrderService($this->db))->place($cart, $this->input($in), null, $key ?? Crypto::token(32), '127.0.0.1');
    }

    private function levels(int $variantId): array
    {
        return array_map('intval', $this->db->one('SELECT on_hand, reserved FROM inventory WHERE variant_id = ?', [$variantId]));
    }

    /** Invariant check: reserved counter equals the sum of active reservations, for every variant. */
    private function assertInventoryConsistent(): void
    {
        $bad = $this->db->all(
            "SELECT i.variant_id, i.reserved, COALESCE(SUM(r.quantity), 0) AS active
               FROM inventory i LEFT JOIN stock_reservations r ON r.variant_id = i.variant_id AND r.status = 'active'
              GROUP BY i.variant_id, i.reserved HAVING i.reserved <> active"
        );
        $this->assertSame([], $bad, 'inventory.reserved must equal active reservations');
    }

    public function testPlacementCreatesOrderSnapshotsAndReservationsTogether(): void
    {
        $a = $this->makeVariant(10, '1000.00')['variant_id'];
        $b = $this->makeVariant(5, '250.00')['variant_id'];
        $cart = $this->cart([[$a, 2], [$b, 1]]);

        $r = $this->place($cart, ['grand_total' => '1', 'delivery_fee' => '0']);   // tampered values ignored
        $o = $r['order'];
        $this->assertTrue($r['created']);
        $this->assertMatchesRegularExpression('/^WM-[A-Z0-9]{8}$/', $o['order_number']);
        $this->assertSame('awaiting_payment', $o['status']);
        $this->assertSame('unpaid', $o['payment_status']);
        $this->assertSame(['2250.00', '200.00', '2450.00'], [$o['subtotal'], $o['delivery_fee'], $o['grand_total']]);

        // Item snapshots at the price paid.
        $items = $this->db->all('SELECT variant_id, quantity, unit_price, line_total FROM order_items WHERE order_id = ? ORDER BY variant_id', [$o['id']]);
        $this->assertSame([[$a, 2, '1000.00', '2000.00'], [$b, 1, '250.00', '250.00']],
            array_map(fn ($i) => [(int) $i['variant_id'], (int) $i['quantity'], $i['unit_price'], $i['line_total']], $items));
        $this->assertSame('Argwings Kodhek Rd', $this->db->value('SELECT location FROM order_fulfilment WHERE order_id = ?', [$o['id']]));

        // Reservations + counters + ledger.
        $this->assertSame([10, 2], array_values($this->levels($a)), 'on_hand unchanged, reserved +2');
        $this->assertSame([5, 1], array_values($this->levels($b)));
        $res = $this->db->all("SELECT * FROM stock_reservations WHERE order_id = ? AND status = 'active'", [$o['id']]);
        $this->assertCount(2, $res);
        $minutes = (strtotime($res[0]['expires_at']) - time()) / 60;
        $this->assertEqualsWithDelta(30, $minutes, 1.5, 'default M-Pesa window is 30 minutes');
        $this->assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM stock_movements WHERE order_id = ? AND movement_type = 'reserve' AND reservation_id IS NOT NULL", [$o['id']]));

        // History, private access token (hash only), cart emptied.
        $this->assertSame('Order placed', $this->db->value('SELECT note FROM order_status_history WHERE order_id = ?', [$o['id']]));
        $this->assertSame(64, strlen((string) $this->db->value('SELECT token_hash FROM order_access_tokens WHERE order_id = ?', [$o['id']])));
        $this->assertNotSame($r['access_token'], $this->db->value('SELECT token_hash FROM order_access_tokens WHERE order_id = ?', [$o['id']]));
        $this->assertSame(0, $cart->count());
        $this->assertInventoryConsistent();
    }

    /** C9 #2: one customer holds the last unit; another tries to check out. */
    public function testScenario2LastUnitAlreadyReserved(): void
    {
        $v = $this->makeVariant(1)['variant_id'];
        $cartA = $this->cart([[$v, 1]]);
        $cartB = $this->cart([[$v, 1]]);                       // both carts were filled while stock was available

        $this->place($cartA);
        try {
            $this->place($cartB, ['customer_phone' => '0799000111']);
            $this->fail('Second checkout must fail');
        } catch (ValidationException $e) {
            // Either rejected at cart revalidation or under the lock — never oversold.
            $this->assertTrue(isset($e->errors['_cart']) || isset($e->errors['_stock']));
        }
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM orders'));
        $this->assertSame([1, 1], array_values($this->levels($v)));
        $this->assertSame(1, $cartB->count(), 'loser keeps their cart to fix it');
        $this->assertInventoryConsistent();
    }

    /** The locked recheck (C2 step 6) reports every short item by name. */
    public function testLockedRecheckReportsShortItemsByName(): void
    {
        $a = $this->makeVariant(1)['variant_id'];
        $b = $this->makeVariant(0)['variant_id'];
        $c = $this->makeVariant(9)['variant_id'];
        $svc = new ReservationService($this->db);
        try {
            $this->db->transaction(fn () => $svc->lockAndCheck([
                ['variant_id' => $c, 'quantity' => 2, 'name' => 'Plenty'],
                ['variant_id' => $a, 'quantity' => 3, 'name' => 'Kettle'],
                ['variant_id' => $b, 'quantity' => 1, 'name' => 'Radio'],
            ]));
            $this->fail('Expected shortage');
        } catch (InsufficientStockException $e) {
            $this->assertSame([$a, $b], array_column($e->shortages, 'variant_id'));
            $this->assertStringContainsString('"Kettle" (only 1 left)', $e->getMessage());
            $this->assertStringContainsString('"Radio" (sold out)', $e->getMessage());
        }
    }

    public function testReservationPrimitivesRequireTransaction(): void
    {
        $this->expectException(\LogicException::class);
        (new ReservationService($this->db))->lockAndCheck([['variant_id' => 1, 'quantity' => 1, 'name' => 'x']]);
    }

    /** C9 #10: a database error during reservation creation rolls back everything. */
    public function testScenario10FailureMidReservationRollsBackEverything(): void
    {
        $a = $this->makeVariant(5)['variant_id'];
        $b = $this->makeVariant(5)['variant_id'];
        $cart = $this->cart([[$a, 1], [$b, 2]]);
        ReservationService::$faultInjector = function (int $variantId, int $index) {
            if ($index === 1) {
                throw new \PDOException('Simulated database failure during reservation');
            }
        };
        try {
            $this->place($cart);
            $this->fail('Expected failure');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('Simulated', $e->getMessage());
        }
        foreach (['orders', 'order_items', 'order_fulfilment', 'stock_reservations', 'order_status_history', 'order_access_tokens'] as $t) {
            $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM `$t`"), "$t must be empty after rollback");
        }
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM stock_movements WHERE movement_type = 'reserve'"));
        $this->assertSame([5, 0], array_values($this->levels($a)), 'first reservation was rolled back too');
        $this->assertSame([5, 0], array_values($this->levels($b)));
        $this->assertSame(3, $cart->count(), 'cart untouched so the customer can retry');
    }

    /** C9 #11: a network retry repeats the checkout request with the same key. */
    public function testScenario11RetryWithSameKeyReturnsSameOrder(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $cart = $this->cart([[$v, 2]]);
        $key = Crypto::token(32);
        $first = $this->place($cart, [], $key);
        $retry = $this->place($cart, [], $key);
        $this->assertTrue($first['created']);
        $this->assertFalse($retry['created']);
        $this->assertSame($first['order']['order_number'], $retry['order']['order_number']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM orders'));
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM stock_reservations'));
        $this->assertSame(2, $this->levels($v)['reserved']);
    }

    /** C9 #12: reservations are made at variant level. */
    public function testScenario12VariantLevelReservation(): void
    {
        $base = $this->makeVariant(4, '900.00', null, 'Size M');
        $large = $this->makeVariant(3, '900.00', $base['product_id'], 'Size L')['variant_id'];
        $cart = $this->cart([[$large, 2]]);
        $o = $this->place($cart)['order'];
        $this->assertSame([3, 2], array_values($this->levels($large)));
        $this->assertSame([4, 0], array_values($this->levels($base['variant_id'])), 'sibling variant untouched');
        $this->assertSame('Size L', $this->db->value('SELECT variant_name FROM order_items WHERE order_id = ?', [$o['id']]));
        $this->assertSame($large, (int) $this->db->value('SELECT variant_id FROM stock_reservations WHERE order_id = ?', [$o['id']]));
    }

    public function testCodOrdersUseTheirOwnWindowOrAutoConfirm(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->place($this->cart([[$v, 1]]), ['payment_method' => 'cod'])['order'];
        $this->assertSame('pending_confirmation', $o['status']);
        $this->assertSame('cod_pending', $o['payment_status']);
        $exp = (string) $this->db->value('SELECT expires_at FROM stock_reservations WHERE order_id = ?', [$o['id']]);
        $this->assertEqualsWithDelta(240, (strtotime($exp) - time()) / 60, 1.5);

        Settings::set('cod_auto_confirm', '1');
        $o2 = $this->place($this->cart([[$v, 1]]), ['payment_method' => 'cod', 'customer_phone' => '0700111222'])['order'];
        $this->assertSame('confirmed', $o2['status']);
        $this->assertNull($this->db->value('SELECT expires_at FROM stock_reservations WHERE order_id = ?', [$o2['id']]), 'held until fulfilment');
    }

    public function testCouponDiscountAndUsageLimit(): void
    {
        $v = $this->makeVariant(10, '2000.00')['variant_id'];
        $this->db->insert('coupons', ['code' => 'KARIBU10', 'discount_type' => 'percent', 'discount_value' => '10', 'max_discount' => '150', 'usage_limit' => 1]);

        $o = $this->place($this->cart([[$v, 1]]), ['coupon_code' => 'karibu10'])['order'];
        $this->assertSame('150.00', $o['discount_total'], '10% capped at 150');
        $this->assertSame('2050.00', $o['grand_total'], '2000 - 150 + 200 delivery');
        $this->assertSame(1, (int) $this->db->value('SELECT used_count FROM coupons'));

        try {
            $this->place($this->cart([[$v, 1]]), ['coupon_code' => 'KARIBU10', 'customer_phone' => '0700999888']);
            $this->fail('Usage limit must hold');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('coupon_code', $e->errors);
        }
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM orders'));
    }

    public function testInvalidKeyIsRejected(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $this->expectException(ValidationException::class);
        $this->place($this->cart([[$v, 1]]), [], 'not-a-valid-key');
    }
}
