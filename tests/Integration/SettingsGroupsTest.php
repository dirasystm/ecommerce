<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Repositories\ProductListing;
use Waimart\Services\AccountService;
use Waimart\Services\SettingsService;

/** Ecommerce Stage 2: grouped settings — isolation, validation, export/import, and the settings taking effect. */
final class SettingsGroupsTest extends DbTestCase
{
    /** @var array<string,string> */
    private array $snapshot = [];
    private int $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshot = array_column($this->db->all('SELECT setting_key, setting_value FROM settings'), 'setting_value', 'setting_key');
        $this->staff = $this->db->insert('users', ['email' => 'ops@settings.test', 'password_hash' => 'x', 'first_name' => 'O', 'last_name' => 'S', 'is_staff' => 1]);
    }

    protected function tearDown(): void
    {
        // Settings survive between tests: put every value back exactly.
        $now = array_column($this->db->all('SELECT setting_key FROM settings'), 'setting_key');
        foreach ($now as $k) {
            if (!array_key_exists($k, $this->snapshot)) {
                $this->db->exec('DELETE FROM settings WHERE setting_key = ?', [$k]);
            }
        }
        foreach ($this->snapshot as $k => $v) {
            Settings::set($k, (string) $v);
        }
        parent::tearDown();
    }

    private function save(string $group, array $in): void
    {
        (new SettingsService())->save($in, $this->staff, $group);
        Settings::flush();
    }

    private function refused(string $group, array $in, string $field): void
    {
        try {
            $this->save($group, $in);
            $this->fail("refused: $field");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors);
        }
    }

    public function testEveryRegisteredSettingHasAGroupAndAStoredDefault(): void
    {
        foreach (SettingsService::FIELDS as $key => $f) {
            $this->assertArrayHasKey($f[3], SettingsService::GROUPS, "$key belongs to a known group");
            $this->assertNotNull(Settings::get($key), "$key has a stored default (migrations)");
        }
        $this->assertSame(['allow_registration' => '1', 'products_per_page' => '24', 'maintenance_mode' => '0'],
            ['allow_registration' => Settings::get('allow_registration'), 'products_per_page' => Settings::get('products_per_page'), 'maintenance_mode' => Settings::get('maintenance_mode')],
            'defaults keep the shop behaving as before');
    }

    public function testSavingOnePageNeverTouchesAnother(): void
    {
        Settings::set('reservation_minutes', '45');
        Settings::set('cod_auto_confirm', '1');
        Settings::set('mpesa_till_number', '123456');
        $this->save('contact', ['store_phone' => '0700 000 111', 'store_email' => 'care@example.test', 'whatsapp_number' => '0712 345 678']);
        $this->assertSame(['45', '1', '123456'], [Settings::get('reservation_minutes'), Settings::get('cod_auto_confirm'), Settings::get('mpesa_till_number')],
            'orders and payments untouched, including an unrelated checkbox');
        $this->assertSame('254712345678', Settings::get('whatsapp_number'), 'phone normalised');
        $log = (array) $this->db->one("SELECT * FROM audit_logs WHERE action = 'settings.updated' ORDER BY id DESC LIMIT 1");
        $this->assertStringContainsString('care@example.test', (string) $log['after_json']);
        $this->assertStringNotContainsString('reservation_minutes', (string) $log['after_json']);

        $this->expectException(\InvalidArgumentException::class);
        (new SettingsService())->save([], $this->staff, 'no-such-group');
    }

    public function testValidationRules(): void
    {
        $this->refused('social', ['social_facebook' => 'http://facebook.com/ecommerce'], 'social_facebook');
        $this->refused('social', ['social_x' => 'javascript:alert(1)'], 'social_x');
        $this->save('social', ['social_facebook' => 'https://facebook.com/ecommerce']);
        $this->assertSame('https://facebook.com/ecommerce', Settings::get('social_facebook'));

        foreach (['javascript:alert(1)', '//evil.example', 'http://evil.example', 'data:text/html,x', '/x"onmouseover=1'] as $bad) {
            $this->refused('maintenance', ['announcement_enabled' => '1', 'announcement_text' => 'Hi', 'announcement_link' => $bad], 'announcement_link');
        }
        $this->refused('maintenance', ['announcement_enabled' => '1', 'announcement_text' => ''], 'announcement_text');
        $this->save('maintenance', ['announcement_enabled' => '1', 'announcement_text' => 'Free pickup this week', 'announcement_link' => '/flash-sales']);
        $this->assertSame(['1', '/flash-sales'], [Settings::get('announcement_enabled'), Settings::get('announcement_link')]);

        $this->refused('general', ['products_per_page' => '500'], 'products_per_page');
        $this->refused('general', ['products_per_page' => '12', 'store_description' => str_repeat('x', 301)], 'store_description');
        $this->refused('contact', ['whatsapp_number' => '12345'], 'whatsapp_number');
        $this->refused('seo', ['seo_title' => str_repeat('t', 71)], 'seo_title');
    }

    public function testExportLeavesOutPaymentNumbersAndImportIsAllOrNothing(): void
    {
        Settings::set('mpesa_till_number', '5123456');
        $svc = new SettingsService();
        $export = $svc->export($this->staff);
        $this->assertArrayNotHasKey('mpesa_till_number', $export['settings']);
        $this->assertArrayHasKey('products_per_page', $export['settings']);

        // Export → import of an unchanged shop changes nothing.
        $this->assertSame(0, $svc->import(json_encode($export), $this->staff)['changed']);

        // A bad value anywhere: nothing is imported.
        $file = $export;
        $file['settings']['products_per_page'] = '36';
        $file['settings']['social_x'] = 'http://insecure.example';
        try {
            $svc->import(json_encode($file), $this->staff);
            $this->fail('invalid file refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors);
        }
        Settings::flush();
        $this->assertSame('24', Settings::get('products_per_page'), 'nothing applied');

        // Valid: applied and audited; payment numbers and unknown keys are ignored, never applied.
        $file['settings']['social_x'] = 'https://x.com/ecommerce';
        $file['settings']['mpesa_till_number'] = '9999999';
        $file['settings']['brand_primary'] = '#FF0000';
        $file['settings']['no_such_setting'] = 'x';
        $r = $svc->import(json_encode($file), $this->staff);
        Settings::flush();
        $this->assertSame(['36', 'https://x.com/ecommerce', '5123456'], [Settings::get('products_per_page'), Settings::get('social_x'), Settings::get('mpesa_till_number')]);
        $this->assertNotSame('#FF0000', Settings::get('brand_primary'), 'branding is not importable through settings');
        $this->assertEqualsCanonicalizing(['mpesa_till_number', 'brand_primary', 'no_such_setting'], $r['ignored']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.imported'"), 'the no-op import wrote no audit entry');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.exported'"));
        foreach (['not json', '{"settings":"x"}', '{"settings":{"no_such":"1"}}'] as $junk) {
            try {
                $svc->import($junk, $this->staff);
                $this->fail("refused: $junk");
            } catch (ValidationException) {
            }
        }
    }

    public function testProductsPerPageDrivesListings(): void
    {
        for ($i = 0; $i < 14; $i++) {
            $this->makeVariant(5);
        }
        $this->save('general', ['products_per_page' => '12', 'store_description' => '']);
        $r = (new ProductListing($this->db))->find([]);
        $this->assertSame([12, 12, 2], [$r['per_page'], count($r['items']), $r['pages']]);
        Settings::set('products_per_page', '9999');   // even a bad stored value is kept within 12–96
        Settings::flush();
        $this->assertSame(96, (new ProductListing($this->db))->find([])['per_page']);
    }

    public function testClosedRegistrationIsEnforcedOnTheServer(): void
    {
        $this->save('checkout', ['allow_registration' => '0']);   // guest_email_required omitted → off
        $this->assertFalse(AccountService::registrationOpen());
        try {
            (new AccountService($this->db))->register(['first_name' => 'A', 'last_name' => 'B', 'email' => 'new@example.com', 'phone' => '0711 000 222',
                'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => '1'], '10.1.1.1');
            $this->fail('closed');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('cannot be created', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM users WHERE email = 'new@example.com'"));
    }
}
