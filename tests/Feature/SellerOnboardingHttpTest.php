<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\Settings;
use Waimart\Services\SellerDocumentStore;

/** Phase 3 over HTTP: applying, reviewing, Seller Centre access, isolation and tampering. */
final class SellerOnboardingHttpTest extends FeatureTestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->db->all('SELECT stored_name FROM seller_documents') as $d) {
            (new SellerDocumentStore())->delete((string) $d['stored_name']);
        }
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function customer(string $email): int
    {
        $id = $this->db->insert('users', ['email' => $email, 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT), 'first_name' => 'Juma', 'last_name' => 'Mwangi']);
        $this->post('/login', ['email' => $email, 'password' => 'Secret123'], '/login');
        return $id;
    }

    private function logout(): void
    {
        $this->post('/logout', [], '/');
    }

    /** A store with one member, created directly. @return array{seller:int,user:int} */
    private function store(string $slug, string $role = 'owner', string $status = 'active'): array
    {
        $seller = $this->db->insert('sellers', ['name' => ucwords(str_replace('-', ' ', $slug)), 'slug' => $slug, 'status' => $status, 'approved_at' => date('Y-m-d H:i:s')]);
        $user = $this->customer("$slug-$role@example.com");
        $this->db->insert('seller_users', ['seller_id' => $seller, 'user_id' => $user, 'role' => $role]);
        return ['seller' => $seller, 'user' => $user];
    }

    private function pdfFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wmpdf') . '.pdf';
        file_put_contents($path, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n");
        $this->tmp[] = $path;
        return $path;
    }

    /** @return array<string,mixed> */
    private function details(): array
    {
        $cat = (int) ($this->db->value('SELECT id FROM categories WHERE parent_id IS NULL LIMIT 1')
            ?: $this->db->insert('categories', ['name' => 'Home', 'slug' => 'home-' . bin2hex(random_bytes(2))]));
        return ['store_name' => 'Juma Electronics', 'contact_name' => 'Juma Mwangi', 'email' => 'juma@example.com', 'phone' => '0712345678',
                'business_type' => 'individual', 'business_description' => 'Phone accessories and small electronics, Nairobi CBD.',
                'county_id' => '47', 'town' => 'Nairobi', 'category_ids' => [(string) $cat]];
    }

    public function testLandingIsPublicLinkedFromHeaderAndApplyingNeedsSignIn(): void
    {
        $home = $this->get('/')['body'];
        $this->assertStringContainsString('/sell"', $home, '"Sell on Basemall" link in the header/footer');
        $landing = $this->get('/sell');
        $this->assertSame(200, $landing['status']);
        $this->assertStringContainsString('Sell on Basemall', $landing['body']);
        $this->assertSame(200, $this->get('/sell/terms')['status']);
        $r = $this->get('/sell/apply');
        $this->assertSame(303, $r['status']);
        $this->assertStringEndsWith('/login', $r['headers']['location']);
    }

    public function testApplicantToApprovedSellerEndToEndIgnoringTamperedFields(): void
    {
        $victim = $this->db->insert('users', ['email' => 'victim@example.com', 'password_hash' => 'x', 'first_name' => 'V', 'last_name' => 'X']);
        $me = $this->customer('juma@example.com');
        $r = $this->post('/sell/apply', $this->details() + ['user_id' => (string) $victim, 'status' => 'approved', 'seller_id' => '1', 'is_staff' => '1'], '/sell/apply');
        $this->assertSame(303, $r['status'], $this->lastBody);
        $app = (array) $this->db->one('SELECT * FROM seller_applications ORDER BY id DESC LIMIT 1');
        $this->assertSame([$me, 'draft', null], [(int) $app['user_id'], $app['status'], $app['seller_id']], 'tampered fields ignored');

        $up = $this->post('/sell/application/documents', ['doc_type' => 'national_id'], '/sell/application', ['document' => $this->pdfFile()]);
        $this->assertSame(303, $up['status']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM seller_documents WHERE application_id = ?', [$app['id']]));

        $this->post('/sell/application/submit', ['payout_method' => 'mpesa', 'payout_phone' => '0722111222', 'payout_name' => 'Juma Mwangi', 'accept_terms' => '1'], '/sell/application');
        $this->assertSame('submitted', $this->db->value('SELECT status FROM seller_applications WHERE id = ?', [$app['id']]));
        $status = $this->get('/sell/application')['body'];
        $this->assertStringContainsString('waiting for review', $status);
        $this->assertStringNotContainsString('722111222', $status, 'payout number never echoed back');
        $this->assertSame(303, $this->get('/seller')['status'], 'not a seller until approved');

        $this->logout();
        $this->loginStaff('manager');
        $this->assertStringContainsString('Juma Electronics', $this->get('/admin/sellers/applications')['body']);
        $r = $this->post('/admin/sellers/applications/' . $app['id'] . '/approve', [], '/admin/sellers/applications/' . $app['id']);
        $this->assertSame(303, $r['status'], $this->lastBody);
        $sellerId = (int) $this->db->value('SELECT seller_id FROM seller_applications WHERE id = ?', [$app['id']]);
        $this->assertGreaterThan(1, $sellerId);

        $this->logout();
        $this->post('/login', ['email' => 'juma@example.com', 'password' => 'Secret123'], '/login');
        $centre = $this->get('/seller');
        $this->assertSame(200, $centre['status']);
        $this->assertStringContainsString('Juma Electronics', $centre['body']);
        $this->assertStringContainsString('Seller Centre', $this->get('/')['body'], 'header links to the Seller Centre');
        $this->assertSame(403, $this->get('/admin')['status'], 'a seller is not staff');
    }

    public function testApplicantsCannotReadEachOthersDocuments(): void
    {
        $this->customer('a@example.com');
        $this->post('/sell/apply', $this->details(), '/sell/apply');
        $this->post('/sell/application/documents', ['doc_type' => 'national_id'], '/sell/application', ['document' => $this->pdfFile()]);
        $docId = (int) $this->db->value('SELECT id FROM seller_documents ORDER BY id DESC LIMIT 1');

        $own = $this->get("/sell/application/documents/$docId");
        $this->assertSame(200, $own['status']);
        $this->assertStringStartsWith('attachment;', $own['headers']['content-disposition'] ?? '');
        $this->assertSame('nosniff', $own['headers']['x-content-type-options'] ?? null);
        $this->assertStringStartsWith('%PDF-', $own['body']);

        $this->logout();
        $this->customer('b@example.com');
        $this->assertSame(404, $this->get("/sell/application/documents/$docId")['status']);
        $del = $this->post("/sell/application/documents/$docId/delete", [], '/sell');
        $this->assertNotSame(200, $del['status']);
        $this->assertStringNotContainsString('Document removed', $this->follow($del)['body']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM seller_documents WHERE id = ?', [$docId]), 'still there');
        $this->assertSame(403, $this->get("/admin/sellers/documents/$docId")['status'], 'customers cannot use the staff route');
    }

    public function testStaffPermissionsOnSellerScreensAndAuditedDocumentAccess(): void
    {
        $this->customer('c@example.com');
        $this->post('/sell/apply', $this->details(), '/sell/apply');
        $this->post('/sell/application/documents', ['doc_type' => 'national_id'], '/sell/application', ['document' => $this->pdfFile()]);
        $this->post('/sell/application/submit', ['payout_method' => 'mpesa', 'payout_phone' => '0722111222', 'payout_name' => 'C', 'accept_terms' => '1'], '/sell/application');
        $appId = (int) $this->db->value('SELECT id FROM seller_applications ORDER BY id DESC LIMIT 1');
        $docId = (int) $this->db->value('SELECT id FROM seller_documents ORDER BY id DESC LIMIT 1');
        $this->logout();

        $this->loginStaff('inventory_clerk');
        $this->assertSame(403, $this->get('/admin/sellers/applications')['status']);
        $this->logout();

        $this->loginStaff('finance');   // sellers.view but not sellers.manage
        $this->assertSame(200, $this->get("/admin/sellers/applications/$appId")['status']);
        $this->assertSame(200, $this->get("/admin/sellers/documents/$docId")['status']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'seller_document.downloaded' AND entity_id = ?", [$docId]));
        $this->assertSame(403, $this->post("/admin/sellers/applications/$appId/approve", [], "/admin/sellers/applications/$appId")['status']);
        $this->assertSame('submitted', $this->db->value('SELECT status FROM seller_applications WHERE id = ?', [$appId]));
    }

    public function testSuspendedStoreIsReadOnlyAndReactivationRestoresEditing(): void
    {
        ['seller' => $s] = $this->store('nyeri-coffee');
        $ok = $this->post('/seller/profile', ['description' => 'Single-origin coffee from Nyeri.'], '/seller/profile');
        $this->assertSame(303, $ok['status']);
        $this->db->exec("UPDATE sellers SET status = 'suspended', suspension_reason = 'Quality complaints' WHERE id = ?", [$s]);

        $page = $this->get('/seller');
        $this->assertSame(200, $page['status'], 'records remain viewable');
        $this->assertStringContainsString('Your store is suspended', $page['body']);
        $this->assertSame(403, $this->post('/seller/profile', ['description' => 'Changed while suspended'], '/seller/profile')['status']);
        $this->assertSame('Single-origin coffee from Nyeri.', $this->db->value('SELECT description FROM sellers WHERE id = ?', [$s]));

        $this->db->exec("UPDATE sellers SET status = 'active' WHERE id = ?", [$s]);
        $this->assertSame(303, $this->post('/seller/profile', ['description' => 'Back in business.'], '/seller/profile')['status']);
    }

    public function testSellerRolesAndNoWayToTargetAnotherStore(): void
    {
        ['seller' => $other] = $this->store('other-shop');
        $this->logout();
        ['seller' => $mine] = $this->store('my-shop', 'staff');
        $this->assertSame(200, $this->get('/seller/profile')['status'], 'staff members can view');
        $this->assertSame(403, $this->post('/seller/profile', ['description' => 'x'], '/seller/profile')['status'], 'but not edit');
        $this->db->exec("UPDATE seller_users SET role = 'owner' WHERE seller_id = ?", [$mine]);

        $this->post('/seller/profile', ['description' => 'Mine', 'seller_id' => (string) $other, 'id' => (string) $other, 'slug' => 'hijack', 'status' => 'active', 'is_verified' => '1'], '/seller/profile');
        $this->assertSame('Mine', $this->db->value('SELECT description FROM sellers WHERE id = ?', [$mine]));
        $this->assertNull($this->db->value('SELECT description FROM sellers WHERE id = ?', [$other]), 'other store untouched');
        $this->assertSame(['my-shop', '0'], array_values(array_map('strval', (array) $this->db->one('SELECT slug, is_verified FROM sellers WHERE id = ?', [$mine]))), 'slug/verification not seller-editable');
    }

    public function testNonSellersAreSentToTheSellPage(): void
    {
        $this->customer('shopper@example.com');
        $r = $this->get('/seller');
        $this->assertSame(303, $r['status']);
        $this->assertStringEndsWith('/sell', $r['headers']['location']);
        $this->logout();
        $r = $this->get('/seller');
        $this->assertSame(303, $r['status']);
        $this->assertStringEndsWith('/login', $r['headers']['location']);
    }

    public function testAdministratorConfiguresRequiredDocuments(): void
    {
        $this->loginStaff('super_admin');
        $values = array_column($this->db->all('SELECT setting_key, setting_value FROM settings'), 'setting_value', 'setting_key');
        $values['doc_mode'] = ['national_id' => 'individual', 'business_registration' => 'business', 'tax_registration' => 'optional', 'other' => 'off'];
        $r = $this->post('/admin/settings', $values, '/admin/settings');
        $this->assertSame(303, $r['status'], $this->lastBody);
        Settings::flush();
        $this->assertSame(['individual' => ['national_id'], 'registered_business' => ['business_registration'], 'optional' => ['tax_registration']],
            json_decode((string) Settings::get('seller_required_documents'), true));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.updated' AND after_json LIKE '%seller_required_documents%'"));
    }
}
