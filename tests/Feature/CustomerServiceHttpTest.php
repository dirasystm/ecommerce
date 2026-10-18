<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Services\CodService;
use Waimart\Services\OrderWorkflow;
use Waimart\Tests\Integration\PlacesOrders;

final class CustomerServiceHttpTest extends FeatureTestCase
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

    private function deliveredFor(int $userId, int $variant): array
    {
        $o = $this->placeOrder([[$variant, 1]], 'cod');
        $this->db->exec('UPDATE orders SET user_id = ? WHERE id = ?', [$userId, $o['id']]);
        $wf = new OrderWorkflow($this->db);
        foreach (['confirmed', 'processing', 'packed', 'dispatched', 'delivered'] as $to) {
            $wf->transition((int) $o['id'], $to, ['type' => 'staff', 'id' => $this->staffUser()]);
        }
        (new CodService($this->db))->record((int) $o['id'], (string) $o['grand_total'], $this->staffUser(), null);
        return $o;
    }

    public function testReturnRequestToInspectionOverHttp(): void
    {
        $this->post('/register', ['first_name' => 'R', 'last_name' => 'T', 'email' => 'rt@example.com', 'phone' => '0712000111',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => '1'], '/register');
        $uid = (int) $this->db->value("SELECT id FROM users WHERE email = 'rt@example.com'");
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->deliveredFor($uid, $v);
        $item = (int) $this->db->value('SELECT id FROM order_items WHERE order_id = ?', [$o['id']]);

        $this->assertStringContainsString('Request a return', $this->get('/account/orders/' . $o['order_number'])['body']);
        $form = '/order/' . $o['order_number'] . '/return';
        $r = $this->post($form, ['qty' => [$item => '1'], 'reason' => 'defective', 'details' => 'Stopped working'], $form);
        $this->assertStringContainsString('Return request RT-', $this->follow($r)['body']);
        $this->post('/logout', [], '/account');

        $rid = (int) $this->db->value('SELECT id FROM return_requests');
        $page = "/admin/returns/$rid";
        $this->loginStaff('support');
        $this->post($page, ['action' => 'approve'], $page);
        $this->post($page, ['action' => 'receive'], $page);
        $ri = (int) $this->db->value('SELECT id FROM return_items WHERE return_id = ?', [$rid]);
        $this->post($page, ['action' => 'inspect', 'return_item_id' => (string) $ri, 'outcome' => 'restock', 'note' => 'OK'], $page);
        $this->assertSame(5, $this->levels($v)['on_hand'], 'restocked after passing inspection');
        $this->assertSame('restock', $this->db->value('SELECT inspection FROM return_items WHERE id = ?', [$ri]));
        $this->assertSame(403, $this->get('/admin/refunds')['status'], 'support cannot pay refunds');
    }

    public function testReviewFlowAndProductPage(): void
    {
        $this->post('/register', ['first_name' => 'Wanjiku', 'last_name' => 'M', 'email' => 'wm@example.com', 'phone' => '0712000222',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => '1'], '/register');
        $uid = (int) $this->db->value("SELECT id FROM users WHERE email = 'wm@example.com'");
        $p = $this->makeVariant(5);
        $o = $this->deliveredFor($uid, $p['variant_id']);
        $item = (int) $this->db->value('SELECT id FROM order_items WHERE order_id = ?', [$o['id']]);
        $this->assertStringContainsString('Waiting for your review', $this->get('/account/reviews')['body']);
        $this->post("/account/reviews/$item", ['rating' => '5', 'title' => 'Excellent <b>kettle</b>', 'body' => 'Boils fast'], '/account/reviews');
        $this->post('/logout', [], '/account');

        $slug = (string) $this->db->value('SELECT slug FROM products WHERE id = ?', [$p['product_id']]);
        $this->assertStringNotContainsString('Excellent', $this->get("/product/$slug")['body'], 'not shown before moderation');

        $this->loginStaff('support');
        $rid = (int) $this->db->value('SELECT id FROM reviews');
        $this->post("/admin/reviews/$rid", ['decision' => 'approved'], '/admin/reviews');
        $page = $this->get("/product/$slug")['body'];
        $this->assertStringContainsString('Excellent &lt;b&gt;kettle&lt;/b&gt;', $page, 'escaped');
        $this->assertStringContainsString('Verified purchase', $page);
        $this->assertStringContainsString('Wanjiku M.', $page, 'surname shortened');
    }

    public function testOutboxNeverResendsSecrets(): void
    {
        $id = $this->db->insert('notifications', ['channel' => 'email', 'template' => 'password_reset', 'recipient' => 'a@b.co', 'subject' => 'Reset',
            'body' => 'reset-password?token=[redacted]', 'status' => 'failed']);
        $this->loginStaff('manager');
        $r = $this->post("/admin/notifications/$id/resend", [], '/admin/notifications');
        $this->assertStringContainsString('Only failed emails without secrets', $this->follow($r)['body']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM notifications'));
    }
}
