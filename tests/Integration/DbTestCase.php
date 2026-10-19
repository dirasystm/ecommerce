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
    private const KEEP = ['schema_migrations', 'counties', 'roles', 'permissions', 'role_permissions', 'settings', 'seller_slug_aliases'];
    /** Tables cleaned only partially: the WHERE keeps reference rows seeded by migrations. */
    private const KEEP_ROWS = ['sellers' => ' WHERE is_platform = 0'];
    /** Tables whose triggers refuse UPDATE/DELETE; emptied with TRUNCATE between tests. */
    private const APPEND_ONLY = ['seller_ledger_entries', 'seller_payout_history'];
    /**
     * Content seeded by migrations 022 (pages, menus, homepage) and 023 (payment methods). Captured once from the freshly
     * migrated test database and put back before every test, so each test starts from the shipped content and no test
     * can leak edits.
     */
    private const CONTENT_SEEDED = ['content_pages', 'menus', 'menu_items', 'home_sections', 'payment_methods'];
    /** @var array<string,list<array<string,mixed>>>|null */
    private static ?array $contentSeed = null;

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
        if (self::$contentSeed === null) {
            foreach (self::CONTENT_SEEDED as $t) {
                self::$contentSeed[$t] = $this->db->all("SELECT * FROM `$t` ORDER BY " . match ($t) { 'menu_items' => 'parent_id IS NOT NULL, id', 'payment_methods' => 'code', default => 'id' });
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->all("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE()") as $r) {
            if (in_array($r['t'], self::APPEND_ONLY, true)) {
                $pdo->exec('TRUNCATE TABLE `' . $r['t'] . '`');   // DELETE is refused by the append-only triggers (016)
            } elseif (!in_array($r['t'], self::KEEP, true)) {
                $pdo->exec('DELETE FROM `' . $r['t'] . '`' . (self::KEEP_ROWS[$r['t']] ?? ''));
            }
        }
        // Marketplace reference rows seeded by migrations 010/013, restored to their shipped values.
        $pdo->exec("UPDATE sellers SET status = 'active', suspended_at = NULL, suspension_reason = NULL WHERE is_platform = 1");
        $pdo->exec("INSERT INTO commission_rules (scope, seller_id, rate, note) VALUES
            ('default', NULL, 10.00, 'Provisional default'), ('seller', 1, 0.00, 'Waimart Official Store')");
        foreach (self::$contentSeed as $t => $rows) {
            foreach ($rows as $row) {
                $this->db->insert($t, $row);
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        \Waimart\Services\Sellers::flush();
        \Waimart\Services\SiteContentService::flushCache();
        \Waimart\Services\PaymentOptions::flush();
        \Waimart\Services\StatusLabels::flush();
    }

    /** Creates a category, product and variant with the given stock; returns ids. */
    protected function makeVariant(int $onHand = 10, string $price = '1000.00', ?int $productId = null, string $name = 'Default', ?int $sellerId = null): array
    {
        if ($productId === null) {
            $catId = (int) ($this->db->value('SELECT id FROM categories LIMIT 1') ?: $this->db->insert('categories', [
                'name' => 'Test Category', 'slug' => 'test-category-' . bin2hex(random_bytes(3)),
            ]));
            $productId = $this->db->insert('products', [
                'seller_id' => $sellerId ?? \Waimart\Services\Sellers::platformId($this->db),
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
        $sellerId = (int) $this->db->value('SELECT p.seller_id FROM products p WHERE p.id = ?', [$productId]);
        $number = (string) $this->db->value('SELECT order_number FROM orders WHERE id = ?', [$orderId]);
        $soId = $this->db->insert('seller_orders', ['order_id' => $orderId, 'seller_id' => $sellerId, 'sub_number' => $number . '-1',
            'items_subtotal' => '1000.00', 'commission_base' => '1000.00', 'seller_net' => '1000.00']);
        $itemId = $this->db->insert('order_items', [
            'order_id' => $orderId,
            'seller_id' => $sellerId,
            'seller_order_id' => $soId,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'sku' => 'SKU',
            'product_name' => 'Test Product',
            'variant_name' => 'Default',
            'unit_price' => '1000.00',
            'quantity' => $qty,
            'line_total' => '1000.00',
        ]);
        return ['order_id' => $orderId, 'item_id' => $itemId, 'seller_order_id' => $soId];
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
