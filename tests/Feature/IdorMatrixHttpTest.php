<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\Crypto;
use Waimart\Core\Router;
use Waimart\Core\Settings;
use Waimart\Services\CartService;
use Waimart\Services\DisputeService;
use Waimart\Services\OrderService;
use Waimart\Services\OrderWorkflow;
use Waimart\Services\PayoutService;
use Waimart\Services\SellerLedgerService;

/**
 * Phase 10 IDOR matrix. Every route that takes an id is taken from the live route table (so a route added later is
 * covered automatically, or the test fails until it is mapped), filled with ANOTHER party's real ids, and requested
 * with GET and POST. Every request must be a 404, and the other party's data must be byte-for-byte unchanged.
 */
final class IdorMatrixHttpTest extends FeatureTestCase
{
    private int $sellerA;
    private int $ownerA;
    /** @var array<string,int> */
    private array $ids = [];
    private string $orderNumber = '';

    protected function setUp(): void
    {
        parent::setUp();
        $zone = $this->db->insert('delivery_zones', ['name' => 'City', 'fee' => '250', 'min_days' => 1, 'max_days' => 2, 'cod_allowed' => 1]);
        $this->db->insert('delivery_zone_locations', ['zone_id' => $zone, 'county_id' => 47, 'town' => '']);
        Settings::set('mpesa_till_number', '123456');
        Settings::set('payout_min_amount', '500');

        // Seller A with a product, variant, image, a confirmed order part, a case and a payout request.
        $this->sellerA = $this->db->insert('sellers', ['name' => 'Victim Store', 'slug' => 'victim-store']);
        $this->ownerA = $this->user('victim-owner');
        $this->db->insert('seller_users', ['seller_id' => $this->sellerA, 'user_id' => $this->ownerA, 'role' => 'owner']);
        $this->db->insert('seller_payout_methods', ['seller_id' => $this->sellerA, 'method' => 'mpesa', 'details_enc' => Crypto::encrypt('{"phone":"254711111111"}'), 'masked' => 'M-Pesa ••• 111']);
        $mk = $this->makeVariant(5, '2000.00', null, 'Default', $this->sellerA);
        $this->ids['product'] = $mk['product_id'];
        $this->ids['variant'] = $mk['variant_id'];
        $this->ids['image'] = $this->db->insert('product_images', ['product_id' => $mk['product_id'], 'path' => 'uploads/products/idor-test.jpg', 'sort_order' => 0]);

        $customer = $this->user('victim-customer');
        $cart = new CartService(null, null, $this->db);
        $cart->add($mk['variant_id'], 1);
        $o = (new OrderService($this->db))->place($cart, ['customer_name' => 'Victim Customer', 'customer_phone' => '0711222333', 'customer_email' => 'victim@example.com',
            'fulfilment_method' => 'delivery', 'county_id' => '47', 'town' => 'Kilimani', 'location' => 'Private Lane 1', 'payment_method' => 'mpesa_till'],
            (array) $this->db->one('SELECT * FROM users WHERE id = ?', [$customer]), Crypto::token(32), '127.0.0.1')['order'];
        $this->orderNumber = (string) $o['order_number'];
        $this->db->exec("UPDATE orders SET payment_status = 'verified' WHERE id = ?", [$o['id']]);
        (new OrderWorkflow($this->db))->transition((int) $o['id'], 'confirmed', ['type' => 'system'], 'Paid');
        $this->ids['part'] = (int) $this->db->value('SELECT id FROM seller_orders WHERE order_id = ?', [$o['id']]);
        $this->ids['dispute'] = (int) (new DisputeService($this->db))->open($this->ids['part'], 'staff', null, 'other', 'Victim case used by the IDOR matrix.')['id'];
        (new SellerLedgerService($this->db))->post($this->sellerA, 'release', 'available', '1000.00', 'idor:seed');
        $this->ids['payout'] = (int) (new PayoutService($this->db))->request($this->sellerA, $this->ownerA, '600')['id'];
    }

    protected function tearDown(): void
    {
        Settings::set('mpesa_till_number', '');
        parent::tearDown();
    }

    private function user(string $prefix): int
    {
        return $this->db->insert('users', ['email' => $prefix . '-' . bin2hex(random_bytes(3)) . '@example.com', 'password_hash' => password_hash('Secret123', PASSWORD_DEFAULT),
            'first_name' => 'Idor', 'last_name' => 'Tester']);
    }

    private function loginAs(int $userId): void
    {
        $this->post('/logout', [], '/');
        $this->post('/login', ['email' => (string) $this->db->value('SELECT email FROM users WHERE id = ?', [$userId]), 'password' => 'Secret123'], '/login');
    }

    /** Hash of everything belonging to seller A and the victim order. */
    private function victimState(): string
    {
        $q = [
            ['SELECT * FROM products WHERE seller_id = ? ORDER BY id', [$this->sellerA]],
            ['SELECT v.* FROM product_variants v JOIN products p ON p.id = v.product_id WHERE p.seller_id = ? ORDER BY v.id', [$this->sellerA]],
            ['SELECT i.* FROM inventory i JOIN product_variants v ON v.id = i.variant_id JOIN products p ON p.id = v.product_id WHERE p.seller_id = ? ORDER BY i.variant_id', [$this->sellerA]],
            ['SELECT * FROM product_images WHERE product_id = ? ORDER BY id', [$this->ids['product']]],
            ['SELECT * FROM seller_orders WHERE seller_id = ? ORDER BY id', [$this->sellerA]],
            ['SELECT * FROM seller_disputes WHERE id = ?', [$this->ids['dispute']]],
            ['SELECT COUNT(*) AS n FROM seller_dispute_messages WHERE dispute_id = ?', [$this->ids['dispute']]],
            ['SELECT * FROM seller_payouts WHERE seller_id = ?', [$this->sellerA]],
            ['SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS t FROM seller_ledger_entries WHERE seller_id = ?', [$this->sellerA]],
            ['SELECT * FROM orders WHERE order_number = ?', [$this->orderNumber]],
            ['SELECT COUNT(*) AS n FROM return_requests', []],
            ['SELECT COUNT(*) AS n FROM refunds', []],
        ];
        return hash('sha256', json_encode(array_map(fn ($x) => $this->db->all($x[0], $x[1]), $q)));
    }

