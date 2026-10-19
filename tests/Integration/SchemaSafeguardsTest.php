<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

/**
 * Verifies the database itself enforces the critical invariants (C1, C3, C2 idempotency,
 * payment-code reuse, money types), independent of application code.
 */
final class SchemaSafeguardsTest extends DbTestCase
{
    public function testAllRequiredTablesExist(): void
    {
        $tables = array_column($this->db->all(
            "SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()"
        ), 't');
        foreach ([
            'pickup_points', 'orders', 'order_items', 'order_fulfilment', 'inventory', 'stock_reservations',
            'stock_movements', 'payment_submissions', 'payment_verifications', 'order_status_history',
            'order_access_tokens', 'return_requests', 'return_items', 'refunds', 'audit_logs', 'cod_collections',
            'order_resolutions', 'delivery_zones', 'delivery_zone_locations', 'carts', 'cart_items', 'reviews',
        ] as $t) {
            $this->assertContains($t, $tables, "Missing table $t");
        }
    }

    public function testAllMoneyColumnsAreDecimal(): void
    {
        $bad = $this->db->all(
            "SELECT table_name, column_name, data_type FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND (column_name REGEXP 'price|total|amount|fee|subtotal|threshold|discount_value|max_discount')
                AND column_name <> 'low_stock_threshold'   -- a unit count, not money
                AND data_type <> 'decimal'"
        );
        $this->assertSame([], $bad, 'Money columns must be DECIMAL');
    }

    public function testReferenceDataSeeded(): void
    {
        $this->assertSame(47, (int) $this->db->value('SELECT COUNT(*) FROM counties'));
        $this->assertSame('30', $this->db->value("SELECT setting_value FROM settings WHERE setting_key = 'reservation_minutes'"));
        $this->assertSame(8, (int) $this->db->value('SELECT COUNT(*) FROM roles'), 'six original roles plus Content & Marketing and Delivery & Logistics (024)');
    }

    public function testInventoryCannotGoNegative(): void
    {
        $v = $this->makeVariant(1);
        $this->assertDbRejects(
            fn () => $this->db->exec('UPDATE inventory SET on_hand = -1 WHERE variant_id = ?', [$v['variant_id']]),
            'ck_inv_on_hand'
        );
    }

    public function testReservedCannotExceedOnHand(): void
    {
        $v = $this->makeVariant(2);
        $this->db->exec('UPDATE inventory SET reserved = 2 WHERE variant_id = ?', [$v['variant_id']]);
        $this->assertDbRejects(
            fn () => $this->db->exec('UPDATE inventory SET reserved = reserved + 1 WHERE variant_id = ?', [$v['variant_id']]),
            'ck_inv_available'
        );
    }

    public function testOnlyOneActiveReservationPerOrderItem(): void
    {
        $v = $this->makeVariant(5);
        $o = $this->makeOrder($v['variant_id'], 1);
        $row = ['order_id' => $o['order_id'], 'order_item_id' => $o['item_id'], 'variant_id' => $v['variant_id'], 'quantity' => 1];

        $first = $this->db->insert('stock_reservations', $row);
        $this->assertDbRejects(fn () => $this->db->insert('stock_reservations', $row), 'uq_sr_active_item');

        // Once released, a fresh reservation for the same item is allowed (late-payment re-reservation).
        $this->db->exec("UPDATE stock_reservations SET status = 'released' WHERE id = ?", [$first]);
        $second = $this->db->insert('stock_reservations', $row);
        $this->assertGreaterThan($first, $second);
    }

    public function testStockMovementRecordedOncePerReservationTransition(): void
    {
        $v = $this->makeVariant(5);
        $o = $this->makeOrder($v['variant_id'], 1);
        $resId = $this->db->insert('stock_reservations', [
            'order_id' => $o['order_id'], 'order_item_id' => $o['item_id'], 'variant_id' => $v['variant_id'], 'quantity' => 1,
        ]);
        $mv = ['variant_id' => $v['variant_id'], 'movement_type' => 'release', 'reserved_delta' => -1,
               'on_hand_after' => 5, 'reserved_after' => 0, 'reservation_id' => $resId, 'actor_type' => 'system'];
        $this->db->insert('stock_movements', $mv);
        $this->assertDbRejects(fn () => $this->db->insert('stock_movements', $mv), 'uq_sm_reservation');
    }

    public function testDuplicateCheckoutKeyRejected(): void
    {
        $v = $this->makeVariant(5);
        $this->makeOrder($v['variant_id'], 1, ['idempotency_key' => str_repeat('a', 43)]);
        $this->assertDbRejects(
            fn () => $this->makeOrder($v['variant_id'], 1, ['idempotency_key' => str_repeat('a', 43)]),
            'uq_order_idem'
        );
    }

    public function testMpesaCodeCannotBeReusedAcrossOrders(): void
    {
        $v = $this->makeVariant(5);
        $a = $this->makeOrder($v['variant_id']);
        $b = $this->makeOrder($v['variant_id']);
        $sub = ['method' => 'mpesa_till', 'transaction_code' => 'SGH7K2L9XQ', 'payer_phone' => '254712345678', 'amount_claimed' => '1000.00'];
        $this->db->insert('payment_submissions', ['order_id' => $a['order_id']] + $sub);
        $this->assertDbRejects(fn () => $this->db->insert('payment_submissions', ['order_id' => $b['order_id']] + $sub), 'uq_ps_code');
    }

    public function testOrderItemReturnedCannotExceedFulfilled(): void
    {
        $v = $this->makeVariant(5);
        $o = $this->makeOrder($v['variant_id'], 2);
        $this->assertDbRejects(
            fn () => $this->db->exec('UPDATE order_items SET returned_qty = 1 WHERE id = ?', [$o['item_id']]),
            'ck_oi_returned'
        );
    }

    public function testDeliveryLocationBelongsToOneZone(): void
    {
        $z1 = $this->db->insert('delivery_zones', ['name' => 'Z1', 'fee' => '200', 'min_days' => 1, 'max_days' => 2]);
        $z2 = $this->db->insert('delivery_zones', ['name' => 'Z2', 'fee' => '300', 'min_days' => 2, 'max_days' => 4]);
        $this->db->insert('delivery_zone_locations', ['zone_id' => $z1, 'county_id' => 47, 'town' => '']);
        $this->assertDbRejects(
            fn () => $this->db->insert('delivery_zone_locations', ['zone_id' => $z2, 'county_id' => 47, 'town' => '']),
            'uq_dzl_location'
        );
    }

    public function testTransactionRollsBackOnFailure(): void
    {
        $v = $this->makeVariant(3);
        try {
            $this->db->transaction(function ($db) use ($v) {
                $db->exec('UPDATE inventory SET reserved = 1 WHERE variant_id = ?', [$v['variant_id']]);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, (int) $this->db->value('SELECT reserved FROM inventory WHERE variant_id = ?', [$v['variant_id']]));
    }
}
