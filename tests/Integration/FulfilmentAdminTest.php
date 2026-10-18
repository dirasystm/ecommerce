<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\ValidationException;
use Waimart\Services\DeliveryService;
use Waimart\Services\FulfilmentAdminService;

final class FulfilmentAdminTest extends DbTestCase
{
    use PlacesOrders;

    private FulfilmentAdminService $svc;
    private int $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new FulfilmentAdminService($this->db);
        $this->staff = $this->staffUser();
    }

    private function expectField(callable $fn, string $field): ValidationException
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors, json_encode($e->errors));
            return $e;
        }
        $this->fail("Expected error on $field");
    }

    private function pointInput(array $o = []): array
    {
        return $o + ['code' => 'nbo-test-01', 'name' => 'Test Station', 'county_id' => '47', 'town' => 'CBD', 'address' => 'Kimathi St',
            'opening_hours' => 'Mon–Sat 9–5', 'pickup_fee' => '0', 'cod_allowed' => '1', 'is_active' => '1'];
    }

    public function testZoneValidationAndAudit(): void
    {
        $base = ['name' => 'City', 'fee' => '200', 'min_days' => '1', 'max_days' => '2', 'is_active' => '1'];
        $id = $this->svc->saveZone(null, $base, $this->staff);
        $this->assertNull($this->db->value('SELECT free_delivery_threshold FROM delivery_zones WHERE id = ?', [$id]));
        $this->expectField(fn () => $this->svc->saveZone(null, ['max_days' => '0'] + $base + ['name' => 'X'], $this->staff), 'max_days');
        $this->expectField(fn () => $this->svc->saveZone(null, $base, $this->staff), 'name');           // duplicate
        $this->expectField(fn () => $this->svc->saveZone(null, ['fee' => '-5', 'name' => 'Neg'] + $base, $this->staff), 'fee');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'fulfilment.delivery_zone_created'"));
    }

    public function testLocationsBelongToOneZoneWithFriendlyMessage(): void
    {
        $a = $this->svc->saveZone(null, ['name' => 'A', 'fee' => '100', 'min_days' => '1', 'max_days' => '1', 'is_active' => '1'], $this->staff);
        $b = $this->svc->saveZone(null, ['name' => 'B', 'fee' => '300', 'min_days' => '2', 'max_days' => '4', 'is_active' => '1'], $this->staff);
        $this->svc->addZoneLocation($a, ['county_id' => '47', 'town' => ''], $this->staff);
        $this->svc->addZoneLocation($b, ['county_id' => '22', 'town' => 'Thika'], $this->staff);
        $e = $this->expectField(fn () => $this->svc->addZoneLocation($b, ['county_id' => '47', 'town' => ''], $this->staff), 'town');
        $this->assertStringContainsString('already covered by the "A" zone', $e->errors['town']);

        // Delivery quoting reflects the configuration immediately.
        $d = new DeliveryService($this->db);
        $this->assertSame('B', $d->quoteDelivery(22, 'thika', '100')['zone_name']);
        $this->assertNull($d->quoteDelivery(22, 'Ruiru', '100'));
        $loc = (int) $this->db->value('SELECT id FROM delivery_zone_locations WHERE zone_id = ?', [$b]);
        $this->svc->removeZoneLocation($b, $loc, $this->staff);
        $this->assertNull($d->quoteDelivery(22, 'Thika', '100'));
    }

    public function testPickupPointValidation(): void
    {
        $id = $this->svc->savePickupPoint(null, $this->pointInput(['phone' => '0712 345 678']), $this->staff);
        $p = $this->db->one('SELECT * FROM pickup_points WHERE id = ?', [$id]);
        $this->assertSame('NBO-TEST-01', $p['code']);
        $this->assertSame('254712345678', $p['phone']);
        $this->expectField(fn () => $this->svc->savePickupPoint(null, $this->pointInput(), $this->staff), 'code');                         // duplicate
        $this->expectField(fn () => $this->svc->savePickupPoint(null, $this->pointInput(['code' => 'bad code!']), $this->staff), 'code');
        $this->expectField(fn () => $this->svc->savePickupPoint(null, $this->pointInput(['code' => 'X2', 'map_url' => 'javascript:alert(1)']), $this->staff), 'map_url');
        $this->expectField(fn () => $this->svc->savePickupPoint(null, $this->pointInput(['code' => 'X3', 'latitude' => '123']), $this->staff), 'latitude');
        $this->expectField(fn () => $this->svc->savePickupPoint(null, $this->pointInput(['code' => 'X4', 'opening_hours' => '']), $this->staff), 'opening_hours');
    }

    public function testDeactivatedPointsLeaveCheckoutButOrdersKeepTheirSnapshot(): void
    {
        $this->setUpFulfilment();
        $id = $this->svc->savePickupPoint(null, $this->pointInput(), $this->staff);
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'cod', 'pickup', ['pickup_point_id' => (string) $id]);

        // Rename, move and deactivate the point after the order was placed.
        $this->svc->savePickupPoint($id, $this->pointInput(['name' => 'Renamed Station', 'address' => 'New Street 9']), $this->staff);
        $open = $this->svc->setPickupActive($id, false, $this->staff);
        $this->assertSame(1, $open, 'staff are told an open order still uses it');

        $snap = $this->db->one('SELECT pickup_name, pickup_address FROM order_fulfilment WHERE order_id = ?', [$o['id']]);
        $this->assertSame(['pickup_name' => 'Test Station', 'pickup_address' => 'Kimathi St'], $snap, 'B3.5 / E4 historical snapshot');
        $this->assertNotContains($id, array_map('intval', array_column((new DeliveryService($this->db))->pickupPoints(), 'id')));
        $this->assertNull((new DeliveryService($this->db))->quotePickup($id, '100'));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'fulfilment.pickup_deactivated'"));
        $this->tearDownFulfilment();
    }
}
