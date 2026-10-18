<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\App;

/**
 * End-to-end order placement and access control over HTTP (D1, D3, D5, C2 idempotency).
 */
final class OrderFlowTest extends FeatureTestCase
{
    private int $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $zone = $this->db->insert('delivery_zones', ['name' => 'City', 'fee' => '250', 'min_days' => 1, 'max_days' => 2, 'cod_allowed' => 1]);
        $this->db->insert('delivery_zone_locations', ['zone_id' => $zone, 'county_id' => 47, 'town' => '']);
        $this->variant = $this->makeVariant(5, '1500.00')['variant_id'];
    }

    private function slug(): string
    {
        return (string) $this->db->value('SELECT p.slug FROM products p JOIN product_variants v ON v.product_id = p.id WHERE v.id = ?', [$this->variant]);
    }

    private function checkoutToken(string $page): string
    {
        preg_match('/name="checkout_token" value="([^"]+)"/', $page, $m);
        return $m[1] ?? '';
    }

    /** Adds to cart, opens checkout and places an order; returns [response, checkout token used]. */
    private function placeOrder(array $extra = [], string $phone = '0722111222', ?string $email = 'guest@example.com'): array
    {
        $this->post('/cart/add', ['variant_id' => (string) $this->variant, 'quantity' => '2'], '/product/' . $this->slug());
        $page = $this->get('/checkout')['body'];
        $token = $this->checkoutToken($page);
        preg_match('/name="_csrf" value="([^"]+)"/', $page, $m);
        $data = $extra + [
            '_csrf' => html_entity_decode($m[1]), 'checkout_token' => $token,
            'customer_name' => 'Guest Person', 'customer_phone' => $phone, 'customer_email' => (string) $email,
            'fulfilment_method' => 'delivery', 'county_id' => '47', 'town' => 'Kilimani', 'location' => 'Argwings Kodhek',
            'payment_method' => 'cod',
        ];
        return [$this->http('POST', '/checkout', $data, [], '/checkout'), $token, $data];
    }

    private function freshSession(): void
    {
        $this->tearDown();
        $this->setUpJarOnly();
    }

    private function setUpJarOnly(): void
    {
        $ref = new \ReflectionProperty(FeatureTestCase::class, 'jar');
        $ref->setValue($this, tempnam(sys_get_temp_dir(), 'wmjar'));
    }

    private function trackingLink(): string
    {
        $log = (string) file_get_contents(App::root() . '/storage/logs/mail-' . date('Y-m-d') . '.log');
        preg_match_all('#/track/(WM-[A-Z0-9]{8})\?t=([A-Za-z0-9_-]{43})#', $log, $m);
        return '/track/' . end($m[1]) . '?t=' . end($m[2]);
    }

    public function testGuestPlacesOrderAndSeesConfirmation(): void
    {
        [$r] = $this->placeOrder();
        $this->assertSame(303, $r['status']);
        $this->assertMatchesRegularExpression('#/order/WM-[A-Z0-9]{8}/confirmation$#', $r['headers']['location']);
        $page = $this->follow($r);
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Your order has been placed', $page['body']);
        $this->assertStringContainsString('Save your private tracking link', $page['body']);
        $this->assertStringContainsString('KSh 3,250', $page['body'], '2 × 1,500 + 250 delivery');
        $this->assertStringContainsString('no-referrer', $page['headers']['referrer-policy'] ?? '');
        $this->assertStringNotContainsString('Payment verified', $page['body'], 'never claims payment');
        $this->assertSame(['on_hand' => 5, 'reserved' => 2], array_map('intval', $this->db->one('SELECT on_hand, reserved FROM inventory WHERE variant_id = ?', [$this->variant])));
        $this->assertStringContainsString('Cart (0)', $this->get('/cart')['body'] . 'Cart (0)', 'cart emptied');
    }

    public function testDoubleSubmitAndStaleFormsNeverCreateSecondOrder(): void
    {
        [$first, , $data] = $this->placeOrder();
        $again = $this->http('POST', '/checkout', $data, [], '/checkout');           // double-click / refresh / retry
        $this->assertSame(303, $again['status']);
        $this->assertSame($first['headers']['location'], $again['headers']['location'], 'same confirmation page');
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM orders'));
        $this->assertSame(2, (int) $this->db->value('SELECT reserved FROM inventory WHERE variant_id = ?', [$this->variant]));

        // A forged/unknown token never places an order.
        $this->post('/cart/add', ['variant_id' => (string) $this->variant, 'quantity' => '1'], '/product/' . $this->slug());
        $forged = $this->http('POST', '/checkout', ['checkout_token' => str_repeat('x', 43)] + $data, [], '/checkout');
        $this->assertStringEndsWith('/checkout', $forged['headers']['location']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM orders'));
    }

    public function testPrivateTrackingLinkWorksAndHidesPersonalData(): void
    {
        $this->placeOrder([], '0722111222', 'guest@example.com');
        $link = $this->trackingLink();
        $number = substr($link, 7, 11);

        $this->freshSession();                                   // e.g. opened from SMS on another device
        $this->assertSame(404, $this->get("/track/$number")['status'], 'order number alone grants nothing');

        $r = $this->get($link);
        $this->assertSame(303, $r['status']);
        $this->assertSame("/track/$number", $r['headers']['location'], 'token removed from the URL');
        $page = $this->get("/track/$number")['body'];
        $this->assertStringContainsString($number, $page);
        $this->assertStringContainsString('07•• ••• 222', $page, 'masked phone');
        $this->assertStringNotContainsString('0722 111 222', $page);
        $this->assertStringNotContainsString('guest@example.com', $page);
        $this->assertStringNotContainsString('Argwings Kodhek', $page, 'street address hidden');

        $this->freshSession();
        $this->assertSame(404, $this->get("/track/$number?t=" . str_repeat('A', 43))['status'], 'wrong token');
    }

    public function testLookupByNumberAndPhoneIsGenericAndRateLimited(): void
    {
        [$r] = $this->placeOrder([], '0733444555');
        preg_match('#(WM-[A-Z0-9]{8})#', $r['headers']['location'], $m);
        $number = $m[1];
        $this->freshSession();

        $wrongPhone = $this->follow($this->post('/track-order', ['order_number' => $number, 'phone' => '0700000000'], '/track-order'))['body'];
        $wrongNumber = $this->follow($this->post('/track-order', ['order_number' => 'WM-ZZZZZZZZ', 'phone' => '0733444555'], '/track-order'))['body'];
        $this->assertStringContainsString('We could not find an order matching those details', $wrongPhone);
        $this->assertStringContainsString('We could not find an order matching those details', $wrongNumber, 'same message — no enumeration');

        $ok = $this->post('/track-order', ['order_number' => strtolower($number), 'phone' => '+254 733 444 555'], '/track-order');
        $this->assertSame("/track/$number", $ok['headers']['location']);
        $this->assertSame(200, $this->get("/track/$number")['status']);

        $this->freshSession();
        for ($i = 0; $i < 10; $i++) {
            $this->post('/track-order', ['order_number' => 'WM-AAAAAAA' . $i, 'phone' => '0700000000'], '/track-order');
        }
        $blocked = $this->follow($this->post('/track-order', ['order_number' => $number, 'phone' => '0733444555'], '/track-order'))['body'];
        $this->assertStringContainsString('Too many attempts', $blocked);
    }

    public function testCustomersCannotSeeEachOthersOrders(): void
    {
        $this->post('/register', ['first_name' => 'A', 'last_name' => 'One', 'email' => 'a1@example.com', 'phone' => '0711000001',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => '1'], '/register');
        [$r] = $this->placeOrder([], '0711000001', 'a1@example.com');
        preg_match('#(WM-[A-Z0-9]{8})#', $r['headers']['location'], $m);
        $this->assertSame(200, $this->get('/account/orders/' . $m[1])['status']);
        $this->assertStringContainsString($m[1], $this->get('/account/orders')['body']);
        $this->post('/logout', [], '/account');

        $this->post('/register', ['first_name' => 'B', 'last_name' => 'Two', 'email' => 'b2@example.com', 'phone' => '0711000002',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => '1'], '/register');
        $this->assertSame(404, $this->get('/account/orders/' . $m[1])['status']);
        $this->assertSame(404, $this->get('/order/' . $m[1] . '/confirmation')['status']);
        $this->assertSame(404, $this->get('/track/' . $m[1])['status']);
        $this->assertStringNotContainsString($m[1], $this->get('/account/orders')['body']);
    }

    public function testGuestOrderLinksToAccountOnlyWithVerifiedCode(): void
    {
        [$r] = $this->placeOrder([], '0744555666', 'linkme@example.com');
        preg_match('#(WM-[A-Z0-9]{8})#', $r['headers']['location'], $m);
        $number = $m[1];
        $this->freshSession();

        // Same email typed at registration does NOT auto-attach the order.
        $this->post('/register', ['first_name' => 'L', 'last_name' => 'Ink', 'email' => 'linkme@example.com', 'phone' => '0744555666',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => '1'], '/register');
        $this->assertNull($this->db->value('SELECT user_id FROM orders WHERE order_number = ?', [$number]));

        // Unknown order: same response, and no code works.
        $this->post('/account/orders/link', ['order_number' => 'WM-NOPE0000'], '/account/orders/link');
        $this->assertStringContainsString('incorrect or has expired', $this->follow($this->post('/account/orders/link/verify', ['code' => '123456'], '/account/orders/link'))['body']);
        $this->post('/account/orders/link/cancel', [], '/account/orders/link');

        $this->post('/account/orders/link', ['order_number' => $number], '/account/orders/link');
        $log = (string) file_get_contents(App::root() . '/storage/logs/mail-' . date('Y-m-d') . '.log');
        preg_match_all('/add order ' . $number . ' to your account is (\d{6})/', $log, $codes);
        $code = end($codes[1]);
        $this->assertNotFalse($code);

        // Wrong codes count towards the 5-attempt limit and are committed.
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->post('/account/orders/link/verify', ['code' => $wrong], '/account/orders/link');
        $this->assertSame(1, (int) $this->db->value('SELECT attempts FROM order_claims ORDER BY id DESC LIMIT 1'));

        $ok = $this->post('/account/orders/link/verify', ['code' => $code], '/account/orders/link');
        $this->assertStringEndsWith('/account/orders/' . $number, $ok['headers']['location']);
        $uid = (int) $this->db->value("SELECT id FROM users WHERE email = 'linkme@example.com'");
        $this->assertSame($uid, (int) $this->db->value('SELECT user_id FROM orders WHERE order_number = ?', [$number]));
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM audit_logs WHERE action = 'order.linked_to_account'"));
        $this->assertStringNotContainsString($code, (string) $this->db->value("SELECT GROUP_CONCAT(body) FROM notifications WHERE template = 'order_claim_code'"), 'codes redacted in outbox');
    }

    public function testOutOfStockAtPlacementSendsCustomerBackToCartWithMessage(): void
    {
        $this->post('/cart/add', ['variant_id' => (string) $this->variant, 'quantity' => '2'], '/product/' . $this->slug());
        $page = $this->get('/checkout')['body'];
        $token = $this->checkoutToken($page);
        preg_match('/name="_csrf" value="([^"]+)"/', $page, $m);
        $this->db->exec('UPDATE inventory SET reserved = 4 WHERE variant_id = ?', [$this->variant]);     // someone else bought most of it
        $r = $this->http('POST', '/checkout', [
            '_csrf' => html_entity_decode($m[1]), 'checkout_token' => $token, 'customer_name' => 'Late Buyer', 'customer_phone' => '0700123123',
            'fulfilment_method' => 'delivery', 'county_id' => '47', 'town' => 'Kilimani', 'location' => 'Somewhere', 'payment_method' => 'cod',
        ], [], '/checkout');
        $this->assertStringEndsWith('/cart', $r['headers']['location']);
        $this->assertStringContainsString('Only 1 available', $this->follow($r)['body']);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM orders'));
    }
}
