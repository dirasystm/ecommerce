<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Tests\Integration\PlacesOrders;

final class PaymentHttpTest extends FeatureTestCase
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

    public function testCustomerSubmitsAndFinanceVerifiesWithPermissions(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'mpesa_till', 'delivery', ['customer_phone' => '0733222111']);

        // Guest proves access with number + phone, then submits a code.
        $this->post('/track-order', ['order_number' => $o['order_number'], 'phone' => '0733222111'], '/track-order');
        $page = '/track/' . $o['order_number'];
        $this->assertStringContainsString('submit my M-Pesa code', $this->get($page)['body']);
        $r = $this->post('/order/' . $o['order_number'] . '/payment', ['transaction_code' => 'sgh7k2l9xq', 'payer_phone' => '0733222111', 'amount' => (string) $o['grand_total']], $page);
        $after = $this->follow($r)['body'];
        $this->assertStringContainsString('confirmed only after verification', $after);
        $this->assertStringContainsString('SGH7K2L9XQ', $after);
        $this->assertStringContainsString('Awaiting verification', $after);
        $this->post('/logout', [], $page);

        // Support can see the queue but not decide.
        $this->loginStaff('support');
        $this->assertStringContainsString('SGH7K2L9XQ', $this->get('/admin/payments')['body']);
        $sid = (int) $this->db->value("SELECT id FROM payment_submissions WHERE transaction_code = 'SGH7K2L9XQ'");
        $this->assertSame(403, $this->post("/admin/payments/$sid/verify", ['amount_received' => (string) $o['grand_total']], '/admin/payments')['status']);
        $this->post('/logout', [], '/admin');

        $this->loginStaff('finance');
        $r = $this->post("/admin/payments/$sid/verify", ['amount_received' => (string) $o['grand_total'], 'note' => 'OK'], '/admin/payments');
        $this->assertStringContainsString('is confirmed', $this->follow($r)['body']);
        $this->assertSame('confirmed', $this->db->value('SELECT status FROM orders WHERE id = ?', [$o['id']]));
    }

    public function testStrangerCannotSubmitPaymentForSomeoneElsesOrder(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $o = $this->placeOrder([[$v, 1]], 'mpesa_till');
        $r = $this->post('/order/' . $o['order_number'] . '/payment', ['transaction_code' => 'ABCDE12345', 'payer_phone' => '0700000000', 'amount' => '1'], '/track-order');
        $this->assertSame(404, $r['status']);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM payment_submissions'));
    }

    public function testSettingsRequireSettingsPermission(): void
    {
        $this->loginStaff('manager');
        $this->assertSame(403, $this->get('/admin/settings')['status'], 'managers lack settings.manage');
        $this->assertSame(403, $this->get('/admin/settings/payments')['status']);
        $this->assertSame(403, $this->get('/admin/payment-methods/mpesa_till')['status'], 'managers lack payments.configure');
        $this->post('/logout', [], '/admin');
        $this->loginStaff('super_admin');
        $this->assertStringEndsWith('/admin/settings/general', (string) ($this->get('/admin/settings')['headers']['location'] ?? ''));
        // M-Pesa numbers are edited with their payment method (Stage 4); the old settings address leads there.
        $this->assertStringEndsWith('/admin/payment-methods', (string) ($this->get('/admin/settings/payments')['headers']['location'] ?? ''));
        $page = $this->get('/admin/payment-methods/mpesa_till');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Double-check every digit', $page['body']);
    }
}
