<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\Crypto;
use Waimart\Core\Settings;
use Waimart\Services\PayoutService;
use Waimart\Services\SellerLedgerService;

/** Phase 8 over HTTP: seller earnings page, payout requests by role, isolation, and the admin payout/commission desks. */
final class SellerFinanceHttpTest extends FeatureTestCase
{
    private int $seller;
    private int $owner;
    private string $ownerEmail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seller = $this->db->insert('sellers', ['name' => 'Kamba Crafts', 'slug' => 'kamba-crafts']);
        $this->ownerEmail = 'owner-' . bin2hex(random_bytes(3)) . '@example.com';
        $this->owner = $this->db->insert('users', ['email' => $this->ownerEmail, 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT), 'first_name' => 'O', 'last_name' => 'W']);
        $this->db->insert('seller_users', ['seller_id' => $this->seller, 'user_id' => $this->owner, 'role' => 'owner']);
        $this->db->insert('seller_payout_methods', ['seller_id' => $this->seller, 'method' => 'mpesa',
            'details_enc' => Crypto::encrypt('{"phone":"254712345678","name":"Kamba"}'), 'masked' => 'M-Pesa ••• 678']);
        // 3,000 payable: posted directly so the page tests do not depend on the order flow (covered in Integration).
        $l = new SellerLedgerService($this->db);
        $l->post($this->seller, 'release', 'available', '3000.00', 'test:seed:available');
        $l->post($this->seller, 'sale', 'pending', '450.00', 'test:seed:pending');
    }

    private function login(string $email): void
    {
        $this->post('/logout', [], '/');
        $this->post('/login', ['email' => $email, 'password' => 'Secret123'], '/login');
    }

    private function member(string $role, ?int $sellerId = null): void
    {
        $email = "$role-" . bin2hex(random_bytes(3)) . '@example.com';
        $u = $this->db->insert('users', ['email' => $email, 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT), 'first_name' => 'S', 'last_name' => 'M']);
        $this->db->insert('seller_users', ['seller_id' => $sellerId ?? $this->seller, 'user_id' => $u, 'role' => $role]);
        $this->login($email);
    }

    public function testOwnerSeesBalancesAndRequestsAndCancelsAPayout(): void
    {
        $this->login($this->ownerEmail);
        $page = $this->get('/seller/finance');
        $this->assertSame(200, $page['status']);
        foreach (['KSh 3,000', 'KSh 450', 'M-Pesa ••• 678', 'Request payout'] as $text) {
            $this->assertStringContainsString($text, $page['body']);
        }
        $this->assertStringNotContainsString('254712345678', $page['body'], 'full payout number never shown');
        $this->assertStringContainsString('KSh 3,000', $this->get('/seller')['body'], 'dashboard shows the payable balance');

        $bad = $this->post('/seller/finance/payouts', ['amount' => '3000.01'], '/seller/finance');
        $this->assertStringContainsString('at most KSh 3,000', html_entity_decode($this->follow($bad)['body']));
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM seller_payouts'));
        $r = $this->post('/seller/finance/payouts', ['amount' => '2500'], '/seller/finance');
        $this->assertSame(303, $r['status']);
        $id = (int) $this->db->value('SELECT id FROM seller_payouts WHERE seller_id = ?', [$this->seller]);
        $this->assertStringContainsString('Cancel request', $this->get('/seller/finance')['body']);
        $this->assertSame(303, $this->post("/seller/finance/payouts/$id/cancel", [], '/seller/finance')['status']);
        $this->assertSame('cancelled', $this->db->value('SELECT status FROM seller_payouts WHERE id = ?', [$id]));
        $this->assertSame('3000.00', (new SellerLedgerService($this->db))->balances($this->seller)['available']);
    }

    public function testOnlyOwnersMoveMoneyAndOtherSellersSeeNothing(): void
    {
        $p = (new PayoutService($this->db))->request($this->seller, $this->owner, '1000');
        foreach (['manager', 'staff'] as $role) {
            $this->member($role);
            $this->assertSame(200, $this->get('/seller/finance')['status'], "$role may view earnings");
            $this->assertStringContainsString('Only the store owner', $this->get('/seller/finance')['body']);
            $this->assertSame(403, $this->post('/seller/finance/payouts', ['amount' => '600'], '/seller/finance')['status'], "$role cannot request");
            $this->assertSame(403, $this->post("/seller/finance/payouts/{$p['id']}/cancel", [], '/seller/finance')['status'], "$role cannot cancel");
        }
        $other = $this->db->insert('sellers', ['name' => 'Other Shop', 'slug' => 'other-shop']);
        $this->member('owner', $other);
        $page = $this->get('/seller/finance')['body'];
        $this->assertStringNotContainsString($p['payout_number'], $page);
        $this->assertStringNotContainsString('KSh 3,000', $page);
        $this->assertSame(404, $this->post("/seller/finance/payouts/{$p['id']}/cancel", [], '/seller/finance')['status'], "another seller's payout does not exist for them");
        $this->assertSame('requested', $this->db->value('SELECT status FROM seller_payouts WHERE id = ?', [$p['id']]));
    }

    public function testSuspendedStoreCannotRequestPayouts(): void
    {
        $this->db->exec("UPDATE sellers SET status = 'suspended' WHERE id = ?", [$this->seller]);
        $this->login($this->ownerEmail);
        $this->assertSame(200, $this->get('/seller/finance')['status']);
        $this->assertSame(403, $this->post('/seller/finance/payouts', ['amount' => '600'], '/seller/finance')['status']);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM seller_payouts'));
    }

    public function testAdminPayoutDeskPermissionsAndFlow(): void
    {
        $p = (new PayoutService($this->db))->request($this->seller, $this->owner, '2000');
        $id = (int) $p['id'];

        $this->loginStaff('support');          // no payouts.* permission
        $this->assertSame(403, $this->get('/admin/payouts')['status']);
        $this->assertSame(403, $this->get("/admin/sellers/{$this->seller}/ledger")['status']);
        $this->post('/logout', [], '/');

        $this->loginStaff('manager');          // payouts.view only
        $this->assertStringContainsString($p['payout_number'], $this->get('/admin/payouts')['body']);
        $this->assertSame(200, $this->get("/admin/sellers/{$this->seller}/ledger")['status']);
        $this->assertStringNotContainsString('Approve for payment', $this->get("/admin/payouts/$id")['body']);
        $this->assertSame(403, $this->post("/admin/payouts/$id/approve", [], "/admin/payouts/$id")['status']);
        $this->assertSame(403, $this->get('/admin/commissions')['status'], 'commission rules are super-admin only');
        $this->post('/logout', [], '/');

        $this->loginStaff('finance');          // payouts.manage
        $this->assertSame(303, $this->post("/admin/payouts/$id/approve", ['note' => 'ok'], "/admin/payouts/$id")['status']);
        $page = $this->get("/admin/payouts/$id")['body'];
        $this->assertStringNotContainsString('254712345678', $page, 'destination hidden until asked for');
        $this->assertStringContainsString('254712345678', $this->get("/admin/payouts/$id?details=1")['body']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'payout.destination_viewed'"));
        $bad = $this->post("/admin/payouts/$id/paid", ['paid_method' => 'mpesa', 'paid_reference' => 'SKD81H2XQ1', 'paid_amount' => '1999'], "/admin/payouts/$id");
        $this->assertStringContainsString('must equal the approved payout', $this->follow($bad)['body']);
        $this->assertSame('approved', $this->db->value('SELECT status FROM seller_payouts WHERE id = ?', [$id]));
        $this->assertSame(303, $this->post("/admin/payouts/$id/paid", ['paid_method' => 'mpesa', 'paid_reference' => 'SKD81H2XQ1', 'paid_amount' => '2000.00'], "/admin/payouts/$id")['status']);
        $this->assertSame(['paid', 'SKD81H2XQ1'], array_values((array) $this->db->one('SELECT status, paid_reference FROM seller_payouts WHERE id = ?', [$id])));
        $csv = $this->get('/admin/payouts?status=paid&format=csv');
        $this->assertStringContainsString('text/csv', (string) ($csv['headers']['content-type'] ?? ''));
        $this->assertStringContainsString('SKD81H2XQ1', $csv['body']);
        $this->assertStringNotContainsString('254712345678', $csv['body']);

        $this->post('/logout', [], '/');
        $this->login($this->ownerEmail);
        $this->assertStringContainsString('SKD81H2XQ1', $this->get('/seller/finance')['body'], 'seller sees the payment reference');
        $this->assertSame(403, $this->get('/admin/payouts')['status'], 'sellers are not staff');
    }

    public function testSuperAdminChangesCommissionRates(): void
    {
        $this->loginStaff('super_admin');
        $page = $this->get('/admin/commissions');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('provisional', $page['body']);
        $bad = $this->post('/admin/commissions', ['scope' => 'default', 'rate' => '150'], '/admin/commissions');
        $this->assertStringContainsString('between 0 and 100', $this->follow($bad)['body']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM commission_rules WHERE scope = 'default'"));
        $this->assertSame(303, $this->post('/admin/commissions', ['scope' => 'seller', 'seller_id' => (string) $this->seller, 'rate' => '7.5', 'note' => 'Launch deal'], '/admin/commissions')['status']);
        $rule = (int) $this->db->value("SELECT id FROM commission_rules WHERE scope = 'seller' AND seller_id = ? AND is_active = 1", [$this->seller]);
        $this->assertSame('7.50', $this->db->value('SELECT rate FROM commission_rules WHERE id = ?', [$rule]));
        $this->assertStringContainsString('Seller: Kamba Crafts', $this->get('/admin/commissions')['body']);
        $this->assertSame(303, $this->post("/admin/commissions/$rule/retire", [], '/admin/commissions')['status']);
        $this->assertSame(0, (int) $this->db->value('SELECT is_active FROM commission_rules WHERE id = ?', [$rule]));
        $this->assertSame('1', Settings::get('marketplace_commission_provisional'), 'unchanged without confirmation');
    }
}