    /** @return list<array{method:string,pattern:string}> */
    private function routes(string $prefix): array
    {
        $router = new Router();
        require dirname(__DIR__, 2) . '/routes/web.php';
        return array_values(array_filter($router->all(), fn ($r) => str_starts_with($r['pattern'], $prefix) && str_contains($r['pattern'], '{')));
    }

    /** Fills a pattern's parameters with the victim's ids; fails loudly for any parameter this matrix does not know. */
    private function fill(string $pattern): string
    {
        $resource = match (true) {
            str_starts_with($pattern, '/seller/products/') => 'product',
            str_starts_with($pattern, '/seller/orders/') => 'part',
            str_starts_with($pattern, '/seller/disputes/') => 'dispute',
            str_starts_with($pattern, '/seller/finance/payouts/') => 'payout',
            str_contains($pattern, '/cases/') => 'dispute',
            default => null,
        };
        return (string) preg_replace_callback('/\{(\w+)(?::[^}]+)?\}/', function ($m) use ($pattern, $resource) {
            return (string) match ($m[1]) {
                'id' => $this->ids[$resource ?? 'none'] ?? $this->fail("unmapped resource for $pattern"),
                'vid' => $this->ids['variant'],
                'iid' => $this->ids['image'],
                'number' => $this->orderNumber,
                default => $this->fail("IDOR matrix: unmapped route parameter {{$m[1]}} in $pattern — add it to the matrix"),
            };
        }, $pattern);
    }

    /** Plausible form data, so a request fails on ownership, never on a missing field. */
    private const BODY = ['to' => 'accepted', 'reason' => 'damaged', 'details' => 'IDOR attempt with enough detail.', 'body' => 'IDOR attempt',
        'name' => 'Hijacked', 'price' => '1', 'sku' => 'HIJACK', 'action' => 'set', 'quantity' => '999', 'counted' => '999', 'note' => 'x',
        'seller_order_id' => '0', 'qty' => [], 'amount' => '600'];

    public function testAnotherSellerGets404OnEverySellerCentreRouteAndChangesNothing(): void
    {
        $sellerB = $this->db->insert('sellers', ['name' => 'Attacker Store', 'slug' => 'attacker-store']);
        $ownerB = $this->user('attacker-owner');
        $this->db->insert('seller_users', ['seller_id' => $sellerB, 'user_id' => $ownerB, 'role' => 'owner']);
        $this->loginAs($ownerB);
        $before = $this->victimState();

        $tried = [];
        foreach ($this->routes('/seller/') as $r) {
            $url = $this->fill($r['pattern']);
            $res = $r['method'] === 'GET' ? $this->get($url) : $this->post($url, self::BODY, '/seller');
            $this->assertSame(404, $res['status'], "{$r['method']} $url as another seller");
            $tried[] = "{$r['method']} {$r['pattern']}";
        }
        // Pinned: a new Seller Centre route with an id fails here until it is reviewed and covered by this matrix.
        $this->assertCount(17, $tried, 'Seller Centre routes with an id: ' . implode(', ', $tried));
        $this->assertSame($before, $this->victimState(), "seller A's data is unchanged");
    }

    public function testOtherCustomersAndGuestsGet404OnEveryOrderRoute(): void
    {
        $routes = array_merge($this->routes('/order/'), $this->routes('/account/orders/'));
        $before = $this->victimState();
        foreach (['another customer' => $this->user('attacker-customer'), 'a guest' => null] as $who => $uid) {
            $uid === null ? $this->post('/logout', [], '/') : $this->loginAs($uid);
            foreach ($routes as $r) {
                $url = $this->fill($r['pattern']);
                $res = $r['method'] === 'GET' ? $this->get($url) : $this->post($url, self::BODY, '/');
                $expected = $uid === null && str_starts_with($r['pattern'], '/account/') ? [302, 303] : [404];
                $this->assertContains($res['status'], $expected, "{$r['method']} $url as $who");
            }
        }
        $this->assertGreaterThanOrEqual(10, count($routes));
        $this->assertSame($before, $this->victimState(), 'the victim order and its case are unchanged');
    }

    public function testSellersAndCustomersCannotReachStaffRoutes(): void
    {
        $this->loginAs($this->ownerA);
        foreach (['/admin', '/admin/disputes/' . $this->ids['dispute'], '/admin/payouts/' . $this->ids['payout'], '/admin/sellers/' . $this->sellerA . '/ledger', '/admin/reports/marketplace'] as $url) {
            $this->assertContains($this->get($url)['status'], [302, 303, 403], "GET $url as a seller");
        }
        $before = $this->victimState();
        $this->assertContains($this->post('/admin/payouts/' . $this->ids['payout'] . '/approve', [], '/seller')['status'], [302, 303, 403]);
        $this->assertContains($this->post('/admin/disputes/' . $this->ids['dispute'] . '/resolve', ['resolution' => 'refund_full', 'resolution_note' => 'self-serve refund'], '/seller')['status'], [302, 303, 403]);
        $this->assertSame($before, $this->victimState());
    }
}
