<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

final class AdminPhase10HttpTest extends FeatureTestCase
{
    public function testDashboardWidgetsFollowPermissions(): void
    {
        $this->loginStaff('manager');
        $page = $this->get('/admin')['body'];
        $this->assertStringContainsString('Booked sales today', $page);
        $this->assertStringContainsString('Booked sales per day', $page);
        $this->assertStringContainsString('Show as table', $page, 'chart has a table view');
        $this->assertStringContainsString('M-Pesa payments to verify', $page);
        $this->post('/logout', [], '/admin');

        $this->loginStaff('inventory_clerk');
        $page = $this->get('/admin')['body'];
        $this->assertStringNotContainsString('Booked sales today', $page, 'no sales data without reports/orders permission');
        $this->assertStringNotContainsString('M-Pesa payments to verify', $page);
        $this->assertStringContainsString('Variants low on stock', $page);
    }

    public function testReportsAndCsvExportAreAudited(): void
    {
        $this->loginStaff('finance');
        $this->assertSame(200, $this->get('/admin/reports?tab=sales')['status']);
        $csv = $this->get('/admin/reports?tab=stock&format=csv');
        $this->assertSame(200, $csv['status']);
        $this->assertStringContainsString('text/csv', $csv['headers']['content-type']);
        $this->assertStringContainsString('attachment; filename="ecommerce-stock-', $csv['headers']['content-disposition']);
        $this->assertStringContainsString('SKU,Product', $csv['body']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'report.exported'"));
        $this->assertSame(403, $this->get('/admin/audit')['status'], 'finance has no audit.view');
    }

    public function testStockReportShowsLowestOnScreenAndEverythingInCsv(): void
    {
        $cat = $this->db->insert('categories', ['name' => 'Bulk', 'slug' => 'bulk-' . bin2hex(random_bytes(3))]);
        $pid = $this->db->insert('products', ['seller_id' => \Waimart\Services\Sellers::platformId($this->db), 'category_id' => $cat, 'name' => 'Bulk item', 'slug' => 'bulk-item-' . bin2hex(random_bytes(3))]);
        for ($i = 0; $i < 205; $i++) {
            $v = $this->db->insert('product_variants', ['product_id' => $pid, 'sku' => sprintf('BULK-%03d', $i), 'name' => "Size $i", 'price' => '100', 'is_default' => (int) ($i === 0)]);
            $this->db->insert('inventory', ['variant_id' => $v, 'on_hand' => $i === 204 ? 0 : 50]);
        }
        $this->loginStaff('finance');
        $page = $this->get('/admin/reports?tab=stock')['body'];
        $this->assertStringContainsString('Showing the 200 variants with the least available stock, out of 205', $page);
        $this->assertStringContainsString('BULK-204', $page, 'the out-of-stock variant is listed first');
        $csv = $this->get('/admin/reports?tab=stock&format=csv')['body'];
        $this->assertSame(206, substr_count(trim($csv), "\n") + 1, 'header + every variant');
    }

    public function testStaffAdministrationIsSuperAdminOnlyAndAudited(): void
    {
        $this->loginStaff('manager');
        $this->assertSame(403, $this->get('/admin/staff')['status']);
        $this->assertSame(200, $this->get('/admin/audit')['status']);
        $this->assertSame(200, $this->get('/admin/customers')['status']);
        $this->post('/logout', [], '/admin');

        $this->loginStaff('super_admin');
        $r = $this->post('/admin/staff', ['first_name' => 'New', 'last_name' => 'Rider', 'email' => 'rider@waimart.test', 'roles' => ['fulfilment_staff']], '/admin/staff/new');
        $this->assertStringContainsString('emailed a link', $this->follow($r)['body']);
        $id = (int) $this->db->value("SELECT id FROM users WHERE email = 'rider@waimart.test'");
        $audit = $this->get('/admin/audit?entity=user&entity_id=' . $id)['body'];
        $this->assertStringContainsString('staff.created', $audit);
        $this->assertStringContainsString('fulfilment_staff', $audit);
    }

    public function testContentScreensRequirePermission(): void
    {
        $this->loginStaff('support');
        $this->assertSame(403, $this->get('/admin/banners')['status']);
        $this->assertSame(403, $this->get('/admin/flash-sales')['status']);
        $this->post('/logout', [], '/admin');
        $this->loginStaff('manager');
        $this->assertSame(200, $this->get('/admin/banners')['status']);
        $r = $this->post('/admin/banners/new', ['title' => 'Back to school', 'link_url' => '/category/computing', 'is_active' => '1'], '/admin/banners/new');
        $this->assertSame(303, $r['status']);
        $this->assertStringContainsString('Back to school', $this->get('/')['body'], 'banner appears on the home page');
    }
}
