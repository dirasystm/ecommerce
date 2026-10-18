<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\App;

final class CustomerFlowTest extends FeatureTestCase
{
    private function registerViaHttp(string $email = 'shopper@example.com'): array
    {
        $r = $this->post('/register', [
            'first_name' => 'Amina', 'last_name' => 'Otieno', 'email' => $email, 'phone' => '0712345678',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'accept_terms' => '1',
        ], '/register');
        $this->assertSame(303, $r['status']);
        return $r;
    }

    private function productSlug(int $variantId): string
    {
        return (string) $this->db->value('SELECT p.slug FROM products p JOIN product_variants v ON v.product_id = p.id WHERE v.id = ?', [$variantId]);
    }

    public function testRegisterSignsInAndAccountPagesAreProtected(): void
    {
        $this->assertSame(303, $this->get('/account')['status'], 'guests are redirected to login');
        $r = $this->registerViaHttp();
        $this->assertStringEndsWith('/account', $r['headers']['location']);
        $page = $this->get('/account');
        $this->assertSame(200, $page['status']);
        $this->assertStringContainsString('Amina Otieno', $page['body']);
        $this->assertStringContainsString('no-store', $page['headers']['cache-control'] ?? '', 'personal pages are not cached');

        $this->post('/logout', [], '/account');
        $this->assertSame(303, $this->get('/account')['status']);
    }

    public function testGuestCartSurvivesNavigationAndMergesOnLogin(): void
    {
        $v = $this->makeVariant(5)['variant_id'];
        $this->registerViaHttp();
        $this->post('/logout', [], '/account');

        $slug = $this->productSlug($v);
        $this->post('/cart/add', ['variant_id' => (string) $v, 'quantity' => '2'], "/product/$slug");
        $this->get('/');
        $this->get('/help');
        $this->assertStringContainsString('Cart (2)', $this->get('/cart')['body'], 'guest cart persists across pages');

        $r = $this->post('/login', ['email' => 'shopper@example.com', 'password' => 'Secret123'], '/login');
        $this->assertSame(303, $r['status']);
        $this->assertStringContainsString('Cart (2)', $this->get('/cart')['body'], 'merged into the account cart');
        $uid = (int) $this->db->value("SELECT id FROM users WHERE email = 'shopper@example.com'");
        $this->assertSame(2, (int) $this->db->value('SELECT SUM(ci.quantity) FROM cart_items ci JOIN carts c ON c.id = ci.cart_id WHERE c.user_id = ?', [$uid]));
    }

    public function testAddToCartCapsAtStockAndExplains(): void
    {
        $v = $this->makeVariant(3)['variant_id'];
        $slug = $this->productSlug($v);
        $r = $this->post('/cart/add', ['variant_id' => (string) $v, 'quantity' => '9'], "/product/$slug");
        $this->assertSame(303, $r['status'], 'without JavaScript the form redirects back');
        $this->assertStringEndsWith("/product/$slug", $r['headers']['location']);
        $this->assertStringContainsString('Only 3 more', $this->follow($r)['body']);
    }

    public function testLoginReturnsToIntendedInternalPageOnly(): void
    {
        $this->registerViaHttp('safe@example.com');
        $this->post('/logout', [], '/account');

        // "Intended" is recorded by the server from its own request path, so it is always local.
        $this->get('/account/addresses');
        $r = $this->post('/login', ['email' => 'safe@example.com', 'password' => 'Secret123'], '/login');
        $this->assertSame('/account/addresses', $r['headers']['location']);
        $this->post('/logout', [], '/account');

        // Query strings ride along but can never change the host.
        $this->get('/account/orders?next=' . rawurlencode('//evil.example/x'));
        $loc = $this->post('/login', ['email' => 'safe@example.com', 'password' => 'Secret123'], '/login')['headers']['location'];
        $this->assertStringStartsWith('/account/orders', $loc);
        $this->assertNull(parse_url($loc, PHP_URL_HOST));
        $this->post('/logout', [], '/account');

        // Customers bounced from the staff area never get sent back into it.
        $this->get('/admin');
        $loc = $this->post('/login', ['email' => 'safe@example.com', 'password' => 'Secret123'], '/login')['headers']['location'];
        $this->assertStringNotContainsString('/admin', $loc);
    }

    public function testUsersCannotTouchEachOthersAddresses(): void
    {
        $this->registerViaHttp('owner@example.com');
        $this->post('/account/addresses', ['recipient_name' => 'Owner', 'phone' => '0712345678', 'county_id' => '47', 'town' => 'Karen', 'location' => 'Karen Road 1'], '/account/addresses/new');
        $addrId = (int) $this->db->value('SELECT id FROM user_addresses');
        $this->post('/logout', [], '/account');

        $this->registerViaHttp('intruder@example.com');
        $this->assertSame(404, $this->get("/account/addresses/$addrId/edit")['status']);
        $this->assertSame(404, $this->post("/account/addresses/$addrId", ['recipient_name' => 'Hacked', 'phone' => '0712345678', 'county_id' => '47', 'town' => 'X', 'location' => 'Hacked'], '/account/addresses')['status']);
        $this->assertSame(404, $this->post("/account/addresses/$addrId/delete", [], '/account/addresses')['status']);
        $this->assertSame('Owner', $this->db->value('SELECT recipient_name FROM user_addresses WHERE id = ?', [$addrId]));
        $this->assertStringNotContainsString('Karen Road 1', $this->get('/account/addresses')['body']);
    }

    public function testPasswordResetEndToEnd(): void
    {
        $this->registerViaHttp('forgot@example.com');
        $this->post('/logout', [], '/account');

        $known = $this->post('/forgot-password', ['email' => 'forgot@example.com'], '/forgot-password');
        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com'], '/forgot-password');
        $this->assertSame($known['headers']['location'], $unknown['headers']['location'], 'identical response');

        $log = (string) file_get_contents(App::root() . '/storage/logs/mail-' . date('Y-m-d') . '.log');
        $this->assertSame(1, preg_match_all('#reset-password\?token=([A-Za-z0-9_-]{43})#', $log, $m) >= 1 ? 1 : 0);
        $token = end($m[1]);

        $this->assertStringContainsString('Set a new password', $this->get('/reset-password?token=' . $token)['body']);
        $r = $this->post('/reset-password', ['token' => $token, 'password' => 'Brand New 7', 'password_confirmation' => 'Brand New 7'], '/reset-password?token=' . $token);
        $this->assertStringEndsWith('/login', $r['headers']['location']);

        $this->assertStringContainsString('invalid, has already been used', $this->get('/reset-password?token=' . $token)['body'], 'single use');
        $this->assertSame(303, $this->post('/login', ['email' => 'forgot@example.com', 'password' => 'Brand New 7'], '/login')['status']);
        $this->assertSame(200, $this->get('/account')['status']);
    }
}
