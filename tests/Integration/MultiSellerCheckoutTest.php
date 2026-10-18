<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use Waimart\Core\Crypto;
use Waimart\Core\Settings;
use Waimart\Core\ValidationException;
use Waimart\Services\CartService;
use Waimart\Services\ContentService;
use Waimart\Services\OrderService;
use Waimart\Services\OrderWorkflow;
use Waimart\Services\ReservationService;
use Waimart\Services\Sellers;

/** Phase 6: one checkout, one parent order, one seller sub-order per seller; commission, discounts, fees, COD. */
final class MultiSellerCheckoutTest extends DbTestCase
{
    use PlacesOrders { placeOrder as traitPlaceOrder; }

    private int $cat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFulfilment();
        ReservationService::$faultInjector = null;
        $this->cat = (int) $this->db->value('SELECT id FROM categories LIMIT 1')
            ?: $this->db->insert('categories', ['name' => 'Mixed', 'slug' => 'mixed-' . bin2hex(random_bytes(2))]);
        Settings::set('marketplace_cod_policy', 'pickup_and_platform');
        Settings::set('marketplace_delivery_fee_policy', 'per_order');
    }

    protected function tearDown(): void
    {
        ReservationService::$faultInjector = null;
        $this->tearDownFulfilment();
        Settings::set('marketplace_cod_policy', 'pickup_and_platform');
        Settings::set('marketplace_delivery_fee_policy', 'per_order');
        parent::tearDown();
    }

    /** Marketplace carts default to M-Pesa: COD home delivery is not offered for marketplace sellers (tested separately). */
    protected function placeOrder(array $lines, string $payment = 'mpesa_till', string $method = 'delivery', array $extra = []): array
    {
        return $this->traitPlaceOrder($lines, $payment, $method, $extra);
    }

    private function seller(string $slug, ?string $ownerEmail = null): int
    {
        $s = $this->db->insert('sellers', ['name' => ucwords(str_replace('-', ' ', $slug)), 'slug' => $slug]);
        if ($ownerEmail) {
            $u = $this->db->insert('users', ['email' => $ownerEmail, 'password_hash' => 'x', 'first_name' => 'Owner', 'last_name' => 'X']);
            $this->db->insert('seller_users', ['seller_id' => $s, 'user_id' => $u, 'role' => 'owner']);
        }
        return $s;
    }

    /** Published, in-stock variant for a seller. */
    private function item(int $sellerId, string $price, int $stock = 10, ?int $category = null): int
    {
        $v = $this->makeVariant($stock, $price, null, 'Default', $sellerId);
        if ($category !== null) {
            $this->db->exec('UPDATE products SET category_id = ? WHERE id = ?', [$category, $v['product_id']]);
        }
        return $v['variant_id'];
    }

    /** @return list<array<string,mixed>> */
    private function subOrders(int $orderId): array
    {
        return $this->db->all('SELECT * FROM seller_orders WHERE order_id = ? ORDER BY id', [$orderId]);
    }

    public function testOneOrderSplitsIntoSellerSubOrdersWithCommissionSnapshots(): void
    {
        $platform = Sellers::platformId($this->db);
        $b = $this->seller('shop-b');
        $c = $this->seller('shop-c');
        $this->db->insert('commission_rules', ['scope' => 'seller', 'seller_id' => $c, 'rate' => '7.50']);
        $o = $this->placeOrder([[$this->item($platform, '500.00'), 2], [$this->item($b, '1000.00'), 1], [$this->item($c, '1333.00'), 1], [$this->item($b, '250.00'), 2]]);

        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM orders'));
        $g = $this->subOrders((int) $o['id']);
        $this->assertCount(3, $g);
        $this->assertSame([$o['order_number'] . '-1', $o['order_number'] . '-2', $o['order_number'] . '-3'], array_column($g, 'sub_number'));
        $this->assertSame([$platform, $b, $c], array_map('intval', array_column($g, 'seller_id')));
        $this->assertSame(['1000.00', '1500.00', '1333.00'], array_column($g, 'items_subtotal'));
        $this->assertSame(['0.00', '150.00', '99.98'], array_column($g, 'commission_total'), '0% official, 10% default, 7.5% seller rule (rounded half up)');
        $this->assertSame(['1000.00', '1350.00', '1233.02'], array_column($g, 'seller_net'));
        $this->assertSame(['pending_acceptance'], array_values(array_unique(array_column($g, 'status'))));
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM order_items oi JOIN seller_orders so ON so.id = oi.seller_order_id WHERE oi.order_id = ? AND so.seller_id <> oi.seller_id', [$o['id']]));
        $this->assertSame('3833.00', $this->db->value('SELECT SUM(items_subtotal) FROM seller_orders WHERE order_id = ?', [$o['id']]));
        $this->assertSame('3833.00', $this->db->value('SELECT subtotal FROM orders WHERE id = ?', [$o['id']]));
        $this->assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM seller_order_history h JOIN seller_orders so ON so.id = h.seller_order_id WHERE so.order_id = ?', [$o['id']]));
        $this->assertSame('0.00', $this->db->value('SELECT SUM(delivery_fee_share) FROM seller_orders WHERE order_id = ?', [$o['id']]), 'delivery fee belongs to the platform');
    }

    public function testCommissionPrecedenceAndSnapshotsDoNotChangeLater(): void
    {
        $b = $this->seller('shop-b');
        $parentCat = $this->db->insert('categories', ['name' => 'Electronics', 'slug' => 'electronics-' . bin2hex(random_bytes(2))]);
        $childCat = $this->db->insert('categories', ['name' => 'Phones', 'slug' => 'phones-' . bin2hex(random_bytes(2)), 'parent_id' => $parentCat]);
        $this->db->insert('commission_rules', ['scope' => 'category', 'category_id' => $parentCat, 'rate' => '12.00']);
        $o1 = $this->placeOrder([[$this->item($b, '1000.00', 10, $childCat), 1]]);
        $this->assertSame('12.00', $this->db->value('SELECT commission_rate FROM order_items WHERE order_id = ?', [$o1['id']]), "parent category's rule applies");

        $this->db->insert('commission_rules', ['scope' => 'category', 'category_id' => $childCat, 'rate' => '8.00']);
        $o2 = $this->placeOrder([[$this->item($b, '1000.00', 10, $childCat), 1]]);
        $this->assertSame('8.00', $this->db->value('SELECT commission_rate FROM order_items WHERE order_id = ?', [$o2['id']]), 'own category beats parent');

        $sellerRule = $this->db->insert('commission_rules', ['scope' => 'seller', 'seller_id' => $b, 'rate' => '5.00']);
        $o3 = $this->placeOrder([[$this->item($b, '1000.00', 10, $childCat), 1]]);
        $this->assertSame(['5.00', (string) $sellerRule], array_values(array_map('strval', (array) $this->db->one('SELECT commission_rate, commission_rule_id FROM order_items WHERE order_id = ?', [$o3['id']]))), 'seller rule beats category');

        $this->db->exec('UPDATE commission_rules SET is_active = 0 WHERE is_active = 1');
        $this->db->insert('commission_rules', ['scope' => 'default', 'rate' => '20.00']);
        $this->assertSame(['120.00', '80.00', '50.00'], array_column($this->db->all('SELECT commission_total FROM seller_orders WHERE order_id IN (?, ?, ?) ORDER BY order_id', [$o1['id'], $o2['id'], $o3['id']]), 'commission_total'),
            'changing rules later never alters placed orders');
    }

    public function testCouponDiscountIsAllocatedExactlyAndDoesNotReduceSellerNet(): void
    {
        $b = $this->seller('shop-b');
        $c = $this->seller('shop-c');
        $this->db->insert('coupons', ['code' => 'SAVE301', 'discount_type' => 'fixed', 'discount_value' => '301.00']);
        $o = $this->placeOrder([[$this->item($b, '1000.00'), 1], [$this->item($c, '2000.00'), 1], [$this->item(Sellers::platformId($this->db), '333.00'), 1]], 'cod', 'pickup', ['coupon_code' => 'SAVE301']);
        $g = $this->subOrders((int) $o['id']);
        $this->assertSame('301.00', $o['discount_total']);
        $this->assertSame('301.00', \Waimart\Core\Money::add(...array_column($g, 'discount_share')), 'shares add up to the discount exactly');
        // 301 × 1000/3333 = 90.31, 301 × 2000/3333 = 180.62, remainder 30.07 on the last group.
        $this->assertSame(['90.31', '180.62', '30.07'], array_column($g, 'discount_share'), 'pro rata, remainder on the last group');
        $this->assertSame(['900.00', '1800.00', '333.00'], array_column($g, 'seller_net'), 'platform-funded coupon: commission on full price, net not reduced');
        $this->assertSame(['0.00'], array_values(array_unique(array_column($g, 'seller_discount'))));
    }

    public function testFailureAfterSubOrdersRollsBackEverything(): void
    {
        $b = $this->seller('shop-b');
        $x = $this->item($b, '1000.00', 5);
        $y = $this->item(Sellers::platformId($this->db), '500.00', 5);
        ReservationService::$faultInjector = function (int $variantId, int $index) {
            if ($index === 1) {
                throw new \PDOException('Simulated failure after seller orders were created');
            }
        };
        try {
            $this->placeOrder([[$x, 1], [$y, 1]]);
            $this->fail('expected failure');
        } catch (\PDOException) {
        }
        foreach (['orders', 'order_items', 'seller_orders', 'seller_order_history', 'stock_reservations'] as $t) {
            $this->assertSame(0, (int) $this->db->value("SELECT COUNT(*) FROM `$t`"), "$t empty after rollback");
        }
        $this->assertSame([5, 0], array_values($this->levels($x)));
    }

    public function testRetryWithSameKeyCreatesNoSecondOrderOrSubOrders(): void
    {
        $b = $this->seller('shop-b');
        $cart = new CartService(null, null, $this->db);
        $cart->add($this->item($b, '1000.00'), 1);
        $cart->add($this->item(Sellers::platformId($this->db), '500.00'), 1);
        $key = Crypto::token(32);
        $in = ['customer_name' => 'R', 'customer_phone' => '0712345678', 'fulfilment_method' => 'pickup',
               'pickup_point_id' => (string) $this->db->value('SELECT id FROM pickup_points LIMIT 1'), 'payment_method' => 'mpesa_till'];
        $first = (new OrderService($this->db))->place($cart, $in, null, $key, '127.0.0.1');
        $second = (new OrderService($this->db))->place($cart, $in, null, $key, '127.0.0.1');
        $this->assertFalse($second['created']);
        $this->assertSame($first['order']['id'], $second['order']['id']);
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM seller_orders'));
    }

    public function testCashOnDeliveryRule(): void
    {
        $b = $this->seller('shop-b');
        $third = $this->item($b, '1000.00');
        $official = $this->item(Sellers::platformId($this->db), '500.00');
        try {
            $this->placeOrder([[$third, 1], [$official, 1]], 'cod', 'delivery');
            $this->fail('COD home delivery with a marketplace seller must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('pickup', $e->errors['payment_method'] ?? '');
        }
        $this->assertSame('pending_confirmation', $this->placeOrder([[$third, 1]], 'cod', 'pickup')['status'], 'COD at a Waimart pickup desk is fine');
        $this->assertSame('pending_confirmation', $this->placeOrder([[$official, 1]], 'cod', 'delivery')['status'], 'Official Store only: COD delivery allowed');
        Settings::set('marketplace_cod_policy', 'all');
        $this->assertSame('pending_confirmation', $this->placeOrder([[$third, 1]], 'cod', 'delivery')['status'], "policy 'all' lifts the rule");
    }

    public function testDeliveryFeePolicies(): void
    {
        $b = $this->seller('shop-b');
        $lines = [[$this->item($b, '1000.00'), 1], [$this->item(Sellers::platformId($this->db), '500.00'), 1]];
        $this->assertSame('200.00', $this->placeOrder($lines)['delivery_fee'], 'per order: one zone fee');
        Settings::set('marketplace_delivery_fee_policy', 'per_seller');
        $o = $this->placeOrder($lines);
        $this->assertSame('400.00', $o['delivery_fee'], 'per seller: one fee per seller group');
        $this->assertSame('1900.00', $o['grand_total']);
    }

    public function testSubOrdersFollowConfirmationCancellationAndExpiryAndSellersAreNotified(): void
    {
        $b = $this->seller('shop-b', 'owner-b@example.com');
        $o = $this->placeOrder([[$this->item($b, '1000.00'), 1], [$this->item(Sellers::platformId($this->db), '500.00'), 1]], 'cod', 'pickup');
        $staff = $this->staffUser();
        $order = (new OrderWorkflow($this->db))->transition((int) $o['id'], 'confirmed', ['type' => 'staff', 'id' => $staff], 'COD confirmed');
        \Waimart\Services\OrderNotifier::statusChanged($order);
        // Phase 7: the marketplace seller accepts their own part; Waimart's part is accepted on confirmation.
        $this->assertSame(['pending_acceptance', 'accepted'], array_column($this->subOrders((int) $o['id']), 'status'));
        $mail = $this->db->all("SELECT recipient, subject FROM notifications WHERE template = 'seller_order_confirmed'");
        $this->assertCount(1, $mail, 'only the marketplace seller is emailed, not the Official Store');
        $this->assertSame('owner-b@example.com', $mail[0]['recipient']);
        $this->assertStringContainsString($o['order_number'] . '-1', $mail[0]['subject']);

        (new OrderWorkflow($this->db))->transition((int) $o['id'], 'cancelled', ['type' => 'staff', 'id' => $staff], 'Customer changed mind');
        $g = $this->subOrders((int) $o['id']);
        $this->assertSame(['cancelled'], array_values(array_unique(array_column($g, 'status'))));
        $this->assertSame('Customer changed mind', $g[0]['cancel_reason']);

        $o2 = $this->placeOrder([[$this->item($b, '1000.00'), 1]], 'mpesa_till');
        $this->db->exec('UPDATE stock_reservations SET expires_at = NOW() - INTERVAL 1 MINUTE');
        (new \Waimart\Services\ExpiryService($this->db))->run();
        $this->assertSame('cancelled', $this->db->value('SELECT status FROM seller_orders WHERE order_id = ?', [$o2['id']]));
    }

    public function testFlashSalesNeedTheSellersConsent(): void
    {
        $b = $this->seller('shop-b');
        $v = $this->item($b, '1000.00');
        $sku = (string) $this->db->value('SELECT sku FROM product_variants WHERE id = ?', [$v]);
        $sale = $this->db->insert('flash_sales', ['title' => 'Weekend', 'starts_at' => date('Y-m-d H:i:s'), 'ends_at' => date('Y-m-d H:i:s', time() + 86400)]);
        try {
            (new ContentService($this->db))->addSaleItem($sale, $sku, '800', $this->staffUser());
            $this->fail('seller has not opted in');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sku', $e->errors);
        }
        $this->db->exec('UPDATE sellers SET allow_promotions = 1 WHERE id = ?', [$b]);
        (new ContentService($this->db))->addSaleItem($sale, $sku, '800', $this->staffUser());
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM flash_sale_items'));
    }
}
