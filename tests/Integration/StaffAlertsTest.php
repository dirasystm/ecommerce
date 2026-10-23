<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Services\StaffAlerts;

/**
 * New orders and submitted M-Pesa codes reach, by email and SMS, the super administrators and exactly the staff who
 * must act: payment verifiers for M-Pesa, order confirmers for cash on delivery. Suspended staff and staff without the
 * permission get nothing.
 */
final class StaffAlertsTest extends DbTestCase
{
    /** @var array<string,int> */
    private array $staff = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['super_admin' => '254711000001', 'finance' => '254711000002', 'fulfilment_staff' => '254711000003', 'support' => '254711000004'] as $role => $phone) {
            $id = $this->db->insert('users', ['email' => "$role@example.test", 'phone' => $phone, 'password_hash' => 'x', 'first_name' => ucfirst($role), 'last_name' => 'T', 'is_staff' => 1]);
            $this->db->exec('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?', [$id, $role]);
            $this->staff[$role] = $id;
        }
        // A suspended finance officer, and a support officer given payments.verify directly.
        $gone = $this->db->insert('users', ['email' => 'gone@example.test', 'phone' => '254711000005', 'password_hash' => 'x', 'first_name' => 'Gone', 'last_name' => 'T', 'is_staff' => 1, 'status' => 'suspended']);
        $this->db->exec("INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = 'finance'", [$gone]);
        $direct = $this->db->insert('users', ['email' => 'direct@example.test', 'phone' => '254711000006', 'password_hash' => 'x', 'first_name' => 'Direct', 'last_name' => 'T', 'is_staff' => 1]);
        $this->db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permissions WHERE slug = 'payments.verify'", [$direct]);
        $this->staff['direct'] = $direct;
    }

    /** @return list<string> who received the template, by email address or phone */
    private function sent(string $template, string $channel): array
    {
        $rows = array_column($this->db->all('SELECT recipient FROM notifications WHERE template = ? AND channel = ? ORDER BY recipient', [$template, $channel]), 'recipient');
        sort($rows);
        return $rows;
    }

    public function testMpesaOrderAlertsPaymentVerifiersByEmailAndSms(): void
    {
        $o = $this->makeOrder($this->makeVariant(5, '1500.00')['variant_id'], 1, ['payment_method' => 'mpesa_till']);
        StaffAlerts::orderPlaced($this->db->one('SELECT * FROM orders WHERE id = ?', [$o['order_id']]));
        $this->assertSame(['direct@example.test', 'finance@example.test', 'super_admin@example.test'], $this->sent('staff_order_new', 'email'),
            'super admin + finance (role) + direct grant; not fulfilment, support or the suspended officer');
        $this->assertSame(['254711000001', '254711000002', '254711000006'], $this->sent('staff_order_new', 'sms'), 'and by SMS');
        $body = (string) $this->db->value("SELECT body FROM notifications WHERE template = 'staff_order_new' AND channel = 'email' LIMIT 1");
        $this->assertStringContainsString('Verify the transaction code as soon as it arrives', $body);
        $this->assertStringContainsString('/admin/orders/' . $o['order_id'], $body);
        $this->assertSame((string) $o['order_id'], (string) $this->db->value("SELECT order_id FROM notifications WHERE template = 'staff_order_new' LIMIT 1"), 'linked to the order');
    }

    public function testCashOnDeliveryOrderAlertsOrderConfirmers(): void
    {
        $o = $this->makeOrder($this->makeVariant(5, '900.00')['variant_id'], 1, ['payment_method' => 'cod', 'status' => 'pending_confirmation', 'payment_status' => 'cod_pending']);
        StaffAlerts::orderPlaced($this->db->one('SELECT * FROM orders WHERE id = ?', [$o['order_id']]));
        $this->assertSame(['fulfilment_staff@example.test', 'super_admin@example.test'], $this->sent('staff_order_new', 'email'));
        $this->assertStringContainsString('press Confirm order', (string) $this->db->value("SELECT body FROM notifications WHERE template = 'staff_order_new' LIMIT 1"));
    }

    public function testSubmittedMpesaCodeReachesVerifiersWithTheDetails(): void
    {
        $o = $this->makeOrder($this->makeVariant(5, '2450.00')['variant_id'], 1, ['payment_method' => 'mpesa_till']);
        StaffAlerts::paymentSubmitted($o['order_id'], ['transaction_code' => 'SGH7K2L9XQ', 'amount_claimed' => '2450.00', 'payer_phone' => '254712345678']);
        $this->assertSame(['direct@example.test', 'finance@example.test', 'super_admin@example.test'], $this->sent('staff_payment_submitted', 'email'));
        $email = (string) $this->db->value("SELECT body FROM notifications WHERE template = 'staff_payment_submitted' AND channel = 'email' LIMIT 1");
        $this->assertStringContainsString('SGH7K2L9XQ', $email);
        $this->assertStringContainsString('KSh 2,450', $email);
        $this->assertStringContainsString('0712 345 678', $email);
        $sms = (string) $this->db->value("SELECT body FROM notifications WHERE template = 'staff_payment_submitted' AND channel = 'sms' LIMIT 1");
        $this->assertStringContainsString('verify M-Pesa SGH7K2L9XQ', $sms);
    }
}
