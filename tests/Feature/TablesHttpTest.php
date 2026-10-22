<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

/**
 * The shared table standard on every list: 10 rows by default, a 15-row option, totals, page links, server-side
 * search and filters, safe handling of odd input, out-of-range pages, and data isolation for sellers and customers.
 */
final class TablesHttpTest extends FeatureTestCase
{
    /** Admin lists converted to the standard (path => accessible search label). */
    private const ADMIN = [
        '/admin/orders' => 'Search orders', '/admin/seller-orders' => 'Search seller orders', '/admin/customers' => 'Search customers',
        '/admin/collections' => 'Find an order to hand over', '/admin/returns' => 'Search returns', '/admin/notifications' => 'Search notifications',
        '/admin/payments' => 'Search payments', '/admin/payments?tab=decided' => 'Search payments', '/admin/cod' => 'Search orders with cash to record',
        '/admin/refunds' => null, '/admin/payouts' => 'Search payouts', '/admin/commissions' => 'Search sellers in the report',
        '/admin/reports/marketplace' => 'Search sellers in the report', '/admin/sellers' => 'Search sellers', '/admin/sellers/applications' => 'Search seller applications',
        '/admin/moderation/products' => 'Search products to approve', '/admin/disputes' => 'Search seller cases', '/admin/delivery-zones' => null,
        '/admin/pickup-points' => 'Search pickup points', '/admin/pickup-points/applications' => 'Search applications', '/admin/products' => 'Search products',
        '/admin/categories' => 'Search categories', '/admin/brands' => 'Search brands', '/admin/inventory' => 'Search stock', '/admin/banners' => 'Search banners',
        '/admin/flash-sales' => 'Search flash sales', '/admin/content/pages' => 'Search pages', '/admin/staff' => 'Search staff', '/admin/audit' => 'Search the audit log',
        '/admin/audit/sign-ins' => 'Search sign-in activity', '/admin/messaging' => 'Search the message log', '/admin/marketing/campaigns' => 'Search campaigns',
        '/admin/marketing/leads' => 'Search leads', '/admin/marketing/consent' => 'Search consent changes', '/admin/marketing/abandoned-carts' => 'Search abandoned carts',
        '/admin/marketing/abandoned-carts?view=log' => 'Search reminders',
    ];

    public function testEveryAdminListUsesTheStandardAndHandlesOddInputSafely(): void
    {
        $this->loginStaff('super_admin');
        foreach (self::ADMIN as $path => $label) {
            $sep = str_contains($path, '?') ? '&' : '?';
            $r = $this->get($path);
            $this->assertSame(200, $r['status'], "$path: " . substr(strip_tags($r['body']), 0, 200));
            if ($label === null) {
                continue;                       // bounded lists (documented in the table audit)
            }
            $this->assertStringContainsString('aria-label="' . $label . '"', $r['body'], "$path search");
            $this->assertStringContainsString('data-table-region', $r['body'], "$path region");
            $this->assertMatchesRegularExpression('/wm-table-count[^>]*>(Showing \d|No )/', $r['body'], "$path count");
            // Hostile and odd input: quotes, SQL wildcards, markup, very long text, bad page/size/sort values.
            $odd = $this->get($path . $sep . 'q=' . rawurlencode("o'Brien %_\" <b>x</b>" . str_repeat('z', 300)) . '&per=999&page=-4&sort=1;DROP&dir=sideways');
            $this->assertSame(200, $odd['status'], "$path with odd input");
            $this->assertStringNotContainsString('<b>x</b>', $odd['body'], "$path escapes the search term");
            $this->assertSame(200, $this->get($path . $sep . 'per=15&page=99999')['status'], "$path out-of-range page");
        }
    }

    public function testPagingSearchSortAndFiltersWorkTogether(): void
    {
        for ($i = 1; $i <= 23; $i++) {
            $this->db->insert('brands', ['name' => sprintf('Zeta %02d', $i), 'slug' => sprintf('zeta-%02d', $i), 'is_active' => $i % 4 === 0 ? 0 : 1]);
        }
        $this->loginStaff('super_admin');
        $base = (int) $this->db->value('SELECT COUNT(*) FROM brands');
        $page1 = $this->get('/admin/brands')['body'];
        $this->assertStringContainsString("Showing 1–10 of $base brands", $page1, '10 rows by default');
        $this->assertSame(10, substr_count($page1, '/edit">Edit<'), 'only one page of rows is rendered');
        $this->assertStringContainsString("Showing 1–15 of $base", $this->get('/admin/brands?per=15')['body'], '15 rows');
        $this->assertStringContainsString('Showing 1–23 of 23 brands', $this->get('/admin/brands?q=zeta&per=25')['body'], 'search updates the total');
        $this->assertStringContainsString('Showing 21–23 of 23', $this->get('/admin/brands?q=zeta&page=3')['body']);
        $this->assertStringContainsString('Showing 1–5 of 5', $this->get('/admin/brands?q=zeta&status=hidden')['body'], 'search + filter');
        $desc = $this->get('/admin/brands?q=zeta&sort=name&dir=desc')['body'];
        $this->assertLessThan(strpos($desc, 'Zeta 22'), strpos($desc, 'Zeta 23'), 'sorted descending');
        $this->assertStringContainsString('aria-sort="descending"', $desc);
        $this->assertStringContainsString('q=zeta&amp;sort=name&amp;dir=desc&amp;page=2', $desc, 'page links keep search and sort');
        $this->assertStringContainsString('No matching records found', $this->get('/admin/brands?q=nothing-like-this')['body']);
        $last = $this->get('/admin/brands?q=zeta&page=50')['body'];
        $this->assertStringContainsString('Showing 21–23 of 23', $last, 'an out-of-range page shows the last page');
    }

    public function testSellerAndCustomerListsOnlyShowTheirOwnRecords(): void
    {
        // Two customers with orders; each sees only their own, also when searching for the other's order number.
        $mine = $this->db->insert('users', ['email' => 'mine@example.com', 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT), 'first_name' => 'Mine', 'last_name' => 'A']);
        $other = $this->db->insert('users', ['email' => 'other@example.com', 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT), 'first_name' => 'Other', 'last_name' => 'B']);
        $v = $this->makeVariant(20, '500.00')['variant_id'];
        for ($i = 0; $i < 12; $i++) {
            $this->makeOrder($v, 1, ['user_id' => $mine, 'placed_at' => date('Y-m-d H:i:s', time() - $i * 60)]);
        }
        $theirs = $this->makeOrder($v, 1, ['user_id' => $other]);
        $theirNumber = (string) $this->db->value('SELECT order_number FROM orders WHERE id = ?', [$theirs['order_id']]);
        $this->post('/login', ['email' => 'mine@example.com', 'password' => 'Secret123'], '/login');
        $list = $this->get('/account/orders')['body'];
        $this->assertStringContainsString('Showing 1–10 of 12 orders', $list);
        $this->assertStringNotContainsString($theirNumber, $list);
        $this->assertStringContainsString('No matching orders found', $this->get('/account/orders?q=' . rawurlencode($theirNumber))['body'], "another customer's order is never found");
        $this->assertStringContainsString('Showing 11–12 of 12', $this->get('/account/orders?page=2')['body']);
    }
}
