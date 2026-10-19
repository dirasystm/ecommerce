<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\App;
use Waimart\Tests\Integration\PlacesOrders;

final class OrderLifecycleHttpTest extends FeatureTestCase
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

    public function testCustomerCancelsOwnUnpaidOrderAndStockReturns(): void
    {
        $this->post('/register', ['first_name' => 'C', 'last_name' => 'X', 'email' => 'cx@example.com', 'phone' => '0712121212',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => '1'], '/register');
        $uid = (int) $this->db->value("SELECT id FROM users WHERE email = 'cx@example.com'");
        $v = $this->makeVariant(3)['variant_id'];
        $o = $this->placeOrder([[$v, 2]], 'cod');
        $this->db->exec('UPDATE orders SET user_id = ? WHERE id = ?', [$uid, $o['id']]);

        $page = '/account/orders/' . $o['order_number'];
        $this->assertStringContainsString('Cancel this order', $this->get($page)['body']);
        $r = $this->post('/order/' . $o['order_number'] . '/cancel', ['reason' => 'changed_mind'], $page);
        $this->assertStringContainsString('Your order has been cancelled', $this->follow($r)['body']);
        $this->assertSame('cancelled', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
        $this->assertSame(['on_hand' => 3, 'reserved' => 0], $this->levels($v));
        $this->assertSame('Changed my mind', $this->db->value('SELECT cancel_reason FROM orders WHERE id = ?', [$o['id']]));
        $this->assertStringNotContainsString('Cancel this order', $this->get($page)['body'], 'no longer offered');
    }

    public function testOtherReasonNeedsTheCustomersOwnWords(): void
    {
        $this->post('/register', ['first_name' => 'D', 'last_name' => 'Y', 'email' => 'dy@example.com', 'phone' => '0712131313',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => '1'], '/register');
        $uid = (int) $this->db->value("SELECT id FROM users WHERE email = 'dy@example.com'");
        $o = $this->placeOrder([[$this->makeVariant(3)['variant_id'], 1]], 'cod');
        $this->db->exec('UPDATE orders SET user_id = ? WHERE id = ?', [$uid, $o['id']]);
        $page = '/account/orders/' . $o['order_number'];

        $form = $this->get($page)['body'];
        $this->assertStringContainsString('data-other-target="cancelReasonOtherWrap"', $form);
        $this->assertMatchesRegularExpression('/id="cancelReasonOtherWrap"[^>]*hidden/', $form, 'the box appears only for "Other"');

        // "Other" without words: refused, the form reopens with "Other" kept and the box shown with the message.
        $bad = $this->follow($this->post('/order/' . $o['order_number'] . '/cancel', ['reason' => 'other', 'reason_other' => ' '], $page))['body'];
        $this->assertSame('pending_confirmation', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
        $this->assertStringContainsString('Please tell us why you are cancelling.', $bad);
        $this->assertMatchesRegularExpression('/<details class="mt-3"\s+open>/', $bad);
        $this->assertMatchesRegularExpression('/<option value="other" selected>/', $bad);
        $this->assertDoesNotMatchRegularExpression('/id="cancelReasonOtherWrap"[^>]*hidden/', $bad);

        $this->post('/order/' . $o['order_number'] . '/cancel', ['reason' => 'other', 'reason_other' => "Wrong colour\nsorry"], $page);
        $this->assertSame(['cancelled', 'Other: Wrong colour sorry'], array_values((array) $this->db->one('SELECT status, cancel_reason FROM orders WHERE id = ?', [$o['id']])));
    }

    public function testStrangersCannotCancelOrders(): void
    {
        $v = $this->makeVariant(3)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'cod');
        $r = $this->post('/order/' . $o['order_number'] . '/cancel', ['reason' => 'changed_mind'], '/track-order');
        $this->assertSame(404, $r['status']);
        $this->assertSame('pending_confirmation', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
    }

    public function testStaffActionsArePermissionChecked(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 2]], 'cod');
        $page = '/admin/orders/' . $o['id'];

        // Support may cancel but not move fulfilment.
        $this->loginStaff('support');
        $this->assertSame(200, $this->get($page)['status']);
        $this->assertSame(403, $this->post("$page/status", ['to' => 'confirmed'], $page)['status']);
        $this->post('/logout', [], '/admin');

        // Fulfilment staff walk it through to dispatch; stock is consumed exactly at dispatch.
        $this->loginStaff('fulfilment_staff');
        foreach (['confirmed', 'processing', 'packed'] as $to) {
            $this->assertSame(303, $this->post("$page/status", ['to' => $to], $page)['status'], $to);
        }
        $this->assertSame(['on_hand' => 5, 'reserved' => 2], $this->levels($v));
        $this->assertSame(403, $this->post("$page/status", ['to' => 'cancelled', 'reason' => 'nope'], $page)['status'], 'no orders.cancel');
        $this->post("$page/status", ['to' => 'dispatched'], $page);
        $this->assertSame(['on_hand' => 3, 'reserved' => 0], $this->levels($v));

        // Arbitrary status values are rejected outright.
        $r = $this->post("$page/status", ['to' => 'delivered_and_paid'], $page);
        $this->assertStringContainsString('not available', $this->follow($r)['body']);
        $r = $this->post("$page/status", ['to' => 'confirmed'], $page);
        $this->assertStringContainsString('cannot be moved', $this->follow($r)['body']);

        $this->post("$page/status", ['to' => 'delivered', 'confirmation' => 'Signed by Mary'], $page);
        $this->assertSame('delivered', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
        // confirmed, processing, packed, dispatched, delivered — each audited with the staff member.
        $this->assertSame(5, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'order.status_changed' AND entity_id = ? AND actor_user_id IS NOT NULL", [$o['id']]));
    }

    public function testCancelAfterDispatchIsRefusedInAdmin(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'cod');
        $page = '/admin/orders/' . $o['id'];
        $this->loginStaff('manager');
        foreach (['confirmed', 'processing', 'packed', 'dispatched'] as $to) {
            $this->post("$page/status", ['to' => $to], $page);
        }
        $r = $this->post("$page/status", ['to' => 'cancelled', 'reason' => 'Customer changed mind'], $page);
        $this->assertStringContainsString('returns process', $this->follow($r)['body']);
        $this->assertSame('dispatched', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
    }

    public function testExpiryCommandLineWorker(): void
    {
        $v = $this->makeVariant(2)['variant_id'];
        $o = $this->placeOrder([[$v, 2]], 'mpesa_till');
        $this->db->exec('UPDATE stock_reservations SET expires_at = NOW() - INTERVAL 1 MINUTE');
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(App::root() . '/bin/expire-reservations.php');
        $env = PHP_OS_FAMILY === 'Windows' ? 'set WAIMART_ENV=testing&& ' : 'WAIMART_ENV=testing ';
        exec($env . $cmd . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('expired orders: 1, reservations released: 1', implode("\n", $out));
        $this->assertSame('expired', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
        exec($env . $cmd . ' --quiet 2>&1', $out2, $code2);
        $this->assertSame(0, $code2);
        $this->assertSame([], $out2, 'quiet run with nothing to do prints nothing');
    }
}
