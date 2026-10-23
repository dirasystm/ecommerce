<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Crypto;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Services\AccountService;
use Waimart\Services\CartService;
use Waimart\Services\CheckoutService;
use Waimart\Services\ContentRenderer;
use Waimart\Services\DeliveryService;
use Waimart\Services\MessageTemplates;
use Waimart\Services\NotificationService;
use Waimart\Services\OrderNotifier;
use Waimart\Services\OrderPresenter;
use Waimart\Services\PaymentOptions;
use Waimart\Services\SecretStore;
use Waimart\Services\SettingsService;
use Waimart\Services\StatusLabels;

/**
 * Ecommerce Stage 4: payment methods, the encrypted secrets store, delivery settings, order status names and message
 * templates — each one changing what checkout, order pages and outgoing messages actually do.
 */
final class PaymentsDeliveryMessagesTest extends DbTestCase
{
    use PlacesOrders;

    /** @var array<string,string> */
    private array $snapshot = [];
    private int $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshot = array_column($this->db->all('SELECT setting_key, setting_value FROM settings'), 'setting_value', 'setting_key');
        $this->setUpFulfilment();
        $this->staff = $this->staffUser();
    }

    protected function tearDown(): void
    {
        foreach ($this->snapshot as $k => $v) {
            Settings::set($k, (string) $v);
        }
        Settings::flush();
        parent::tearDown();
    }

    /** @param array<string,mixed> $o @return array<string,mixed> the edit form as saved today, with overrides */
    private function methodForm(string $code, array $o = []): array
    {
        $m = PaymentOptions::all()[$code];
        $form = ['name' => $m['name'], 'short_name' => $m['short_name'], 'description' => $m['description'], 'instructions' => (string) $m['instructions'],
                 'sort_order' => (string) $m['sort_order'], 'min_order_total' => (string) $m['min_order_total'], 'max_order_total' => (string) $m['max_order_total'],
                 'is_enabled' => (int) $m['is_enabled'] === 1 ? '1' : ''];
        foreach (PaymentOptions::ACCOUNT_SETTINGS[$code] as $k) {
            $form[$k] = (string) Settings::get($k, '');
        }
        return $o + $form;
    }

    private function fails(callable $fn, string $field, string $needle = ''): void
    {
        try {
            $fn();
            $this->fail("Expected a validation error on $field");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors, json_encode($e->errors));
            $this->assertStringContainsString($needle, (string) $e->errors[$field]);
        }
    }

    // ------------------------------------------------------------------ payment methods

    public function testPaymentMethodsDecideWhatCheckoutOffers(): void
    {
        $v = $this->makeVariant(20, '1000.00')['variant_id'];
        $svc = new PaymentOptions();
        $this->assertSame(['mpesa_till', 'cod'], array_keys(PaymentOptions::forFulfilment(true)), 'only methods with a number (and COD)');
        $this->assertSame(['mpesa' => true, 'names' => ['Till', 'Cash on Delivery']], PaymentOptions::badges());

        // Switched off: gone from checkout and the footer, and refused if posted anyway.
        $svc->save('mpesa_till', $this->methodForm('mpesa_till', ['is_enabled' => '']), $this->staff);
        $this->assertArrayNotHasKey('mpesa_till', PaymentOptions::forFulfilment(true));
        $this->assertSame(['mpesa' => false, 'names' => ['Cash on Delivery']], PaymentOptions::badges());
        $this->fails(fn () => $this->placeOrder([[$v, 1]], 'mpesa_till'), 'payment_method', 'Choose a payment method');

        // Renamed and limited: the name follows everywhere; the limit is checked against the order total on the server.
        $svc->save('mpesa_till', $this->methodForm('mpesa_till', ['is_enabled' => '1', 'name' => 'Lipa na M-Pesa (Till)', 'short_name' => 'Lipa Till']), $this->staff);
        $svc->save('cod', $this->methodForm('cod', ['max_order_total' => '1100']), $this->staff);
        $this->assertSame('Lipa na M-Pesa (Till)', PaymentOptions::label('mpesa_till'));
        $this->assertFalse(PaymentOptions::forFulfilment(true, null, '1200.00')['cod']['available']);
        $this->fails(fn () => $this->placeOrder([[$v, 1]], 'cod'), 'payment_method', 'up to KSh 1,100.');   // 1,000 + 200 delivery
        $o = $this->placeOrder([[$v, 1]], 'cod', 'pickup');                                                  // 1,000, free pickup: within the limit
        $this->assertSame('cod', $o['payment_method']);
        $o = $this->placeOrder([[$v, 1]], 'mpesa_till');
        $this->assertSame('mpesa_till', $o['payment_method']);

        // Audit: method changes, and the payment number change keeps its own entry.
        $svc->save('mpesa_till', $this->methodForm('mpesa_till', ['mpesa_till_number' => '654321']), $this->staff);
        $this->assertSame(3, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment_method.updated'"), 'a number-only change has no method change');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.payment_destination_changed'"));
    }

    public function testPaymentMethodValidation(): void
    {
        $svc = new PaymentOptions();
        $this->fails(fn () => $svc->save('mpesa_paybill', $this->methodForm('mpesa_paybill', ['is_enabled' => '1']), $this->staff), 'mpesa_paybill_number', 'to offer this method');
        $this->fails(fn () => $svc->save('mpesa_paybill', $this->methodForm('mpesa_paybill', ['mpesa_paybill_number' => '12ab']), $this->staff), 'mpesa_paybill_number');
        $this->fails(fn () => $svc->save('cod', $this->methodForm('cod', ['min_order_total' => '5000', 'max_order_total' => '1000']), $this->staff), 'max_order_total', 'at least the minimum');
        $this->fails(fn () => $svc->save('cod', $this->methodForm('cod', ['name' => '']), $this->staff), 'name');
        $this->fails(fn () => $svc->save('cod', $this->methodForm('cod', ['max_order_total' => '0']), $this->staff), 'max_order_total', 'blank for no limit');
        // At least one method stays on.
        foreach (['mpesa_till', 'mpesa_paybill', 'mpesa_phone'] as $c) {
            $this->db->exec('UPDATE payment_methods SET is_enabled = 0 WHERE code = ?', [$c]);
        }
        PaymentOptions::flush();
        $this->fails(fn () => $svc->save('cod', $this->methodForm('cod', ['is_enabled' => '']), $this->staff), 'is_enabled', 'At least one');
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payment_method.updated'"), 'nothing saved');
    }

    public function testInstructionsAreSafeAndFilledIn(): void
    {
        (new PaymentOptions())->save('cod', $this->methodForm('cod', [
            'instructions' => "Have **{amount}** ready for {order_number}.\n\n[[payment_instructions]]\n\n<script>alert(1)</script> [x](javascript:alert(1))",
        ]), $this->staff);
        $html = PaymentOptions::instructionsHtml('cod', ['amount' => 'KSh 1,200.00', 'order_number' => 'BM-7K2L9XQ4']);
        $this->assertStringContainsString('Have <strong>KSh 1,200.00</strong> ready for BM-7K2L9XQ4.', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertDoesNotMatchRegularExpression('/<script|javascript:|wm-pay-step/', $html, 'no markup, no unsafe link, no nested block');

        $page = (new ContentRenderer())->render('[[payment_instructions]]');
        $this->assertStringContainsString('M-Pesa Buy Goods (Till)</h2>', $page);
        $this->assertStringContainsString('ready for your order number', $page);
    }

    // ------------------------------------------------------------------ secrets and email settings

    public function testSecretsAreEncryptedWriteOnlyAndAudited(): void
    {
        $store = new SecretStore();
        $store->set('smtp_password', 'S3cret-pa55word', $this->staff);
        $cipher = (string) $this->db->value("SELECT ciphertext FROM secrets WHERE name = 'smtp_password'");
        $this->assertStringNotContainsString('S3cret', $cipher);
        $this->assertStringNotContainsString('S3cret', (string) base64_decode($cipher));
        $this->assertSame('S3cret-pa55word', $store->get('smtp_password'));
        $status = $store->status('smtp_password');
        $this->assertTrue($status['set'] && $status['readable']);
        $this->assertStringNotContainsString('S3cret', json_encode($status));
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE CONCAT(COALESCE(before_json,''), COALESCE(after_json,'')) LIKE '%S3cret%'"), 'never in the audit log');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'secret.updated'"));

        // Settings choose SMTP: the transport uses the stored password; replies default to customer care.
        foreach (['mail_driver' => 'smtp', 'smtp_host' => 'smtp.example.test', 'smtp_username' => 'shop@example.test', 'mail_from_email' => 'shop@example.test', 'store_email' => 'care@example.test'] as $k => $val) {
            Settings::set($k, $val);
        }
        Settings::flush();
        $cfg = NotificationService::mailConfig();
        $this->assertSame(['smtp', 'smtp.example.test', 'S3cret-pa55word', 'settings', 'care@example.test', 'shop@example.test'],
            [$cfg['driver'], $cfg['host'], $cfg['password'], $cfg['source'], $cfg['reply_to'], $cfg['from_email']]);
        Settings::set('mail_driver', '');
        Settings::flush();
        $this->assertSame('server', NotificationService::mailConfig()['source']);

        // Unreadable (e.g. the app key changed): reported, never returned as garbage.
        $this->db->exec("UPDATE secrets SET ciphertext = ? WHERE name = 'smtp_password'", [base64_encode(random_bytes(48))]);
        $this->assertNull($store->get('smtp_password'));
        $this->assertFalse($store->status('smtp_password')['readable']);
        $store->clear('smtp_password', $this->staff);
        $this->assertFalse($store->status('smtp_password')['set']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'secret.removed'"));

        $this->expectException(\InvalidArgumentException::class);
        $store->set('anything_else', 'x', $this->staff);
    }

    public function testEmailSettingsValidation(): void
    {
        $svc = new SettingsService();
        $base = ['smtp_port' => '587', 'smtp_encryption' => 'tls'];
        $this->fails(fn () => $svc->save(['mail_driver' => 'smtp'] + $base, $this->staff, 'email'), 'smtp_host', 'mail server');
        $this->fails(fn () => $svc->save(['mail_driver' => 'carrier-pigeon'] + $base, $this->staff, 'email'), 'mail_driver');
        $this->fails(fn () => $svc->save(['mail_from_email' => 'not-an-email'] + $base, $this->staff, 'email'), 'mail_from_email');
        $this->fails(fn () => $svc->save(['smtp_port' => '70000', 'smtp_encryption' => 'tls'], $this->staff, 'email'), 'smtp_port');
        $svc->save(['mail_driver' => 'smtp', 'smtp_host' => 'smtp.example.test', 'mail_from_name' => 'Ecommerce Care'] + $base, $this->staff, 'email');
        Settings::flush();
        $this->assertSame('Ecommerce Care', NotificationService::mailConfig()['from_name']);
        $this->assertArrayNotHasKey('smtp_host', (new SettingsService())->export($this->staff)['settings'], 'server-specific: not exported');
    }

    // ------------------------------------------------------------------ message templates

    public function testBuiltInWordingIsUnchanged(): void
    {
        $o = ['customer_name' => 'Jane Wanjiku', 'order_number' => 'BM-7K2L9XQ4', 'grand_total' => '2450.00'];
        $m = (new MessageTemplates())->render('order_placed', ['next_step' => 'Please pay.', 'track_url' => 'https://shop.test/track/x'] + MessageTemplates::orderVars($o));
        $this->assertSame('Order BM-7K2L9XQ4 placed', $m['subject']);
        $this->assertSame("Hello Jane Wanjiku,\n\nThank you for shopping with Ecommerce. Your order BM-7K2L9XQ4 has been placed.\nTotal: KSh 2,450\n\nPlease pay.\n\nTrack your order: https://shop.test/track/x\n\nEcommerce", $m['email']);
        $this->assertSame('Ecommerce: order BM-7K2L9XQ4 placed. Please pay. Track: https://shop.test/track/x', $m['sms']);
        $s = (new MessageTemplates())->render('seller_message', ['first_name' => 'Amina', 'subject' => 'New order', 'message' => 'Prepare it.'] + MessageTemplates::contactVars());
        $this->assertSame(["New order", "Hello Amina,\n\nPrepare it.\n\n— Ecommerce Seller Support", 'Ecommerce Seller Centre: New order'], [$s['subject'], $s['email'], $s['sms']]);
    }

    public function testEditedTemplatesAreSentAndChannelsCanBeSwitchedOff(): void
    {
        $tpl = new MessageTemplates();
        $tpl->save('order_confirmed', ['email_subject' => '{order_number} is confirmed', 'email_body' => "Hi {first_name}! {order_number} ({order_total}) is confirmed.\n\n{store}",
            'sms_body' => '{store}: {order_number} confirmed.', 'email_enabled' => '1'], $this->staff);   // SMS switched off
        $o = $this->placeOrder([[$this->makeVariant(5, '1000.00')['variant_id'], 1]], 'cod');
        $o = ['status' => 'confirmed'] + (array) $this->db->one('SELECT * FROM orders WHERE id = ?', [$o['id']]);
        OrderNotifier::statusChanged($o);
        $rows = $this->db->all("SELECT channel, subject, body FROM notifications WHERE order_id = ? AND template = 'order_confirmed'", [$o['id']]);
        $this->assertCount(1, $rows, 'email only');
        $this->assertSame('email', $rows[0]['channel']);
        $this->assertSame($o['order_number'] . ' is confirmed', $rows[0]['subject']);
        $this->assertSame("Hi Test! {$o['order_number']} (KSh 1,200) is confirmed.\n\nEcommerce", $rows[0]['body']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'messages.template_updated' AND entity_id = 'order_confirmed'"));

        $tpl->restore('order_confirmed', $this->staff);
        $this->assertFalse($tpl->get('order_confirmed')['edited']);
        $this->assertTrue($tpl->get('order_confirmed')['sms_enabled']);
    }

    public function testTemplateValidationAndSafety(): void
    {
        $tpl = new MessageTemplates();
        $ok = ['email_subject' => 'Ready', 'email_body' => 'Code {collection_code}', 'sms_body' => 'Code {collection_code}'];
        $this->fails(fn () => $tpl->save('order_ready_for_collection', ['email_body' => 'Your order is ready.'] + $ok, $this->staff), 'email_body', '{collection_code}');
        $this->fails(fn () => $tpl->save('order_ready_for_collection', ['sms_body' => 'Ready.'] + $ok, $this->staff), 'sms_body', '{collection_code}');
        $this->fails(fn () => $tpl->save('order_ready_for_collection', ['email_body' => 'Code {collection_code} {password}'] + $ok, $this->staff), 'email_body', 'Unknown placeholder');
        $this->fails(fn () => $tpl->save('order_ready_for_collection', ['email_subject' => "Ready\r\nBcc: x@evil.test"] + $ok, $this->staff), 'email_subject', 'one line');
        $this->fails(fn () => $tpl->save('order_ready_for_collection', ['sms_body' => str_repeat('x', 470) . ' {collection_code}'] + $ok, $this->staff), 'sms_body', '480');
        $this->fails(fn () => $tpl->save('password_reset', ['email_subject' => 'Reset', 'email_body' => 'Click here'], $this->staff), 'email_body', '{reset_link}');

        // Essential messages cannot be switched off, whatever is posted.
        $tpl->save('order_ready_for_collection', $ok, $this->staff);   // no *_enabled fields posted
        $t = $tpl->get('order_ready_for_collection');
        $this->assertTrue($t['email_enabled'] && $t['sms_enabled']);

        // Values are filled in one pass: a customer called "{collection_code}" cannot pull the code into the greeting.
        $m = $tpl->render('order_ready_for_collection', ['customer_name' => '{collection_code}', 'collection_code' => '483920'] + MessageTemplates::contactVars());
        $this->assertSame('Code 483920', $m['email']);
        $m = (new MessageTemplates())->render('order_cancelled', ['customer_name' => '{collection_code}', 'order_number' => 'BM-1', 'status_label' => 'Cancelled'] + MessageTemplates::contactVars());
        $this->assertStringStartsWith('Hello {collection_code},', (string) $m['email']);
    }

    public function testPreferencesAreRespectedExceptForEssentialMessages(): void
    {
        $user = $this->db->insert('users', ['email' => 'pref@example.com', 'phone' => '254711000222', 'password_hash' => 'x', 'first_name' => 'Pref', 'last_name' => 'User',
            'notify_email' => 0, 'notify_sms' => 0]);
        $prefs = $this->db->one('SELECT notify_email, notify_sms FROM users WHERE id = ?', [$user]);
        $vars = ['order_number' => 'BM-1', 'customer_name' => 'Pref User', 'refund_amount' => 'KSh 1.00', 'refund_method' => 'M-Pesa', 'refund_reference' => 'X'];
        $n = new NotificationService();
        $this->assertSame(0, $n->sendTemplate('payment_verified', $vars, ['email' => 'pref@example.com', 'phone' => '254711000222', 'prefs' => $prefs]));
        $this->assertSame(2, $n->sendTemplate('refund_paid', $vars, ['email' => 'pref@example.com', 'phone' => '254711000222', 'prefs' => $prefs]), 'money: always sent');
        $this->assertSame(2, $n->sendTemplate('payment_verified', $vars, ['email' => 'guest@example.com', 'phone' => '254711000333']), 'guests: sent');
    }

    public function testWelcomeEmailOnRegistration(): void
    {
        $u = (new AccountService($this->db))->register(['first_name' => 'Akinyi', 'last_name' => 'Otieno', 'email' => 'akinyi@example.com', 'phone' => '0712 999 111',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => '1'], '127.0.0.1');
        $row = $this->db->one("SELECT recipient, subject, body FROM notifications WHERE template = 'account_welcome' AND user_id = ?", [$u['id']]);
        $this->assertSame('akinyi@example.com', $row['recipient']);
        $this->assertSame('Welcome to Ecommerce', $row['subject']);
        $this->assertStringContainsString('Hello Akinyi,', $row['body']);
        $this->assertStringContainsString('/login with your email or phone number', $row['body']);
    }

    // ------------------------------------------------------------------ delivery settings and status names

    public function testDeliverySwitchesAndStoreWideFreeDelivery(): void
    {
        $v = $this->makeVariant(20, '1500.00')['variant_id'];
        $cart = new CartService(null, null, $this->db);
        $cart->add($v, 1);
        $in = ['customer_name' => 'Buyer', 'customer_phone' => '0722000111', 'customer_email' => 'b@example.com', 'fulfilment_method' => 'delivery',
               'county_id' => '47', 'town' => 'Kilimani', 'location' => 'Argwings Kodhek Rd', 'payment_method' => 'cod',
               'pickup_point_id' => (string) $this->db->value('SELECT id FROM pickup_points LIMIT 1')];
        $checkout = new CheckoutService($this->db);
        $this->assertSame('200.00', $checkout->prepare($cart, $in, null)['delivery_fee']);

        // Store-wide free delivery applies to zones without their own amount…
        (new SettingsService())->save(['delivery_home_enabled' => '1', 'delivery_pickup_enabled' => '1', 'free_delivery_threshold' => '1000'], $this->staff, 'delivery');
        $this->assertSame('0.00', $checkout->prepare($cart, $in, null)['delivery_fee']);
        // …and a zone's own amount wins.
        $this->db->exec("UPDATE delivery_zones SET free_delivery_threshold = 5000");
        $this->assertSame('200.00', $checkout->prepare($cart, $in, null)['delivery_fee']);

        // Home delivery switched off: not offered, refused if posted; pickup still works.
        (new SettingsService())->save(['delivery_pickup_enabled' => '1', 'delivery_checkout_note' => 'Riders call first.'], $this->staff, 'delivery');
        $this->assertFalse((new DeliveryService($this->db))->deliveryAvailable());
        $this->assertTrue((new DeliveryService($this->db))->pickupAvailable());
        $this->fails(fn () => $checkout->prepare($cart, $in, null), 'fulfilment_method', 'Home delivery is not available');
        $this->assertSame('pickup', $checkout->prepare($cart, ['fulfilment_method' => 'pickup'] + $in, null)['fulfilment']['method']);
        $this->fails(fn () => (new SettingsService())->save([], $this->staff, 'delivery'), 'delivery_home_enabled', 'at least one');
        $this->fails(fn () => (new SettingsService())->save(['delivery_home_enabled' => '1', 'free_delivery_threshold' => 'free'], $this->staff, 'delivery'), 'free_delivery_threshold');
    }

    public function testStatusNamesCanBeRenamedAndRestored(): void
    {
        $svc = new StatusLabels();
        $svc->save(['dispatched' => 'On the way', 'delivered' => OrderPresenter::STATUS_LABELS['delivered']], $this->staff);
        $this->assertSame('On the way', OrderPresenter::status('dispatched'));
        $this->assertSame('Delivered', OrderPresenter::status('delivered'));
        $this->assertSame(['dispatched' => true], StatusLabels::edited(), 'the original name is not stored as an edit');
        $m = (new MessageTemplates())->render('order_dispatched', ['order_number' => 'BM-1', 'status_label' => OrderPresenter::status('dispatched')] + MessageTemplates::contactVars());
        $this->assertSame('Order BM-1: On the way', $m['subject']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'delivery.status_labels_updated'"));

        $this->fails(fn () => $svc->save(['dispatched' => str_repeat('x', 81)], $this->staff), 'label.dispatched');
        $svc->save(['dispatched' => ''], $this->staff);
        $this->assertSame('Dispatched', OrderPresenter::status('dispatched'));
        $this->assertSame([], StatusLabels::edited());
    }
}
