<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

final class AdminCatalogueTest extends FeatureTestCase
{
    public function testGuestsAreSentToStaffLogin(): void
    {
        $r = $this->get('/admin/products');
        $this->assertSame(303, $r['status']);
        $this->assertStringEndsWith('/admin/login', $r['headers']['location']);
    }

    public function testCustomersAreForbidden(): void
    {
        $this->db->insert('users', ['email' => 'cust@x.co', 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT), 'first_name' => 'C', 'last_name' => 'U']);
        $r = $this->post('/admin/login', ['email' => 'cust@x.co', 'password' => 'Secret123'], '/admin/login');
        $this->assertStringContainsString('Incorrect email or password', $this->follow($r)['body'], 'customers cannot use the staff login');
        $this->assertSame(303, $this->get('/admin')['status']);
    }

    public function testRolePermissionsAreEnforced(): void
    {
        $this->loginStaff('support');
        $this->assertSame(200, $this->get('/admin')['status']);
        $this->assertSame(403, $this->get('/admin/products')['status']);
        $this->assertSame(403, $this->get('/admin/inventory')['status']);
        $this->assertSame(403, $this->post('/admin/categories', ['name' => 'Hack'], '/admin')['status']);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM categories'));
    }

    public function testFulfilmentStaffCanViewButNotAdjustInventory(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $this->loginStaff('fulfilment_staff');
        $page = $this->get("/admin/inventory/$v");
        $this->assertSame(200, $page['status']);
        $this->assertStringNotContainsString('Receive stock', $page['body']);
        $this->assertSame(403, $this->post("/admin/inventory/$v/receive", ['quantity' => '5', 'reason' => 'purchase'], "/admin/inventory/$v")['status']);
        $this->assertSame(5, (int) $this->db->value('SELECT on_hand FROM inventory WHERE variant_id = ?', [$v]));
    }

    public function testWrongPasswordIsGenericAndLocksOutAfterFiveAttempts(): void
    {
        $u = $this->makeStaff('manager');
        for ($i = 0; $i < 5; $i++) {
            $r = $this->post('/admin/login', ['email' => $u['email'], 'password' => 'wrong'], '/admin/login');
            $this->assertStringContainsString('Incorrect email or password', $this->follow($r)['body']);
        }
        // Even the right password is refused while locked out.
        $r = $this->post('/admin/login', ['email' => $u['email'], 'password' => $u['password']], '/admin/login');
        $this->assertStringContainsString('Too many sign-in attempts', $this->follow($r)['body']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) >= 5 FROM audit_logs WHERE action = 'auth.staff_login_failed'"));
    }

    public function testCsrfTokenRequiredForAdminPosts(): void
    {
        $this->loginStaff('manager');
        $r = $this->http('POST', '/admin/categories', ['name' => 'NoToken', '_csrf' => 'forged']);
        $this->assertSame(419, $r['status']);
        $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM categories WHERE name = 'NoToken'"));
    }

    public function testInventoryClerkCreatesProductThatAppearsInStore(): void
    {
        $this->loginStaff('inventory_clerk');
        $cat = $this->db->insert('categories', ['name' => 'Phones', 'slug' => 'phones']);
        $r = $this->post('/admin/products', [
            '_form' => 'new', 'name' => 'Feature Test Phone <b>X</b>', 'category_id' => (string) $cat, 'max_per_order' => '3',
            'is_active' => '1', 'sku' => 'FTP-1', 'price' => '12500', 'compare_at_price' => '14000', 'opening_stock' => '20',
        ], '/admin/products/new');
        $this->assertSame(303, $r['status']);
        $this->assertMatchesRegularExpression('#/admin/products/\d+$#', $r['headers']['location']);

        $page = $this->get('/product/feature-test-phone-b-x-b');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Feature Test Phone &lt;b&gt;X&lt;/b&gt;', $page['body'], 'name is escaped');
        $this->assertStringContainsString('KSh 12,500', $page['body']);
        $this->assertStringContainsString('-10%', $page['body']);
        $this->assertStringContainsString('In stock', $page['body']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'catalogue.product_created'"));

        // At or below the low-stock threshold (default 5) the page warns instead.
        $this->db->exec('UPDATE inventory SET reserved = 16');
        $this->assertStringContainsString('Only 4 left', $this->get('/product/feature-test-phone-b-x-b')['body']);
    }

    public function testValidationErrorsKeepInputAndShowMessages(): void
    {
        $this->loginStaff('manager');
        $cat = $this->db->insert('categories', ['name' => 'Phones', 'slug' => 'phones']);
        $r = $this->post('/admin/products', [
            '_form' => 'new', 'name' => 'Keep My Name', 'category_id' => (string) $cat, 'max_per_order' => '3',
            'sku' => 'K-1', 'price' => '100', 'compare_at_price' => '50',
        ], '/admin/products/new');
        $this->assertSame(303, $r['status']);
        $page = $this->follow($r)['body'];
        $this->assertStringContainsString('must be higher than the selling price', $page);
        $this->assertStringContainsString('value="Keep My Name"', $page);
    }

    public function testAdjustBelowReservedIsRejectedInUi(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $this->db->exec('UPDATE inventory SET reserved = 4 WHERE variant_id = ?', [$v]);
        $this->loginStaff('inventory_clerk');
        $r = $this->post("/admin/inventory/$v/adjust", ['_form' => 'adjust', 'delta' => '-3', 'reason' => 'damaged'], "/admin/inventory/$v");
        $this->assertStringContainsString('4 unit(s) reserved', $this->follow($r)['body']);
        $this->assertSame(5, (int) $this->db->value('SELECT on_hand FROM inventory WHERE variant_id = ?', [$v]));

        $r = $this->post("/admin/inventory/$v/receive", ['_form' => 'receive', 'quantity' => '10', 'reason' => 'purchase', 'note' => 'PO-77'], "/admin/inventory/$v");
        $this->assertStringContainsString('On hand is now 15', $this->follow($r)['body']);
    }

    public function testStorefrontHidesInactiveProducts(): void
    {
        $v = $this->makeVariant(5);
        $slug = $this->db->value('SELECT slug FROM products WHERE id = ?', [$v['product_id']]);
        $this->assertSame(200, $this->get("/product/$slug")['status']);
        $this->db->exec('UPDATE products SET is_active = 0 WHERE id = ?', [$v['product_id']]);
        $this->assertSame(404, $this->get("/product/$slug")['status']);
    }
}
