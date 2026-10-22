<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\Settings;

/**
 * Admin → Email & SMS over HTTP, with the SMS gateway pointed at a local stand-in for Talksasa
 * (tests/Support/fake-talksasa.php), so the real request format is exercised without sending real SMS.
 */
final class MessagingHttpTest extends FeatureTestCase
{
    private const FAKE_PORT = 8092;
    /** @var resource|null */
    private static $fake = null;
    private static string $fakeLog = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$fakeLog = sys_get_temp_dir() . '/fake-talksasa-' . getmypid() . '.log';
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $cmd = sprintf('"%s" -S 127.0.0.1:%d "%s"', PHP_BINARY, self::FAKE_PORT, dirname(__DIR__) . '/Support/fake-talksasa.php');
        self::$fake = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, dirname(__DIR__),
            array_merge(getenv(), ['FAKE_TALKSASA_LOG' => self::$fakeLog]), ['bypass_shell' => true]);
        for ($i = 0; $i < 50; $i++) {
            if ($fp = @fsockopen('127.0.0.1', self::FAKE_PORT, $errno, $errstr, 0.2)) {
                fclose($fp);
                return;
            }
            usleep(100_000);
        }
        throw new \RuntimeException('Fake SMS gateway did not start.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$fake)) {
            $status = proc_get_status(self::$fake);
            if (PHP_OS_FAMILY === 'Windows' && !empty($status['pid'])) {
                exec('taskkill /F /T /PID ' . (int) $status['pid'] . ' 2>NUL');
            }
            proc_terminate(self::$fake);
        }
        @unlink(self::$fakeLog);
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        @unlink(self::$fakeLog);
        Settings::set('sms_driver', '');
        Settings::set('sms_sender_id', '');
        Settings::set('sms_api_url', 'https://bulksms.talksasa.com/api/v3/');
    }

    protected function tearDown(): void
    {
        // Settings survive between tests: leave SMS switched off for every other test.
        Settings::set('sms_driver', '');
        Settings::set('sms_sender_id', '');
        Settings::set('sms_api_url', 'https://bulksms.talksasa.com/api/v3/');
        parent::tearDown();
    }

    /** @return list<array<string,mixed>> requests the fake gateway received */
    private function gatewayRequests(): array
    {
        $lines = is_file(self::$fakeLog) ? file(self::$fakeLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
        return array_map(fn ($l) => json_decode($l, true), $lines ?: []);
    }

    private function setUpJarOnly(): void
    {
        (new \ReflectionProperty(FeatureTestCase::class, 'jar'))->setValue($this, tempnam(sys_get_temp_dir(), 'wmjar'));
    }

    private function flash(array $r): string
    {
        return $this->follow($r)['body'];
    }

    public function testOnlyPermittedStaffReachEmailAndSms(): void
    {
        $this->assertSame(303, $this->get('/admin/messaging')['status'], 'signed out: to the login page');
        $this->setUpJarOnly();   // forget the page asked for while signed out
        foreach (['support', 'fulfilment_staff', 'content_marketing'] as $role) {
            $this->post('/logout', [], '/');
            $this->loginStaff($role);
            $this->assertSame(403, $this->get('/admin/messaging')['status'], $role);
            $this->assertSame(403, $this->post('/admin/messaging/test-sms', ['phone' => '0712345678'], '/admin/account')['status'], $role);
        }
        $this->post('/logout', [], '/');
        $this->loginStaff('super_admin');
        $page = $this->get('/admin/messaging');
        $this->assertSame(200, $page['status']);
        foreach (['SMS balance', 'Sent SMS', 'Failed SMS', 'Sent emails', 'Failed emails', 'How notifications are sent', 'SMS gateway (Talksasa)', 'Email server', 'Test your setup', 'Send test SMS', 'Send test email', 'The result of your test SMS appears here', 'Message log', '<strong>SMS is switched off.</strong>'] as $text) {
            $this->assertStringContainsString($text, $page['body']);
        }
        $this->assertSame(303, $this->get('/admin/settings/sms')['status'], 'the SMS settings group opens this page');
    }

    public function testGatewaySetupBalanceTestSmsFailuresRetryAndDeliveryReports(): void
    {
        $this->loginStaff('super_admin');
        $fakeUrl = 'http://127.0.0.1:' . self::FAKE_PORT . '/api/v3/';

        // Switching SMS on needs a sender ID; endpoints must be https (a local test address aside).
        $r = $this->flash($this->post('/admin/messaging/sms', ['sms_driver' => 'talksasa', 'sms_sender_id' => '', 'sms_api_url' => ''], '/admin/messaging'));
        $this->assertStringContainsString('Enter the sender ID approved on your Talksasa account', $r);
        $r = $this->flash($this->post('/admin/messaging/sms', ['sms_driver' => '', 'sms_sender_id' => 'BASEMALL', 'sms_api_url' => 'http://evil.example/api/'], '/admin/messaging'));
        $this->assertStringContainsString('must start with https://', $r);
        $r = $this->flash($this->post('/admin/messaging/sms', ['sms_driver' => '', 'sms_sender_id' => 'BASEMALL', 'sms_api_url' => $fakeUrl], '/admin/messaging'));
        $this->assertStringContainsString('SMS settings saved', $r);

        // A wrong token is saved but reported; the right one shows the balance.
        $r = $this->flash($this->post('/admin/messaging/token', ['secret_sms_api_token' => 'wrong-token'], '/admin/messaging'));
        $this->assertStringContainsString('did not accept the API token', $r);
        $r = $this->flash($this->post('/admin/messaging/token', ['secret_sms_api_token' => 'good-token'], '/admin/messaging'));
        $this->assertStringContainsString('Talksasa accepted it: balance 1,234', $r);
        $page = $this->get('/admin/messaging')['body'];
        $this->assertStringContainsString('1,234', $page);
        $this->assertStringNotContainsString('good-token', $page, 'the token is never shown');
        $this->assertNotSame('good-token', $this->db->value("SELECT ciphertext FROM secrets WHERE name = 'sms_api_token'"), 'stored encrypted');

        // Test SMS works before customer SMS is switched on, and says so.
        $r = $this->flash($this->post('/admin/messaging/test-sms', ['phone' => '0712 345 678', 'message' => 'Hello from the test'], '/admin/messaging'));
        $this->assertStringContainsString('Test SMS sent to 0712 345 678', $r);
        $this->assertStringContainsString('Customer SMS is still switched off', $r);
        $send = array_values(array_filter($this->gatewayRequests(), fn ($q) => $q['path'] === '/api/v3/sms/send'));
        $this->assertSame(['recipient' => '254712345678', 'sender_id' => 'BASEMALL', 'type' => 'plain', 'message' => 'Hello from the test'], $send[0]['body']);
        $this->assertSame('Bearer good-token', $send[0]['auth']);

        $r = $this->flash($this->post('/admin/messaging/test-sms', ['phone' => '0700 000 000'], '/admin/messaging'));
        // Shown under the test form in plain words, with the fix and the gateway's own message.
        $this->assertStringContainsString('Talksasa did not accept the phone number.', $r);
        $this->assertStringContainsString('What to do: </span>Use a Kenyan mobile number', $r);
        $this->assertStringContainsString('Technical details</summary><code class="d-block text-break mt-1">Invalid phone number</code>', $r);
        $this->assertStringContainsString('role="alert"', $r);
        $r = $this->flash($this->post('/admin/messaging/test-sms', ['phone' => '12'], '/admin/messaging'));
        $this->assertStringContainsString('Enter a Kenyan mobile number', $r);

        // Switch customer SMS on; the counters and the log reflect what happened.
        $this->assertStringContainsString('SMS settings saved', $this->flash($this->post('/admin/messaging/sms', ['sms_driver' => 'talksasa', 'sms_sender_id' => 'BASEMALL', 'sms_api_url' => $fakeUrl], '/admin/messaging')));
        $this->post('/admin/messaging/test-sms', ['phone' => '0711 111 111'], '/admin/messaging');   // accepted, later reported as not delivered
        $page = $this->get('/admin/messaging')['body'];
        $this->assertStringNotContainsString('<strong>SMS is switched off.</strong>', $page);
        $this->assertMatchesRegularExpression('#Sent SMS</div>\s*<div class="wm-stat-value">2</div>#', $page);
        $this->assertMatchesRegularExpression('#Failed SMS</div>\s*<div class="wm-stat-value text-danger">1</div>#', $page);
        $failedPage = $this->get('/admin/messaging?channel=sms&status=failed')['body'];
        $this->assertStringContainsString('Invalid phone number', $failedPage);
        $this->assertStringContainsString('Showing 1–1 of 1', $failedPage);
        $this->assertStringContainsString('Showing 1–1 of 1', $this->get('/admin/messaging?q=0711')['body'], 'search by phone (stored as 254…)');

        // Re-sending a failed SMS: it is still refused by the gateway, and that is reported.
        $failedId = (int) $this->db->value("SELECT id FROM notifications WHERE channel = 'sms' AND status = 'failed' ORDER BY id LIMIT 1");
        $r = $this->post("/admin/messaging/$failedId/retry", ['channel' => 'sms', 'status' => 'failed'], '/admin/messaging?channel=sms&status=failed');
        $this->assertStringContainsString('channel=sms', $r['headers']['location'], 'back to the same filters');
        $this->assertStringContainsString('Still failing: Invalid phone number', $this->follow($r)['body']);

        // Delivery reports: the 0711… SMS is reported failed and moves to Failed; the other is Delivered.
        $r = $this->flash($this->post('/admin/messaging/delivery', [], '/admin/messaging'));
        $this->assertStringContainsString('Delivery reports checked for 2 SMS: 2 updated, 1 not delivered', $r);
        $this->assertSame('Delivered', $this->db->value("SELECT provider_status FROM notifications WHERE recipient = '254712345678' AND channel = 'sms'"));
        $this->assertSame(['failed', 'Not delivered: Failed'], array_values($this->db->one("SELECT status, last_error FROM notifications WHERE recipient = '254711111111'")));
        $this->assertStringContainsString('1 delivered', $this->get('/admin/messaging')['body']);

        // Test email (mail log in testing) and the audit trail, which never holds the token.
        $r = $this->flash($this->post('/admin/messaging/test-email', ['email' => 'owner@example.com'], '/admin/messaging'));
        $this->assertStringContainsString('written to the mail log', $r);
        $audit = (string) $this->db->value("SELECT GROUP_CONCAT(CONCAT(action, ' ', COALESCE(after_json, '')) SEPARATOR ' | ') FROM audit_logs");
        $this->assertStringContainsString('messaging.test_sms', $audit);
        $this->assertStringContainsString('messaging.delivery_checked', $audit);
        $this->assertStringContainsString('secret.updated', $audit);
        $this->assertStringNotContainsString('good-token', $audit);
        $this->assertStringNotContainsString('good-token', (string) $this->db->value('SELECT GROUP_CONCAT(COALESCE(last_error, \'\')) FROM notifications'));
    }

    /** Audit A-4: redirecting the mail server or SMS endpoint would hand over the stored password/token and reset codes. */
    public function testOnlyCredentialManagersCanChangeWhereCredentialsAreSent(): void
    {
        $u = $this->loginStaff('support');
        $this->db->exec("INSERT INTO user_permissions (user_id, permission_id) SELECT ?, id FROM permissions WHERE slug IN ('messaging.manage', 'settings.manage')", [$u['id']]);
        Settings::set('smtp_host', 'mail.example.com');
        $evil = ['mail_driver' => 'smtp', 'smtp_host' => 'attacker.example', 'smtp_port' => '587', 'smtp_encryption' => 'tls', 'smtp_username' => 'x'];

        foreach (['/admin/messaging/email' => '/admin/messaging', '/admin/settings/email' => '/admin/settings/email'] as $action => $form) {
            $r = $this->follow($this->post($action, $evil, $form))['body'];
            $this->assertStringContainsString('Only staff allowed to manage credentials can change this', $r, $action);
        }
        $r = $this->follow($this->post('/admin/messaging/sms', ['sms_driver' => '', 'sms_sender_id' => 'X', 'sms_api_url' => 'https://attacker.example/api/'], '/admin/messaging'))['body'];
        $this->assertStringContainsString('Only staff allowed to manage credentials can change this', $r);
        $this->assertSame(['mail.example.com', 'https://bulksms.talksasa.com/api/v3/'], [(string) $this->db->value("SELECT setting_value FROM settings WHERE setting_key = 'smtp_host'"),
            (string) $this->db->value("SELECT setting_value FROM settings WHERE setting_key = 'sms_api_url'")], 'nothing was redirected');

        // Other email settings (sender name) can still be changed without credential rights.
        $ok = $this->follow($this->post('/admin/messaging/email', ['mail_from_name' => 'Basemall Care', 'mail_driver' => 'smtp', 'smtp_host' => 'mail.example.com',
            'smtp_port' => (string) Settings::get('smtp_port', '587'), 'smtp_encryption' => (string) Settings::get('smtp_encryption', 'tls'),
            'smtp_username' => (string) Settings::get('smtp_username', '')], '/admin/messaging'))['body'];
        $this->assertStringContainsString('Email settings saved', $ok);
        Settings::set('smtp_host', '');
        Settings::set('mail_driver', '');
        Settings::set('mail_from_name', '');
    }

    public function testCustomerMessagesGoThroughTheGatewayOnceSwitchedOn(): void
    {
        $this->loginStaff('super_admin');
        $fakeUrl = 'http://127.0.0.1:' . self::FAKE_PORT . '/api/v3/';
        $this->post('/admin/messaging/token', ['secret_sms_api_token' => 'good-token'], '/admin/messaging');
        $this->post('/admin/messaging/sms', ['sms_driver' => 'talksasa', 'sms_sender_id' => 'BASEMALL', 'sms_api_url' => $fakeUrl], '/admin/messaging');
        $this->post('/logout', [], '/');
        $this->setUpJarOnly();

        // A guest places an order: "order placed" reaches the phone through the gateway, with the gateway's id kept.
        $zone = $this->db->insert('delivery_zones', ['name' => 'City', 'fee' => '250', 'min_days' => 1, 'max_days' => 2, 'cod_allowed' => 1]);
        $this->db->insert('delivery_zone_locations', ['zone_id' => $zone, 'county_id' => 47, 'town' => '']);
        $variant = $this->makeVariant(5, '1500.00')['variant_id'];
        $slug = (string) $this->db->value('SELECT p.slug FROM products p JOIN product_variants v ON v.product_id = p.id WHERE v.id = ?', [$variant]);
        $this->post('/cart/add', ['variant_id' => (string) $variant, 'quantity' => '1'], '/product/' . $slug);
        $page = $this->get('/checkout')['body'];
        preg_match('/name="checkout_token" value="([^"]+)"/', $page, $t);
        preg_match('/name="_csrf" value="([^"]+)"/', $page, $c);
        $r = $this->http('POST', '/checkout', ['_csrf' => html_entity_decode($c[1]), 'checkout_token' => $t[1],
            'customer_name' => 'Amina Otieno', 'customer_phone' => '0722 333 444', 'customer_email' => '',
            'fulfilment_method' => 'delivery', 'county_id' => '47', 'town' => 'Kilimani', 'location' => 'Argwings Kodhek', 'payment_method' => 'cod'], [], '/checkout');
        $this->assertSame(303, $r['status']);

        $sms = $this->db->one("SELECT status, provider_ref, template, body FROM notifications WHERE channel = 'sms' AND recipient = '254722333444'");
        $this->assertSame(['sent', 'order_placed'], [$sms['status'], $sms['template']]);
        $this->assertNotEmpty($sms['provider_ref']);
        $sent = array_values(array_filter($this->gatewayRequests(), fn ($q) => ($q['body']['recipient'] ?? '') === '254722333444'));
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('placed', (string) $sent[0]['body']['message']);
    }
}
