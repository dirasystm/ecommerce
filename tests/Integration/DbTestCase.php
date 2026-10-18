<?php

declare(strict_types=1);

namespace Waimart\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Settings;

/**
 * Base class for tests that hit the real waimart_test database.
 * Transactional data is cleaned per test by deleting from mutable tables.
 */
abstract class DbTestCase extends TestCase
{
    protected Database $db;

    /** Tables with reference data that must survive cleanup. */
    private const KEEP = ['schema_migrations', 'counties', 'roles', 'permissions', 'role_permissions', 'settings'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = App::db();
        $this->truncateMutableTables();
        Settings::flush();
    }

    private function truncateMutableTables(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()") as $r) {
            if (!in_array($r['t'], self::KEEP, true)) {
                $pdo->exec('DELETE FROM `' . $r['t'] . '`');
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Creates a category, product and variant with the given stock; returns ids. */
    protected function makeVariant(int $onHand = 10, string $price = '1000.00', ?int $productId = null, string $name = 'Default'): array
    {
        if ($productId === null) {
            $catId = (int) ($this->db->value('SELECT id FROM categories LIMIT 1') ?: $this->db->insert('categories', [
                'name' => 'Test Category', 'slug' => 'test-category-' . bin2hex(random_bytes(3)),
            ]));
            $productId = $this->db->insert('products', [
                'category_id' => $catId,
                'name' => 'Test Product',
                'slug' => 'test-product-' . bin2hex(random_bytes(4)),
            ]);
        }
        $variantId = $this->db->insert('product_variants', [
            'product_id' => $productId,
            'sku' => 'SKU-' . strtoupper(bin2hex(random_bytes(4))),
            'name' => $name,
            'price' => $price,
            // Only the product's first variant is the default (DB allows exactly one).
            'is_default' => $this->db->value('SELECT 1 FROM product_variants WHERE product_id = ? AND is_default = 1', [$productId]) ? 0 : 1,
        ]);
        $this->db->insert('inventory', ['variant_id' => $variantId, 'on_hand' => $onHand, 'reserved' => 0]);
        return ['product_id' => $productId, 'variant_id' => $variantId];
    }

    /** Inserts a minimal order + one item; returns ids. */
    protected function makeOrder(int $variantId, int $qty = 1, array $overrides = []): array
    {
        $productId = (int) $this->db->value('SELECT product_id FROM product_variants WHERE id = ?', [$variantId]);
        $orderId = $this->db->insert('orders', array_merge([
            'order_number' => 'WM-' . strtoupper(bin2hex(random_bytes(4))),
            'idempotency_key' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            'status' => 'awaiting_payment',
            'payment_method' => 'mpesa_till',
            'payment_status' => 'unpaid',
            'fulfilment_method' => 'delivery',
            'customer_name' => 'Test Customer',
            'customer_phone' => '254712345678',
            'subtotal' => '1000.00',
            'grand_total' => '1000.00',
        ], $overrides));
        $itemId = $this->db->insert('order_items', [
            'order_id' => $orderId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'sku' => 'SKU',
            'product_name' => 'Test Product',
            'variant_name' => 'Default',
            'unit_price' => '1000.00',
            'quantity' => $qty,
            'line_total' => '1000.00',
        ]);
        return ['order_id' => $orderId, 'item_id' => $itemId];
    }

    /** Asserts a closure throws a PDOException whose message contains $needle. */
    protected function assertDbRejects(callable $fn, string $needle): void
    {
        try {
            $fn();
        } catch (\PDOException $e) {
            $this->assertStringContainsStringIgnoringCase($needle, $e->getMessage());
            return;
        }
        $this->fail("Expected the database to reject the operation ($needle).");
    }
}
