<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Crypto;
use Waimart\Core\Settings;
use Waimart\Services\CartService;
use Waimart\Services\OrderService;

/** Helpers for tests that need real orders created through OrderService. Use in DbTestCase subclasses. */
trait PlacesOrders
{
    protected function setUpFulfilment(): void
    {
        $zone = $this->db->insert('delivery_zones', ['name' => 'City', 'fee' => '200', 'min_days' => 1, 'max_days' => 2, 'cod_allowed' => 1]);
        $this->db->insert('delivery_zone_locations', ['zone_id' => $zone, 'county_id' => 47, 'town' => '']);
        $this->db->insert('pickup_points', ['code' => 'P1', 'name' => 'Station', 'county_id' => 47, 'town' => 'CBD', 'address' => 'Moi Ave', 'opening_hours' => '9-5']);
        Settings::set('mpesa_till_number', '123456');
    }

    protected function tearDownFulfilment(): void
    {
        Settings::set('mpesa_till_number', '');
    }

    /**
     * @param list<array{0:int,1:int}> $lines [variantId, qty]
     * @return array<string,mixed> the order row
     */
    protected function placeOrder(array $lines, string $payment = 'cod', string $method = 'delivery', array $extra = []): array
    {
        $cart = new CartService(null, null, $this->db);
        foreach ($lines as [$v, $q]) {
            $cart->add($v, $q);
        }
        $in = $extra + [
            'customer_name' => 'Test Buyer', 'customer_phone' => '07' . random_int(10000000, 99999999), 'customer_email' => 'buyer@example.com',
            'fulfilment_method' => $method, 'county_id' => '47', 'town' => 'Kilimani', 'location' => 'Test Rd',
            'pickup_point_id' => (string) $this->db->value('SELECT id FROM pickup_points LIMIT 1'),
            'payment_method' => $payment,
        ];
        return (new OrderService($this->db))->place($cart, $in, null, Crypto::token(32), '127.0.0.1')['order'];
    }

    /** @return array{on_hand:int,reserved:int} */
    protected function levels(int $variantId): array
    {
        return array_map('intval', $this->db->one('SELECT on_hand, reserved FROM inventory WHERE variant_id = ?', [$variantId]));
    }

    protected function assertInventoryConsistent(): void
    {
        $bad = $this->db->all(
            "SELECT i.variant_id, i.reserved, COALESCE(SUM(r.quantity), 0) AS active
               FROM inventory i LEFT JOIN stock_reservations r ON r.variant_id = i.variant_id AND r.status = 'active'
              GROUP BY i.variant_id, i.reserved HAVING i.reserved <> active"
        );
        $this->assertSame([], $bad, 'inventory.reserved must equal the sum of active reservations');
    }

    protected function staffUser(): int
    {
        return (int) ($this->db->value("SELECT id FROM users WHERE email = 'ops@waimart.test'")
            ?: $this->db->insert('users', ['email' => 'ops@waimart.test', 'password_hash' => 'x', 'first_name' => 'Ops', 'last_name' => 'Staff', 'is_staff' => 1]));
    }
}
