<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\Crypto;
use Waimart\Core\Settings;
use Waimart\Services\CartService;
use Waimart\Services\OrderService;
use Waimart\Services\OrderWorkflow;

/** Phase 7 over HTTP: seller order pages, roles, isolation and the admin's per-part actions. */
final class SellerFulfilmentHttpTest extends FeatureTestCase
{
    private int $seller;

    protected function setUp(): void
    {
        parent::setUp();
        $zone = $this->db->insert('delivery_zones', ['name' => 'City', 'fee' => '250', 'min_days' => 1, 'max_days' => 2, 'cod_allowed' => 1]);
        $this->db->insert('delivery_zone_locations', ['zone_id' => $zone, 'county_id' => 47, 'town' => '']);
        Settings::set('mpesa_till_number', '123456');
        $this->seller = $this->db->insert('sellers', ['name' => 'Kamba Crafts', 'slug' => 'kamba-crafts']);
    }

    protected function tearDown(): void
    {
        Settings::set('mpesa_till_number', '');
        parent::tearDown();
    }

    private function member(string $role, ?int $sellerId = null): void
    {
        $email = "$role-" . bin2hex(random_bytes(3)) . '@example.com';
        $u = $this->db->insert('users', ['email' => $email, 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT), 'first_name' => 'S', 'last_name' => 'M']);
        $this->db->insert('seller_users', ['seller_id' => $sellerId ?? $this->seller, 'user_id' => $u, 'role' => $role]);
        $this->post('/logout', [], '/');
        $this->post('/login', ['email' => $email, 'password' => 'Secret123'], '/login');
    }

    /** A paid, confirmed order with one marketplace part; returns [order id, seller order id]. */
    private function order(bool $confirm = true): array
    {
        $v = $this->makeVariant(5, '2000.00', null, 'Default', $this->seller)['variant_id'];
        $cart = new CartService(null, null, $this->db);
        $cart->add($v, 1);
        $o = (new OrderService($this->db))->place($cart, ['customer_name' => 'Achieng Odhiambo', 'customer_phone' => '0733444555', 'customer_email' => 'achieng@example.com',
            'fulfilment_method' => 'delivery', 'county_id' => '47', 'town' => 'Kilimani', 'location' => 'Secret Street 9', 'payment_method' => 'mpesa_till'],
            null, Crypto::token(32), '127.0.0.1')['order'];
        if ($confirm) {
            $this->db->exec("UPDATE orders SET payment_status = 'verified' WHERE id = ?", [$o['id']]);
            (new OrderWorkflow($this->db))->transition((int) $o['id'], 'confirmed', ['type' => 'system'], 'Paid');
        }
        return [(int) $o['id'], (int) $this->db->value('SELECT id FROM seller_orders WHERE order_id = ?', [$o['id']])];
    }

    public function testSellerWorksThroughAnOrderWithoutSeeingCustomerContacts(): void
    {
        [, $so] = $this->order();
        $this->member('owner');
        $list = $this->get('/seller/orders')['body'];
        $this->assertStringContainsString('-1', $list);
        $page = $this->get("/seller/orders/$so");
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Accept order', $page['body']);
        $this->assertStringContainsString('Achieng', $page['body'], 'first name only');
        foreach (['Odhiambo', '0733 444 555', '254733444555', 'achieng@example.com', 'Secret Street 9'] as $private) {
            $this->assertStringNotContainsString($private, $page['body'], "seller must not see: $private");
        }
        foreach (['accepted', 'processing', 'packed'] as $to) {
            $this->assertSame(303, $this->post("/seller/orders/$so/status", ['to' => $to], "/seller/orders/$so")['status']);
        }
        $this->assertSame('packed', $this->db->value('SELECT status FROM seller_orders WHERE id = ?', [$so]));
        $this->post("/seller/orders/$so/status", ['to' => 'handed_over'], "/seller/orders/$so");
        $this->assertSame('packed', $this->db->value('SELECT status FROM seller_orders WHERE id = ?', [$so]), 'sellers cannot confirm their own hand-over');
    }

    public function testOtherSellersAndUnconfirmedOrdersAreInvisible(): void
    {
        [, $so] = $this->order();
        [, $unpaid] = $this->order(false);
        $other = $this->db->insert('sellers', ['name' => 'Other Shop', 'slug' => 'other-shop']);
        $this->member('owner', $other);
        $this->assertSame(404, $this->get("/seller/orders/$so")['status']);
        $this->assertSame(404, $this->post("/seller/orders/$so/status", ['to' => 'accepted'], '/seller/orders')['status']);
        $this->assertSame('pending_acceptance', $this->db->value('SELECT status FROM seller_orders WHERE id = ?', [$so]));
        $this->member('owner');
        $this->assertSame(404, $this->get("/seller/orders/$unpaid")['status'], 'not shown before payment/confirmation');
    }

    public function testStaffMembersPrepareButOnlyOwnersAndManagersAcceptOrDecline(): void
    {
        [, $so] = $this->order();
        $this->member('staff');
        $this->assertSame(403, $this->post("/seller/orders/$so/status", ['to' => 'accepted'], "/seller/orders/$so")['status']);
        $this->assertSame(403, $this->post("/seller/orders/$so/status", ['to' => 'rejected', 'reason' => 'No stock left'], "/seller/orders/$so")['status']);
        $this->db->exec("UPDATE seller_orders SET status = 'accepted' WHERE id = ?", [$so]);
        $this->assertSame(303, $this->post("/seller/orders/$so/status", ['to' => 'processing'], "/seller/orders/$so")['status']);
        $this->db->exec("UPDATE sellers SET status = 'suspended' WHERE id = ?", [$this->seller]);
        $this->assertSame(403, $this->post("/seller/orders/$so/status", ['to' => 'packed'], "/seller/orders/$so")['status'], 'suspended stores are read-only');
        $this->assertSame(200, $this->get("/seller/orders/$so")['status']);
    }

    public function testAdminStepsNeedTheRightPermission(): void
    {
        [$oid, $so] = $this->order();
        $this->db->exec("UPDATE seller_orders SET status = 'packed' WHERE id = ?", [$so]);
        $this->loginStaff('support');      // orders.view + orders.cancel, but not orders.manage / orders.deliver
        $this->assertSame(403, $this->post("/admin/seller-orders/$so/status", ['to' => 'handed_over'], "/admin/orders/$oid")['status']);
        $this->post('/logout', [], '/');
        $this->loginStaff('fulfilment_staff');
        $page = $this->get("/admin/orders/$oid")['body'];
        $this->assertStringContainsString('Received from seller', $page);
        $this->assertStringContainsString('Seller orders', $this->get('/admin/seller-orders')['body']);
        $this->assertSame(303, $this->post("/admin/seller-orders/$so/status", ['to' => 'handed_over'], "/admin/orders/$oid")['status']);
        $this->assertSame(303, $this->post("/admin/seller-orders/$so/status", ['to' => 'dispatched', 'tracking_reference' => 'BODA-12'], "/admin/orders/$oid")['status']);
        $this->assertSame(['dispatched', 'BODA-12'], array_values((array) $this->db->one('SELECT status, tracking_reference FROM seller_orders WHERE id = ?', [$so])));
        $this->assertSame('dispatched', $this->db->value('SELECT status FROM orders WHERE id = ?', [$oid]));

        $this->post('/logout', [], '/');
        $this->member('owner');
        $this->assertSame(403, $this->post("/admin/seller-orders/$so/status", ['to' => 'delivered'], '/')['status'], 'sellers are not staff');
        $this->assertSame(403, $this->get('/admin/seller-orders')['status']);
    }
}
