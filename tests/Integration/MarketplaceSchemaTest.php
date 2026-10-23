<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Services\Sellers;

/** Database-level safeguards of the marketplace schema (migrations 010–013). */
final class MarketplaceSchemaTest extends DbTestCase
{
    private function seller(string $slug = 'acme-shop'): int
    {
        return $this->db->insert('sellers', ['name' => ucwords(str_replace('-', ' ', $slug)), 'slug' => $slug]);
    }

    private function user(string $email): int
    {
        return $this->db->insert('users', ['email' => $email, 'password_hash' => 'x', 'first_name' => 'A', 'last_name' => 'B']);
    }

    public function testMarketplaceTablesExist(): void
    {
        $tables = array_column($this->db->all('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()'), 't');
        foreach (['sellers', 'seller_users', 'seller_applications', 'seller_application_history', 'seller_documents',
                  'seller_payout_methods', 'seller_orders', 'seller_order_history', 'commission_rules',
                  'seller_ledger_entries', 'seller_payouts', 'seller_payout_history', 'seller_disputes', 'seller_dispute_messages'] as $t) {
            $this->assertContains($t, $tables);
        }
    }

    public function testOfficialStoreExistsExactlyOnceAndOwnsAdminProducts(): void
    {
        $platform = Sellers::platformId($this->db);
        $this->assertSame('official-store', $this->db->value('SELECT slug FROM sellers WHERE id = ?', [$platform]));
        $this->assertDbRejects(fn () => $this->db->insert('sellers', ['name' => 'Second Platform', 'slug' => 'second', 'is_platform' => 1]), 'Duplicate');
        $v = $this->makeVariant(3);
        $this->assertSame($platform, (int) $this->db->value('SELECT seller_id FROM products WHERE id = ?', [$v['product_id']]));
        $this->assertSame('published', $this->db->value('SELECT moderation_status FROM products WHERE id = ?', [$v['product_id']]));
    }

    public function testStoreSlugsAreValidatedAndUnique(): void
    {
        $this->seller('acme-shop');
        $this->assertDbRejects(fn () => $this->seller('acme-shop'), 'Duplicate');
        $this->assertDbRejects(fn () => $this->db->insert('sellers', ['name' => 'Bad Slug', 'slug' => 'Bad Slug!']), 'ck_seller_slug');
        $this->assertDbRejects(fn () => $this->db->insert('sellers', ['name' => 'Dash', 'slug' => '-dash-']), 'ck_seller_slug');
    }

    public function testEveryProductAndOrderItemNeedsAnExistingSeller(): void
    {
        $cat = $this->db->insert('categories', ['name' => 'C', 'slug' => 'c-' . bin2hex(random_bytes(3))]);
        $this->assertDbRejects(fn () => $this->db->insert('products', ['category_id' => $cat, 'name' => 'Orphan', 'slug' => 'orphan']), 'seller_id');
        $this->assertDbRejects(fn () => $this->db->insert('products', ['seller_id' => 999, 'category_id' => $cat, 'name' => 'Ghost', 'slug' => 'ghost']), 'fk_prod_seller');
        $v = $this->makeVariant(3);
        $o = $this->makeOrder($v['variant_id']);
        $this->assertSame(Sellers::platformId($this->db), (int) $this->db->value('SELECT seller_id FROM order_items WHERE id = ?', [$o['item_id']]));
    }

    public function testUserActsForAtMostOneSellerAndOneOpenApplication(): void
    {
        $u = $this->user('s@example.com');
        $a = $this->seller('shop-a');
        $b = $this->seller('shop-b');
        $this->db->insert('seller_users', ['seller_id' => $a, 'user_id' => $u]);
        $this->assertDbRejects(fn () => $this->db->insert('seller_users', ['seller_id' => $b, 'user_id' => $u]), 'Duplicate');

        $app = ['user_id' => $u, 'status' => 'submitted', 'store_name' => 'X', 'store_slug' => 'x', 'contact_name' => 'A B',
                'email' => 's@example.com', 'phone' => '254712345678', 'business_type' => 'individual',
                'business_description' => 'Shoes', 'county_id' => 47, 'town' => 'CBD'];
        $first = $this->db->insert('seller_applications', $app + ['application_number' => 'SA-1']);
        $this->assertDbRejects(fn () => $this->db->insert('seller_applications', $app + ['application_number' => 'SA-2']), 'Duplicate');
        $this->db->exec("UPDATE seller_applications SET status = 'rejected' WHERE id = ?", [$first]);
        $this->assertGreaterThan(0, $this->db->insert('seller_applications', $app + ['application_number' => 'SA-3']), 'a new application is allowed once the old one is closed');
    }

