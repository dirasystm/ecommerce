<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Auth;
use Waimart\Core\Csv;
use Waimart\Core\ValidationException;
use Waimart\Services\CodService;
use Waimart\Services\ContentService;
use Waimart\Services\OrderWorkflow;
use Waimart\Services\PaymentService;
use Waimart\Services\ReportService;
use Waimart\Services\StaffService;
use Waimart\Repositories\CatalogueRepository;

/** Staff & roles, reports, content (Phase 10). */
final class AdminPhase10Test extends DbTestCase
{
    use PlacesOrders;

    private function staff(string $role, string $email): int
    {
        $id = $this->db->insert('users', ['email' => $email, 'password_hash' => 'x', 'first_name' => ucfirst($role), 'last_name' => 'T', 'is_staff' => 1]);
        $this->db->exec('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = ?', [$id, $role]);
        return $id;
    }

    private function refused(callable $fn, string $needle): void
    {
        try {
            $fn();
            $this->fail("Expected refusal: $needle");
        } catch (ValidationException $e) {
            $this->assertStringContainsString($needle, json_encode($e->errors) . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ staff

    public function testCreateStaffSendsPasswordLinkAndNeverSetsAKnownPassword(): void
    {
        $super = $this->staff('super_admin', 'boss@waimart.test');
        $svc = new StaffService($this->db);
        $id = $svc->create(['first_name' => 'Wairimu', 'last_name' => 'K', 'email' => 'Wairimu@Waimart.test', 'roles' => ['finance']], $super, '1.1.1.1');
        $u = $this->db->one('SELECT * FROM users WHERE id = ?', [$id]);
        $this->assertSame(['wairimu@waimart.test', 1], [$u['email'], (int) $u['is_staff']]);
        $this->assertSame(['finance'], $svc->rolesOf($id));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM password_resets WHERE user_id = ?", [$id]), 'set-password link issued');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'staff.created' AND entity_id = ?", [$id]));

        // Existing customer gets promoted rather than duplicated.
        $cust = $this->db->insert('users', ['email' => 'shop@example.com', 'password_hash' => 'x', 'first_name' => 'S', 'last_name' => 'C']);
        $this->assertSame($cust, $svc->create(['first_name' => 'S', 'last_name' => 'C', 'email' => 'shop@example.com', 'roles' => ['support']], $super, '1.1.1.1'));
        $this->refused(fn () => $svc->create(['first_name' => 'S', 'last_name' => 'C', 'email' => 'shop@example.com', 'roles' => ['support']], $super, '1.1.1.1'), 'already a staff member');
        $this->refused(fn () => $svc->create(['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@y.co', 'roles' => ['wizard']], $super, '1.1.1.1'), 'valid role');
    }

    public function testRoleSafeguards(): void
    {
        $super = $this->staff('super_admin', 'boss@waimart.test');
        $manager = $this->staff('manager', 'mgr@waimart.test');
        $clerk = $this->staff('inventory_clerk', 'clerk@waimart.test');
        $svc = new StaffService($this->db);

        $this->refused(fn () => $svc->updateRoles($super, ['manager'], $super), 'own roles');
        $this->refused(fn () => $svc->updateRoles($clerk, ['super_admin'], $manager), 'super administrator');
        $this->refused(fn () => $svc->updateRoles($super, ['support'], $manager), 'super administrator');
        $this->refused(fn () => $svc->setActive($super, false, $manager), 'last active super administrator');
        $this->refused(fn () => $svc->revoke($super, $manager), 'super administrator');
        $this->refused(fn () => $svc->setActive($manager, false, $manager), 'your own account');

        $svc->updateRoles($clerk, ['inventory_clerk', 'support'], $manager);
        $this->assertEqualsCanonicalizing(['inventory_clerk', 'support'], $svc->rolesOf($clerk));

        // With a second super admin, the first can be demoted by the other.
        $super2 = $this->staff('super_admin', 'boss2@waimart.test');
        $svc->updateRoles($super, ['manager'], $super2);
        $this->assertSame(['manager'], $svc->rolesOf($super));
        $this->refused(fn () => $svc->revoke($super2, $super), 'super administrator');
    }

    public function testSuspensionSignsOutImmediately(): void
    {
        $super = $this->staff('super_admin', 'boss@waimart.test');
        $clerk = $this->staff('inventory_clerk', 'clerk@waimart.test');
        $_SESSION = ['user_id' => $clerk, 'auth_at' => time() - 30];
        Auth::reset();
        $this->assertTrue(Auth::check());
        (new StaffService($this->db))->setActive($clerk, false, $super);
        Auth::reset();
        $this->assertFalse(Auth::check());
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'staff.suspended'"));
        $_SESSION = [];
    }

    // ------------------------------------------------------------------ reports

    public function testReportDefinitions(): void
    {
        $this->setUpFulfilment();
        $staff = $this->staffUser();
        $v = $this->makeVariant(20, '1000.00')['variant_id'];
        $wf = new OrderWorkflow($this->db);

        $cod = $this->placeOrder([[$v, 1]], 'cod');                       // booked 1,200 (incl. 200 delivery)
        foreach (['confirmed', 'processing', 'packed', 'dispatched', 'delivered'] as $to) {
            $wf->transition((int) $cod['id'], $to, ['type' => 'staff', 'id' => $staff]);
        }
        (new CodService($this->db))->record((int) $cod['id'], '1200', $staff, null);

        $mp = $this->placeOrder([[$v, 2]], 'mpesa_till');                 // booked 2,200
        $s = (new PaymentService($this->db))->submit((int) $mp['id'], ['transaction_code' => 'RPT1234567', 'payer_phone' => '0712345678', 'amount' => '2200'], '1.1.1.1');
        (new PaymentService($this->db))->verify((int) $s['id'], $staff, '2200', null);

        $cancelled = $this->placeOrder([[$v, 1]], 'cod');                 // excluded from booked sales
        $wf->transition((int) $cancelled['id'], 'cancelled', ['type' => 'customer'], null, ['reason' => 'x']);

        [$from, $to] = ReportService::range(null, null, 7);
        $sum = (new ReportService($this->db))->salesSummary($from, $to);
        $this->assertSame(2, $sum['orders']);
        $this->assertSame('3400.00', $sum['booked']);
        $this->assertSame(1, $sum['cancelled']);
        $this->assertSame(['2200.00', '1200.00', '3400.00'], [$sum['cash_mpesa'], $sum['cash_cod'], $sum['cash_total']]);

        $daily = (new ReportService($this->db))->daily($from, $to);
        $this->assertCount(7, $daily, 'every day present, including empty ones');
        $this->assertSame('3400.00', end($daily)['booked']);
        $this->assertSame('3', (string) (new ReportService($this->db))->topProducts($from, $to)[0]['units']);
        $this->tearDownFulfilment();
    }

    public function testRangeIsNormalisedAndCapped(): void
    {
        [$f, $t] = ReportService::range('2026-10-10', '2026-10-01');
        $this->assertSame(['2026-10-01 00:00:00', '2026-10-10 23:59:59'], [$f, $t], 'swapped');
        [$f] = ReportService::range('2020-01-01', '2026-10-01');
        $this->assertSame('2025-09-30 00:00:00', $f, 'capped at 366 days');
        [$f, $t] = ReportService::range("'; DROP TABLE orders; --", null);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} 00:00:00$/', $f, 'garbage ignored');
    }

