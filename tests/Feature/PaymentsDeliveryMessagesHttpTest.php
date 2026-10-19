<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\Settings;

/** Basemall Stage 4 over HTTP: who may change payments, delivery, messages and credentials, and what each change does on the shop. */
final class PaymentsDeliveryMessagesHttpTest extends FeatureTestCase
{
    /** @var array<string,string> */
    private array $snapshot = [];
    private int $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshot = array_column($this->db->all('SELECT setting_key, setting_value FROM settings'), 'setting_value', 'setting_key');
        $zone = $this->db->insert('delivery_zones', ['name' => 'City', 'fee' => '250', 'min_days' => 1, 'max_days' => 2, 'cod_allowed' => 1]);
        $this->db->insert('delivery_zone_locations', ['zone_id' => $zone, 'county_id' => 47, 'town' => '']);
        $this->db->insert('pickup_points', ['code' => 'P1', 'name' => 'Moi Avenue Station', 'county_id' => 47, 'town' => 'CBD', 'address' => 'Moi Ave 12', 'opening_hours' => '8-6']);
        Settings::set('mpesa_till_number', '5123456');
        $this->variant = $this->makeVariant(5, '1500.00')['variant_id'];
    }

    protected function tearDown(): void
    {
        foreach ($this->snapshot as $k => $v) {
            Settings::set($k, (string) $v);
        }
        parent::tearDown();
    }

    private function checkoutPage(): string
    {
        $slug = (string) $this->db->value('SELECT p.slug FROM products p JOIN product_variants v ON v.product_id = p.id WHERE v.id = ?', [$this->variant]);
        $this->post('/cart/add', ['variant_id' => (string) $this->variant, 'quantity' => '1'], '/product/' . $slug);
        return $this->get('/checkout')['body'];
    }

    public function testOnlyTheRightRolesReachEachModule(): void
    {
        $pay = ['/admin/payment-methods', '/admin/payment-methods/cod'];
        $delivery = ['/admin/delivery-settings'];
        $messages = ['/admin/messages', '/admin/messages/order_placed'];
        $matrix = [   // role => [payment methods, delivery settings, messages]
            'support' => [403, 403, 403], 'finance' => [403, 403, 403], 'fulfilment_staff' => [403, 403, 403],
            'inventory_clerk' => [403, 403, 403], 'manager' => [403, 200, 200], 'super_admin' => [200, 200, 200],
        ];
        foreach ($matrix as $role => [$p, $d, $m]) {
            $this->post('/logout', [], '/');
            $this->loginStaff($role);
            foreach ([[$pay, $p], [$delivery, $d], [$messages, $m]] as [$paths, $expect]) {
                foreach ($paths as $path) {
                    $this->assertSame($expect, $this->get($path)['status'], "$role GET $path");
                }
            }
            if ($p === 403) {
                $this->assertSame(403, $this->post('/admin/payment-methods/cod', ['name' => 'Hacked', 'short_name' => 'X', 'sort_order' => '1'], '/admin')['status'], "$role cannot edit a method");
            }
            if ($role !== 'super_admin') {
                $this->assertSame(403, $this->post('/admin/settings/email/password', ['secret_smtp_password' => 'stolen'], '/admin')['status'], "$role cannot set credentials");
            }
        }
        $this->assertSame('Cash on delivery / on collection', (string) $this->db->value("SELECT name FROM payment_methods WHERE code = 'cod'"));
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM secrets'));
    }

    public function testSwitchingOffAndRenamingMethodsChangesTheShop(): void
    {
        $page = $this->checkoutPage();
        $this->assertStringContainsString('value="mpesa_till"', $page);
        $this->assertStringContainsString('<span class="wm-pay-badge">Cash on Delivery</span>', $page);

        $this->loginStaff('super_admin');
        $r = $this->post('/admin/payment-methods/cod', ['name' => 'Pay on delivery', 'short_name' => 'Pay on delivery', 'description' => 'Cash or M-Pesa to the rider.',
            'sort_order' => '5', 'is_enabled' => '1', 'instructions' => 'Have **{amount}** ready.'], '/admin/payment-methods/cod');
        $this->assertSame(303, $r['status']);
        $this->post('/admin/payment-methods/mpesa_till', ['name' => 'M-Pesa Till', 'short_name' => 'Till', 'sort_order' => '10', 'mpesa_till_number' => '5123456'], '/admin/payment-methods/mpesa_till');   // switched off

        $this->post('/logout', [], '/');
        $page = $this->checkoutPage();
        $this->assertStringNotContainsString('value="mpesa_till"', $page);
        $this->assertStringContainsString('Pay on delivery</strong>', $page);
        $this->assertStringContainsString('Cash or M-Pesa to the rider.', $page);
        $this->assertStringNotContainsString('wm-pay-badge mpesa', $page, 'no M-Pesa method offered: no M-Pesa badge');
        $howTo = $this->get('/how-to-pay')['body'];
        $this->assertStringNotContainsString('5123456', $howTo);
        $this->assertStringContainsString('Have <strong>the order total</strong> ready.', $howTo);

        // A disabled method cannot be forced through checkout.
        preg_match('/name="_csrf" value="([^"]+)"/', $page, $m);
        preg_match('/name="checkout_token" value="([^"]+)"/', $page, $t);
        $r = $this->http('POST', '/checkout', ['_csrf' => html_entity_decode($m[1]), 'checkout_token' => $t[1], 'customer_name' => 'Guest', 'customer_phone' => '0722111222',
            'customer_email' => 'g@example.com', 'fulfilment_method' => 'delivery', 'county_id' => '47', 'town' => 'Kilimani', 'location' => 'Argwings Kodhek', 'payment_method' => 'mpesa_till'], [], '/checkout');
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM orders'));
        $this->assertStringContainsString('Choose a payment method.', $this->follow($r)['body']);

        // Refused input is explained, nothing changes.
        $this->loginStaff('super_admin');
        $bad = $this->post('/admin/payment-methods/mpesa_paybill', ['name' => 'Paybill', 'short_name' => 'Paybill', 'sort_order' => '20', 'is_enabled' => '1'], '/admin/payment-methods/mpesa_paybill');
        $this->assertStringContainsString('to offer this method, or switch it off', html_entity_decode($this->follow($bad)['body']));
        $this->assertSame(1, (int) $this->db->value("SELECT is_enabled FROM payment_methods WHERE code = 'mpesa_paybill'"), 'unchanged');
    }

    public function testDeliverySettingsAndStatusNames(): void
    {
        $this->loginStaff('manager');
        $bad = $this->post('/admin/delivery-settings', ['free_delivery_threshold' => ''], '/admin/delivery-settings');
        $this->assertStringContainsString('Offer at least one way to receive orders', html_entity_decode($this->follow($bad)['body']));
        $this->post('/admin/delivery-settings', ['delivery_pickup_enabled' => '1', 'pickup_checkout_note' => 'Wait for our message before you go.'], '/admin/delivery-settings');
        $this->post('/admin/delivery-settings/status-names', ['label' => ['awaiting_payment' => 'Waiting for your M-Pesa payment']], '/admin/delivery-settings');
        $this->post('/logout', [], '/');

        $page = $this->checkoutPage();
        $this->assertStringNotContainsString('id="fmDelivery"', $page, 'home delivery switched off');
        $this->assertStringContainsString('id="fmPickup"', $page);
        $this->assertStringContainsString('Wait for our message before you go.', $page);
        $this->assertSame('Waiting for your M-Pesa payment', \Waimart\Services\OrderPresenter::status('awaiting_payment'));
    }

    public function testMessagesAndCredentials(): void
    {
        $this->loginStaff('super_admin');
        $bad = $this->post('/admin/messages/order_placed', ['email_subject' => 'Thanks', 'email_body' => 'Hi {customer_name} {secret}', 'sms_body' => 'Placed {order_number}'], '/admin/messages/order_placed');
        $this->assertStringContainsString('Unknown placeholder: {secret}', html_entity_decode($this->follow($bad)['body']));
        $ok = $this->post('/admin/messages/order_placed', ['email_subject' => 'Thanks for order {order_number}', 'email_body' => 'Hi {first_name}, we got order {order_number}. {track_url}',
            'sms_body' => '{store}: we got {order_number}.', 'email_enabled' => '1', 'sms_enabled' => '1'], '/admin/messages/order_placed');
        $this->assertStringContainsString('Hi Jane, we got order ' . \Waimart\Services\Brand::orderPrefix() . '-7K2L9XQ4.', html_entity_decode($this->follow($ok)['body']), 'preview');

        // The SMTP password: saved encrypted, never shown again, and the test email reports honestly.
        $this->post('/admin/settings/email/password', ['secret_smtp_password' => 'Pa55-word-xyz'], '/admin/settings/email');
        $email = $this->get('/admin/settings/email')['body'];
        $this->assertStringContainsString('Saved</span>', $email);
        $this->assertStringNotContainsString('Pa55-word-xyz', $email);
        $this->assertStringNotContainsString('Pa55-word-xyz', (string) $this->db->value("SELECT ciphertext FROM secrets WHERE name = 'smtp_password'"));
        $test = $this->post('/admin/settings/email/test', [], '/admin/settings/email');
        $this->assertStringContainsString('it was written to the mail log', html_entity_decode($this->follow($test)['body']));

        // A customer order uses the edited wording.
        $this->post('/logout', [], '/');
        $page = $this->checkoutPage();
        preg_match('/name="_csrf" value="([^"]+)"/', $page, $m);
        preg_match('/name="checkout_token" value="([^"]+)"/', $page, $t);
        $this->http('POST', '/checkout', ['_csrf' => html_entity_decode($m[1]), 'checkout_token' => $t[1], 'customer_name' => 'Wanjiru Kamau', 'customer_phone' => '0722111222',
            'customer_email' => 'w@example.com', 'fulfilment_method' => 'delivery', 'county_id' => '47', 'town' => 'Kilimani', 'location' => 'Argwings Kodhek', 'payment_method' => 'cod'], [], '/checkout');
        $n = $this->db->one("SELECT subject, body FROM notifications WHERE template = 'order_placed' AND channel = 'email'");
        $number = (string) $this->db->value('SELECT order_number FROM orders');
        $this->assertSame("Thanks for order $number", $n['subject']);
        $this->assertStringStartsWith("Hi Wanjiru, we got order $number. http", $n['body']);
        $this->assertStringContainsString('[redacted]', $n['body'], 'the private tracking link stays out of the stored copy');
        $this->assertSame('Basemall: we got ' . $number . '.', (string) $this->db->value("SELECT body FROM notifications WHERE template = 'order_placed' AND channel = 'sms'"));
    }
}
