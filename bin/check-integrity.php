<?php

declare(strict_types=1);

/**
 * Read-only data integrity and money reconciliation checks over the configured database: order and part totals,
 * seller attribution, stock vs reservations, refund caps, the append-only ledger guards, and every marketplace
 * reconciliation check. Changes nothing. Run before paying sellers, after a restore, or on a schedule (daily).
 * Exit code 0 when everything matches, 1 otherwise. Usage: php bin/check-integrity.php
 */

require dirname(__DIR__) . '/bootstrap.php';

use Waimart\Core\App;
use Waimart\Services\MarketplaceReportService;

$db = App::db();
echo 'Database: ' . $db->value('SELECT DATABASE()') . "\n";
$checks = [
    'order items without a seller part' => 'SELECT COUNT(*) FROM order_items WHERE seller_order_id IS NULL',
    'order items whose seller differs from their part' => 'SELECT COUNT(*) FROM order_items oi JOIN seller_orders so ON so.id = oi.seller_order_id WHERE so.seller_id <> oi.seller_id',
    'parts whose items_subtotal <> sum of their lines' => 'SELECT COUNT(*) FROM seller_orders so WHERE so.items_subtotal <> (SELECT COALESCE(SUM(line_total), 0) FROM order_items oi WHERE oi.seller_order_id = so.id)',
    'parts whose commission_total <> sum of line commission' => 'SELECT COUNT(*) FROM seller_orders so WHERE so.commission_total <> (SELECT COALESCE(SUM(commission_amount), 0) FROM order_items oi WHERE oi.seller_order_id = so.id)',
    'orders whose subtotal <> sum of their parts' => 'SELECT COUNT(*) FROM orders o WHERE o.subtotal <> (SELECT COALESCE(SUM(items_subtotal), 0) FROM seller_orders so WHERE so.order_id = o.id)',
    'orders whose discount <> sum of part discount shares' => 'SELECT COUNT(*) FROM orders o WHERE o.discount_total <> (SELECT COALESCE(SUM(discount_share), 0) FROM seller_orders so WHERE so.order_id = o.id)',
    // Per-order policy (the decision recorded in the plan): the fee is Waimart's, every part's share is 0.
    // Per-seller policy: the shares add up to the order's fee.
    'part fee shares inconsistent with the fee policy' => \Waimart\Core\Settings::get('marketplace_delivery_fee_policy', 'per_order') === 'per_seller'
        ? 'SELECT COUNT(*) FROM orders o WHERE o.delivery_fee <> (SELECT COALESCE(SUM(delivery_fee_share), 0) FROM seller_orders so WHERE so.order_id = o.id)'
        : 'SELECT COUNT(*) FROM seller_orders WHERE delivery_fee_share <> 0',
    'grand_total <> subtotal - discount + delivery' => 'SELECT COUNT(*) FROM orders WHERE grand_total <> subtotal - discount_total + delivery_fee',
    'cancelled/expired orders with a live part' => "SELECT COUNT(*) FROM orders o JOIN seller_orders so ON so.order_id = o.id WHERE o.status IN ('cancelled','expired') AND so.status NOT IN ('cancelled','rejected')",
    'products without a seller' => 'SELECT COUNT(*) FROM products WHERE seller_id IS NULL',
    'third-party variant SKUs without their seller prefix' => "SELECT COUNT(*) FROM product_variants v JOIN products p ON p.id = v.product_id JOIN sellers s ON s.id = p.seller_id WHERE s.is_platform = 0 AND v.sku NOT LIKE CONCAT('S', s.id, '-%')",
    'inventory reserved <> active reservations' => "SELECT COUNT(*) FROM inventory i WHERE i.reserved <> (SELECT COALESCE(SUM(quantity), 0) FROM stock_reservations r WHERE r.variant_id = i.variant_id AND r.status = 'active')",
    'negative stock' => 'SELECT COUNT(*) FROM inventory WHERE on_hand < 0 OR reserved < 0 OR reserved > on_hand',
    'Official Store rows in the seller ledger' => 'SELECT COUNT(*) FROM seller_ledger_entries e JOIN sellers s ON s.id = e.seller_id WHERE s.is_platform = 1',
    'refunds above what was paid for a part' => "SELECT COUNT(*) FROM (SELECT so.id FROM seller_orders so JOIN refunds r ON r.seller_order_id = so.id AND r.status <> 'cancelled' GROUP BY so.id, so.items_subtotal, so.discount_share HAVING SUM(r.amount) > so.items_subtotal - so.discount_share) x",
    'refunds above the order total' => "SELECT COUNT(*) FROM (SELECT o.id FROM orders o JOIN refunds r ON r.order_id = o.id AND r.status <> 'cancelled' GROUP BY o.id, o.grand_total HAVING SUM(r.amount) > o.grand_total) x",
    'more than one open return on a part' => "SELECT COUNT(*) FROM (SELECT seller_order_id FROM return_requests WHERE status IN ('requested','approved','received') AND seller_order_id IS NOT NULL GROUP BY seller_order_id HAVING COUNT(*) > 1) x",
    'ledger triggers installed (expect 4)' => "SELECT 4 - COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND event_object_table IN ('seller_ledger_entries','seller_payout_history')",
];
$bad = 0;
foreach ($checks as $label => $sql) {
    $n = (int) $db->value($sql);
    $bad += $n > 0 ? 1 : 0;
    printf("%-58s %s\n", $label, $n === 0 ? 'OK' : "FAIL ($n)");
}
foreach ((new MarketplaceReportService($db))->reconcile() as $c) {
    $bad += $c['ok'] ? 0 : 1;
    printf("%-58s %s\n", 'reconcile: ' . $c['check'], $c['ok'] ? 'OK' : "FAIL ({$c['expected']} vs {$c['actual']})");
}
echo $bad ? "\n$bad check(s) FAILED\n" : "\nAll checks OK\n";
exit($bad ? 1 : 0);