    public function testCsvNeutralisesFormulaInjection(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://evil\")", Csv::cell('=HYPERLINK("http://evil")'));
        $this->assertSame("'+cmd", Csv::cell('+cmd'));
        $this->assertSame("'@SUM(A1)", Csv::cell('@SUM(A1)'));
        $this->assertSame('-150.00', Csv::cell('-150.00'), 'real negative numbers untouched');
        $this->assertSame('Jane', Csv::cell('Jane'));
        $body = Csv::response('x.csv', ['A'], [['=1+1']])->body;
        $this->assertStringContainsString("'=1+1", $body);
    }

    // ------------------------------------------------------------------ content

    public function testBannerLinksMustBeInternal(): void
    {
        $actor = $this->staffUser();
        $svc = new ContentService($this->db);
        foreach (['https://evil.example', '//evil.example', 'javascript:alert(1)'] as $bad) {
            $this->refused(fn () => $svc->saveBanner(null, ['title' => 'T', 'link_url' => $bad, 'is_active' => '1'], null, $actor), 'internal path');
        }
        $id = $svc->saveBanner(null, ['title' => 'Phones week', 'link_url' => '/category/phones?sort=newest', 'is_active' => '1'], null, $actor);
        $this->assertSame('/category/phones?sort=newest', $this->db->value('SELECT link_url FROM banners WHERE id = ?', [$id]));
        $this->refused(fn () => $svc->saveBanner(null, ['title' => 'T', 'starts_at' => '2026-10-10 10:00', 'ends_at' => '2026-10-09 10:00'], null, $actor), 'after the start');
    }

    public function testFlashSaleRules(): void
    {
        $actor = $this->staffUser();
        $v = $this->makeVariant(10, '1000.00');
        $this->db->exec('UPDATE products SET is_featured = 1');
        $sku = (string) $this->db->value('SELECT sku FROM product_variants WHERE id = ?', [$v['variant_id']]);
        $svc = new ContentService($this->db);
        $now = time();
        $a = $svc->saveSale(null, ['title' => 'A', 'starts_at' => date('Y-m-d H:i', $now - 3600), 'ends_at' => date('Y-m-d H:i', $now + 3600), 'is_active' => '1'], $actor);
        $b = $svc->saveSale(null, ['title' => 'B', 'starts_at' => date('Y-m-d H:i', $now + 1800), 'ends_at' => date('Y-m-d H:i', $now + 7200), 'is_active' => '1'], $actor);

        $this->refused(fn () => $svc->addSaleItem($a, $sku, '1000', $actor), 'below the normal price');
        $this->refused(fn () => $svc->addSaleItem($a, 'NOPE', '10', $actor), 'SKU');
        $svc->addSaleItem($a, strtolower($sku), '799', $actor);
        $this->refused(fn () => $svc->addSaleItem($b, $sku, '700', $actor), 'overlapping sale');

        CatalogueRepository::flushCache();
        $this->assertSame('799.00', (new CatalogueRepository($this->db))->featured()[0]['price'], 'storefront uses the sale price');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'content.flash_item_added'"));
    }
}
