<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\Crypto;
use Waimart\Core\Settings;
use Waimart\Services\CartService;
use Waimart\Services\DisputeService;
use Waimart\Services\OrderService;
use Waimart\Services\OrderWorkflow;
use Waimart\Services\SellerOrderWorkflow;

/** Phase 9 over HTTP: customer cases, seller and admin desks, privacy, permissions and the reports. */
final class MarketplaceAfterSalesHttpTest extends FeatureTestCase
{
    private int $seller;
    private int $owner;
    private string $customerEmail;
    private int $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $zone = $this->db->insert('delivery_zones', ['name' => 'City', 'fee' => '250', 'min_days' => 1, 'max_days' => 2, 'cod_allowed' => 1]);
        $this->db->insert('delivery_zone_locations', ['zone_id' => $zone, 'county_id' => 47, 'town' => '']);
        Settings::set('mpesa_till_number', '123456');
        $this->seller = $this->db->insert('sellers', ['name' => 'Kamba Crafts', 'slug' => 'kamba-crafts']);
        $this->owner = $this->user('owner-' . bin2hex(random_bytes(3)) . '@example.com');
        $this->db->insert('seller_users', ['seller_id' => $this->seller, 'user_id' => $this->owner, 'role' => 'owner']);
        $this->customerEmail = 'achieng-' . bin2hex(random_bytes(3)) . '@example.com';
        $this->customer = $this->user($this->customerEmail, 'Achieng', 'Odhiambo');
    }

    protected function tearDown(): void
    {
        Settings::set('mpesa_till_number', '');
        parent::tearDown();
    }

    private function user(string $email, string $first = 'Juma', string $last = 'Mwangi'): int
    {
        return $this->db->insert('users', ['email' => $email, 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT), 'first_name' => $first, 'last_name' => $last]);
    }

    private function login(string $email): void
    {
        $this->post('/logout', [], '/');
        $this->post('/login', ['email' => $email, 'password' => 'Secret123'], '/login');
    }

    private function emailOf(int $userId): string
    {
        return (string) $this->db->value('SELECT email FROM users WHERE id = ?', [$userId]);
    }

    /** A paid order of the customer's, with the seller's part delivered. @return array{0:array<string,mixed>,1:int} */
    private function deliveredOrder(): array
    {
        $v = $this->makeVariant(5, '2000.00', null, 'Default', $this->seller)['variant_id'];
        $cart = new CartService(null, null, $this->db);
        $cart->add($v, 1);
        $o = (new OrderService($this->db))->place($cart, ['customer_name' => 'Achieng Odhiambo', 'customer_phone' => '0733444555', 'customer_email' => $this->customerEmail,
            'fulfilment_method' => 'delivery', 'county_id' => '47', 'town' => 'Kilimani', 'location' => 'Secret Street 9', 'payment_method' => 'mpesa_till'],
            (array) $this->db->one('SELECT * FROM users WHERE id = ?', [$this->customer]), Crypto::token(32), '127.0.0.1')['order'];
        $this->db->exec("UPDATE orders SET payment_status = 'verified' WHERE id = ?", [$o['id']]);
        (new OrderWorkflow($this->db))->transition((int) $o['id'], 'confirmed', ['type' => 'system'], 'Paid');
        $so = (int) $this->db->value('SELECT id FROM seller_orders WHERE order_id = ?', [$o['id']]);
        $wf = new SellerOrderWorkflow($this->db);
        foreach (['accepted', 'processing', 'packed'] as $to) {
            $wf->transition($so, $to, ['type' => 'seller', 'id' => $this->owner, 'seller_id' => $this->seller]);
        }
        foreach (['handed_over', 'dispatched', 'delivered'] as $to) {
            $wf->transition($so, $to, ['type' => 'staff', 'id' => null]);
        }
        return [(array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$o['id']]), $so];
    }

    public function testCustomerOpensACaseAndTheSellerSeesOnlyWhatTheyNeed(): void
    {
        [$order, $so] = $this->deliveredOrder();
        $this->login($this->customerEmail);
        $page = $this->get('/account/orders/' . $order['order_number'])['body'];
        $this->assertStringContainsString('Report a problem with a seller', $page);
        $this->assertStringContainsString('Request a return', $page);

        $form = '/order/' . $order['order_number'] . '/problem';
        $this->assertStringContainsString('Kamba Crafts', $this->get($form)['body']);
        $r = $this->post($form, ['seller_order_id' => (string) $so, 'reason' => 'damaged', 'details' => 'The carving arrived cracked in two places.'], $form);
        $this->assertSame(303, $r['status']);
        $id = (int) $this->db->value('SELECT id FROM seller_disputes WHERE seller_order_id = ?', [$so]);
        $case = $this->follow($r)['body'];
        $this->assertStringContainsString('cracked in two places', $case);
        $this->post("/order/{$order['order_number']}/cases/$id/message", ['body' => 'Photos are ready if needed.'], "/order/{$order['order_number']}/cases/$id");

        // Seller: first name only, never contacts or address.
        $this->login($this->emailOf($this->owner));
        $this->db->exec("UPDATE seller_disputes SET status = 'awaiting_seller' WHERE id = ?", [$id]);   // Waimart asked the seller
        $this->assertStringContainsString('1 case waiting for your reply', $this->get('/seller')['body']);
        $sp = $this->get("/seller/disputes/$id")['body'];
        foreach (['cracked in two places', 'Photos are ready', 'Achieng'] as $t) {
            $this->assertStringContainsString($t, $sp);
        }
        foreach (['Odhiambo', '0733 444 555', '254733444555', $this->customerEmail, 'Secret Street 9'] as $private) {
            $this->assertStringNotContainsString($private, $sp, "seller must not see: $private");
        }
        $this->assertSame(303, $this->post("/seller/disputes/$id/message", ['body' => 'We packed it in bubble wrap.'], "/seller/disputes/$id")['status']);
        $this->assertSame('open', $this->db->value('SELECT status FROM seller_disputes WHERE id = ?', [$id]));
        $this->assertStringContainsString('Returns &amp; cases', $this->get("/seller/orders/$so")['body']);

        // Admin desk resolves with a refund; the customer sees the decision.
        $this->post('/logout', [], '/');
        $this->loginStaff('support');                   // support holds disputes.manage
        $this->assertStringContainsString('Kamba Crafts', $this->get('/admin/disputes')['body']);
        $this->post("/admin/disputes/$id/message", ['body' => 'Internal: courier confirmed rough handling.', 'internal' => '1'], "/admin/disputes/$id");
        $this->assertSame(303, $this->post("/admin/disputes/$id/resolve", ['resolution' => 'refund_full', 'resolution_note' => 'Damaged in transit; full refund.'], "/admin/disputes/$id")['status']);
        $this->assertSame('2000.00', $this->db->value('SELECT amount FROM refunds WHERE dispute_id = ?', [$id]));

        $this->login($this->customerEmail);
        $case = $this->get("/order/{$order['order_number']}/cases/$id")['body'];
        $this->assertStringContainsString('Full refund to the customer', $case);
        $this->assertStringNotContainsString('courier confirmed', $case, 'internal notes stay internal');
        $this->login($this->emailOf($this->owner));
        $this->assertStringNotContainsString('courier confirmed', $this->get("/seller/disputes/$id")['body']);
    }

    public function testCasesAreIsolatedByOrderSellerAndRole(): void
    {
        [$order, $so] = $this->deliveredOrder();
        $d = (new DisputeService($this->db))->open($so, 'customer', $this->customer, 'damaged', 'The carving arrived cracked in two places.');
        $id = (int) $d['id'];

        // Another customer: the order is not theirs.
        $other = 'other-' . bin2hex(random_bytes(3)) . '@example.com';
        $this->user($other);
        $this->login($other);
        $this->assertSame(404, $this->get("/order/{$order['order_number']}/cases/$id")['status']);
        $this->assertSame(404, $this->get("/order/{$order['order_number']}/problem")['status']);

        // The right customer, but through another order of theirs: 404.
        [$order2] = $this->deliveredOrder();
        $this->login($this->customerEmail);
        $this->assertSame(404, $this->get("/order/{$order2['order_number']}/cases/$id")['status']);

        // Another seller: 404. The store's staff role: may read, may not reply.
        $otherSeller = $this->db->insert('sellers', ['name' => 'Other Shop', 'slug' => 'other-shop']);
        $u = $this->user('o-' . bin2hex(random_bytes(3)) . '@example.com');
        $this->db->insert('seller_users', ['seller_id' => $otherSeller, 'user_id' => $u, 'role' => 'owner']);
        $this->login($this->emailOf($u));
        $this->assertSame(404, $this->get("/seller/disputes/$id")['status']);
        $this->assertSame(404, $this->post("/seller/disputes/$id/message", ['body' => 'hello'], '/seller/disputes')['status']);
        $staff = $this->user('s-' . bin2hex(random_bytes(3)) . '@example.com');
        $this->db->insert('seller_users', ['seller_id' => $this->seller, 'user_id' => $staff, 'role' => 'staff']);
        $this->login($this->emailOf($staff));
        $this->assertSame(200, $this->get("/seller/disputes/$id")['status']);
        $this->assertSame(403, $this->post("/seller/disputes/$id/message", ['body' => 'hello'], "/seller/disputes/$id")['status']);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM seller_dispute_messages'));

        // Staff without disputes.manage.
        $this->post('/logout', [], '/');
        $this->loginStaff('fulfilment_staff');
        $this->assertSame(403, $this->get("/admin/disputes/$id")['status']);
    }

    public function testReportsRenderAndReconcile(): void
    {
        $this->deliveredOrder();
        $this->login($this->emailOf($this->owner));
        $rep = $this->get('/seller/reports')['body'];
        $this->assertStringContainsString('KSh 2,000', $rep);
        $this->assertStringContainsString('KSh 1,800', $rep);

        $this->post('/logout', [], '/');
        $this->loginStaff('manager');                    // reports.view
        $page = $this->get('/admin/reports/marketplace')['body'];
        $this->assertStringContainsString('Kamba Crafts', $page);
        $this->assertStringContainsString('All checks pass', $page);
        $csv = $this->get('/admin/reports/marketplace?format=csv');
        $this->assertStringContainsString('text/csv', (string) ($csv['headers']['content-type'] ?? ''));
        $this->assertStringContainsString('Kamba Crafts', $csv['body']);
    }
}
