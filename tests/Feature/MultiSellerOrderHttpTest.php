<?php

declare(strict_types=1);

namespace Waimart\Tests\Feature;

use Waimart\Core\Settings;
use Waimart\Services\Sellers;

/** Phase 6 over HTTP: mixed-seller checkout, COD explanation, grouped order pages, admin sub-order panel. */
final class MultiSellerOrderHttpTest extends FeatureTestCase
{
    private int $official;
    private int $market;

    protected function setUp(): void
    {
        parent::setUp();
        $zone = $this->db->insert('delivery_zones', ['name' => 'City', 'fee' => '250', 'min_days' => 1, 'max_days' => 2, 'cod_allowed' => 1]);
        $this->db->insert('delivery_zone_locations', ['zone_id' => $zone, 'county_id' => 47, 'town' => '']);
        $this->db->insert('pickup_points', ['code' => 'P1', 'name' => 'CBD Station', 'county_id' => 47, 'town' => 'CBD', 'address' => 'Moi Ave', 'opening_hours' => '9-5']);
        Settings::set('mpesa_till_number', '123456');
        $seller = $this->db->insert('sellers', ['name' => 'Kamba Crafts', 'slug' => 'kamba-crafts']);
        $this->official = $this->makeVariant(5, '1000.00')['variant_id'];
        $this->market = $this->makeVariant(5, '2000.00', null, 'Default', $seller)['variant_id'];
        $this->db->exec("UPDATE products SET name = 'Woodcarving Giraffe' WHERE id = (SELECT product_id FROM product_variants WHERE id = ?)", [$this->market]);
    }

    protected function tearDown(): void
    {
        Settings::set('mpesa_till_number', '');
        parent::tearDown();
    }

    private function fillCart(): string
    {
        foreach ([$this->official, $this->market] as $v) {
            $slug = (string) $this->db->value('SELECT p.slug FROM products p JOIN product_variants v ON v.product_id = p.id WHERE v.id = ?', [$v]);
            $this->post('/cart/add', ['variant_id' => (string) $v, 'quantity' => '1'], '/product/' . $slug);
        }
        return $this->get('/checkout')['body'];
    }

    public function testCheckoutExplainsSellersFeeAndCashOnDelivery(): void
    {
        $page = $this->fillCart();
        $this->assertStringContainsString('Sold by Kamba Crafts', $page);
        $this->assertStringContainsString('Sold by Waimart Official Store', $page);
        $this->assertStringContainsString('One delivery fee covers your whole order', $page);

        $delivery = json_decode($this->post('/checkout/quote', ['fulfilment_method' => 'delivery', 'county_id' => '47', 'town' => 'Kilimani'], '/checkout')['body'], true);
        $this->assertSame('250.00', $delivery['fee'], 'one fee for two sellers');
        $this->assertFalse($delivery['cod_allowed']);
        $this->assertStringContainsString('pickup station', (string) $delivery['cod_reason']);
        $pickupId = (string) $this->db->value('SELECT id FROM pickup_points LIMIT 1');
        $pickup = json_decode($this->post('/checkout/quote', ['fulfilment_method' => 'pickup', 'pickup_point_id' => $pickupId], '/checkout')['body'], true);
        $this->assertNotFalse($pickup['cod_allowed']);
    }

    public function testPlacedOrderShowsSellerGroupsToCustomerAndSubOrdersToAdmin(): void
    {
        $page = $this->fillCart();
        preg_match('/name="checkout_token" value="([^"]+)"/', $page, $t);
        preg_match('/name="_csrf" value="([^"]+)"/', $page, $c);
        $base = ['_csrf' => html_entity_decode($c[1]), 'checkout_token' => $t[1], 'customer_name' => 'Wanjiku', 'customer_phone' => '0722333444',
                 'customer_email' => 'wanjiku@example.com', 'fulfilment_method' => 'delivery', 'county_id' => '47', 'town' => 'Kilimani', 'location' => 'Argwings Kodhek'];

        $cod = $this->http('POST', '/checkout', $base + ['payment_method' => 'cod'], [], '/checkout');
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM orders'), 'COD home delivery with a marketplace item is refused server-side');
        $this->assertStringEndsWith('/checkout', $cod['headers']['location'] ?? '');

        $r = $this->http('POST', '/checkout', $base + ['payment_method' => 'mpesa_till'], [], '/checkout');
        $this->assertSame(303, $r['status'], $this->lastBody);
        $order = (array) $this->db->one('SELECT * FROM orders');
        $confirmation = $this->follow($r)['body'];
        $this->assertStringContainsString('items from 2 sellers', $confirmation);
        $this->assertStringContainsString('Sold by <a href="/store/kamba-crafts">Kamba Crafts</a>', $confirmation);
        $this->assertStringContainsString($order['order_number'] . '-1', $confirmation);
        $this->assertStringContainsString($order['order_number'] . '-2', $confirmation);
        $this->assertStringNotContainsString('Commission', $confirmation, 'commission is never shown to customers');

        $this->post('/logout', [], '/');
        $this->loginStaff('manager');
        $admin = $this->get('/admin/orders/' . $order['id'])['body'];
        $this->assertStringContainsString('Seller sub-orders', $admin);
        $this->assertStringContainsString('Kamba Crafts', $admin);
        $this->assertStringContainsString('KSh 200', $admin, '10% commission on KSh 2,000');
        $this->assertStringContainsString('KSh 1,800', $admin, 'seller net');
        $this->assertSame(Sellers::platformId($this->db), (int) $this->db->value('SELECT seller_id FROM seller_orders WHERE sub_number = ?', [$order['order_number'] . '-1']));
    }
}
