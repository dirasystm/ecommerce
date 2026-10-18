<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Services\CollectionService;
use Waimart\Services\OrderWorkflow;
use Waimart\Tests\Integration\PlacesOrders;

final class PickupHttpTest extends FeatureTestCase
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

    private function processingPickupOrder(): array
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'cod', 'pickup', ['customer_phone' => '0745123123']);
        $wf = new OrderWorkflow($this->db);
        $wf->transition((int) $o['id'], 'confirmed', ['type' => 'staff', 'id' => $this->staffUser()]);
        $wf->transition((int) $o['id'], 'processing', ['type' => 'staff', 'id' => $this->staffUser()]);
        return $o + ['variant_id' => $v];
    }

    public function testDeskFlowWithPermissionsAndNoBypass(): void
    {
        $o = $this->processingPickupOrder();
        $page = '/admin/orders/' . $o['id'];

        $this->loginStaff('manager');
        $this->post("$page/status", ['to' => 'ready_for_collection'], $page);
        $this->assertSame('ready_for_collection', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
        // The generic status endpoint cannot mark collected.
        $r = $this->post("$page/status", ['to' => 'collected'], $page);
        $this->assertStringContainsString('not available', $this->follow($r)['body']);
        $this->post('/logout', [], '/admin');

        $this->loginStaff('support');
        $this->assertSame(403, $this->get('/admin/collections')['status'], 'support has no orders.collect');
        $this->post('/logout', [], '/admin');

        $this->loginStaff('fulfilment_staff');
        $desk = '/admin/collections/' . $o['id'];
        $this->assertStringContainsString($o['order_number'], $this->get('/admin/collections')['body']);
        $wrong = $this->post($desk, ['code' => 'ZZZZ ZZZZ', 'id_checked' => '1'], $desk);
        $this->assertStringContainsString('not valid for this order', $this->follow($wrong)['body']);

        $code = (string) CollectionService::codeFor((array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$o['id']]));
        $ok = $this->post($desk, ['code' => $code, 'id_checked' => '1'], $desk);
        $this->assertStringContainsString('collected. Stock has been deducted', $this->follow($ok)['body']);
        $this->assertSame(['on_hand' => 4, 'reserved' => 0], $this->levels($o['variant_id']));
    }

    public function testCodeVisibleOnlyToOwnerOrPrivateLinkHolders(): void
    {
        $o = $this->processingPickupOrder();
        (new CollectionService($this->db))->markReady((int) $o['id'], $this->staffUser());
        $code = (string) CollectionService::codeFor((array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$o['id']]));
        $shown = substr($code, 0, 4) . ' ' . substr($code, 4);

        // Order number + phone lookup: status visible, code NOT shown.
        $this->post('/track-order', ['order_number' => $o['order_number'], 'phone' => '0745123123'], '/track-order');
        $page = $this->get('/track/' . $o['order_number'])['body'];
        $this->assertStringContainsString('Ready for collection', $page);
        $this->assertStringNotContainsString($shown, $page);
        $this->assertStringContainsString('was sent to the phone number', $page);
    }

    public function testAdminFulfilmentScreensArePermissionedAndPublicDirectoryUpdates(): void
    {
        $this->loginStaff('support');
        $this->assertSame(403, $this->get('/admin/pickup-points')['status']);
        $this->assertSame(403, $this->get('/admin/delivery-zones')['status']);
        $this->post('/logout', [], '/admin');

        $this->loginStaff('manager');
        $r = $this->post('/admin/pickup-points', ['code' => 'ELD-CBD-01', 'name' => 'Eldoret Town Station', 'county_id' => '27', 'town' => 'Eldoret',
            'address' => 'Uganda Road', 'opening_hours' => 'Mon–Sat 8–6', 'pickup_fee' => '0', 'cod_allowed' => '1', 'is_active' => '1'], '/admin/pickup-points/new');
        $this->assertSame(303, $r['status']);
        $this->assertStringContainsString('Eldoret Town Station', $this->get('/pickup-stations')['body']);

        $id = (int) $this->db->value("SELECT id FROM pickup_points WHERE code = 'ELD-CBD-01'");
        $this->post("/admin/pickup-points/$id/toggle", ['active' => '0'], '/admin/pickup-points');
        $this->assertStringNotContainsString('Eldoret Town Station', $this->get('/pickup-stations')['body']);

        $this->post('/admin/delivery-zones', ['_form' => 'zone', 'name' => 'Rift', 'fee' => '450', 'min_days' => '2', 'max_days' => '5', 'cod_allowed' => '1', 'is_active' => '1'], '/admin/delivery-zones/new');
        $zone = (int) $this->db->value("SELECT id FROM delivery_zones WHERE name = 'Rift'");
        $this->post("/admin/delivery-zones/$zone/locations", ['county_id' => '27', 'town' => ''], "/admin/delivery-zones/$zone/edit");
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM delivery_zone_locations WHERE zone_id = ?', [$zone]));
    }
}