    public function testCommissionRulesHaveOneActiveRatePerTargetAndValidScope(): void
    {
        $this->assertSame('10.00', $this->db->value("SELECT rate FROM commission_rules WHERE scope = 'default' AND is_active = 1"));
        $this->assertDbRejects(fn () => $this->db->insert('commission_rules', ['scope' => 'default', 'rate' => '12']), 'Duplicate');
        $this->assertDbRejects(fn () => $this->db->insert('commission_rules', ['scope' => 'seller', 'rate' => '5']), 'ck_cr_scope');
        $this->assertDbRejects(fn () => $this->db->insert('commission_rules', ['scope' => 'seller', 'seller_id' => $this->seller(), 'rate' => '101']), 'ck_cr_rate');
        $this->db->exec("UPDATE commission_rules SET is_active = 0 WHERE scope = 'default'");
        $this->assertGreaterThan(0, $this->db->insert('commission_rules', ['scope' => 'default', 'rate' => '12']), 'history kept: old rule deactivated, new one added');
    }

    public function testOneSellerOrderPerSellerPerOrder(): void
    {
        $v = $this->makeVariant(3);
        $o = $this->makeOrder($v['variant_id']);
        // makeOrder already created the Official Store's seller order for this order.
        $row = ['order_id' => $o['order_id'], 'seller_id' => Sellers::platformId($this->db), 'items_subtotal' => '1000'];
        $this->assertDbRejects(fn () => $this->db->insert('seller_orders', $row + ['sub_number' => 'WM-X-2']), 'Duplicate');
        $this->assertDbRejects(fn () => $this->db->exec('UPDATE order_items SET seller_order_id = NULL WHERE id = ?', [$o['item_id']]), 'seller_order_id');
    }

    public function testLedgerPostingsAreExactlyOnceAndNonZero(): void
    {
        $s = $this->seller();
        $entry = ['seller_id' => $s, 'entry_type' => 'sale', 'bucket' => 'pending', 'amount' => '900.00', 'posting_key' => 'sale:so:1'];
        $this->db->insert('seller_ledger_entries', $entry);
        $this->assertDbRejects(fn () => $this->db->insert('seller_ledger_entries', $entry), 'Duplicate');
        $this->assertDbRejects(fn () => $this->db->insert('seller_ledger_entries', ['amount' => '0.00', 'posting_key' => 'zero'] + $entry), 'ck_sle_amount');
    }

    public function testPayoutsOneOpenPerSellerAndPaidNeedsReferenceAndExactAmount(): void
    {
        $s = $this->seller();
        $p = $this->db->insert('seller_payouts', ['payout_number' => 'PO-1', 'seller_id' => $s, 'amount' => '1500.00']);
        $this->assertDbRejects(fn () => $this->db->insert('seller_payouts', ['payout_number' => 'PO-2', 'seller_id' => $s, 'amount' => '100.00']), 'Duplicate');
        $this->assertDbRejects(fn () => $this->db->insert('seller_payouts', ['payout_number' => 'PO-3', 'seller_id' => $s, 'amount' => '0']), 'ck_sp_amount');
        $this->assertDbRejects(fn () => $this->db->exec("UPDATE seller_payouts SET status = 'paid', paid_amount = 1500, paid_at = NOW() WHERE id = ?", [$p]), 'ck_sp_paid');
        $this->assertDbRejects(fn () => $this->db->exec("UPDATE seller_payouts SET status = 'paid', paid_amount = 1400, paid_reference = 'QAB1', paid_at = NOW() WHERE id = ?", [$p]), 'ck_sp_paid');
        $this->db->exec("UPDATE seller_payouts SET status = 'paid', paid_amount = 1500, paid_method = 'mpesa', paid_reference = 'QAB1', paid_at = NOW() WHERE id = ?", [$p]);
        $this->db->insert('seller_payouts', ['payout_number' => 'PO-4', 'seller_id' => $s, 'amount' => '200.00']);
        $q = (int) $this->db->value("SELECT id FROM seller_payouts WHERE payout_number = 'PO-4'");
        $this->assertDbRejects(fn () => $this->db->exec("UPDATE seller_payouts SET status = 'paid', paid_amount = 200, paid_method = 'mpesa', paid_reference = 'QAB1', paid_at = NOW() WHERE id = ?", [$q]), 'Duplicate');
    }

    public function testOneActivePayoutMethodAndOneOpenDisputePerSellerOrder(): void
    {
        $s = $this->seller();
        $m = ['seller_id' => $s, 'method' => 'mpesa', 'details_enc' => 'enc', 'masked' => '07•• ••• 678'];
        $this->db->insert('seller_payout_methods', $m);
        $this->assertDbRejects(fn () => $this->db->insert('seller_payout_methods', $m), 'Duplicate');

        $v = $this->makeVariant(3);
        $o = $this->makeOrder($v['variant_id']);
        $so = $this->db->insert('seller_orders', ['order_id' => $o['order_id'], 'seller_id' => $s, 'sub_number' => 'WM-D-1', 'items_subtotal' => '1000']);
        $d = ['order_id' => $o['order_id'], 'seller_order_id' => $so, 'raised_by_role' => 'customer', 'reason' => 'not_received'];
        $this->db->insert('seller_disputes', $d + ['dispute_number' => 'DS-1']);
        $this->assertDbRejects(fn () => $this->db->insert('seller_disputes', $d + ['dispute_number' => 'DS-2']), 'Duplicate');
    }
}
