<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;

/**
 * Read-only reporting. Definitions (shown on every report):
 *   Booked sales  = orders PLACED in the period, excluding cancelled and expired.
 *   Cash received = M-Pesa amounts VERIFIED in the period + COD cash RECORDED in the period.
 * Dates are inclusive calendar days in Africa/Nairobi.
 */
final class ReportService
{
    public const EXCLUDED = "('cancelled','expired')";

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @return array{0:string,1:string} [from 00:00:00, to 23:59:59] */
    public static function range(?string $from, ?string $to, int $defaultDays = 30): array
    {
        $valid = fn ($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
        $toD = $valid($to) ? $to : date('Y-m-d');
        $fromD = $valid($from) ? $from : date('Y-m-d', strtotime("$toD -" . ($defaultDays - 1) . ' days'));
        if ($fromD > $toD) {
            [$fromD, $toD] = [$toD, $fromD];
        }
        // Cap at 366 days to keep queries cheap.
        if ((strtotime($toD) - strtotime($fromD)) / 86400 > 366) {
            $fromD = date('Y-m-d', strtotime("$toD -366 days"));
        }
        return [$fromD . ' 00:00:00', $toD . ' 23:59:59'];
    }

    /** @return array<string,string|int> */
    public function salesSummary(string $from, string $to): array
    {
        $row = $this->db->one(
            'SELECT COUNT(*) AS orders, COALESCE(SUM(subtotal),0) AS merchandise, COALESCE(SUM(discount_total),0) AS discounts,
                    COALESCE(SUM(delivery_fee),0) AS delivery_fees, COALESCE(SUM(grand_total),0) AS booked,
                    COALESCE(AVG(grand_total),0) AS avg_order
               FROM orders WHERE placed_at BETWEEN ? AND ? AND status NOT IN ' . self::EXCLUDED,
            [$from, $to]
        );
        $lost = $this->db->one(
            "SELECT SUM(status = 'cancelled') AS cancelled, SUM(status = 'expired') AS expired FROM orders WHERE placed_at BETWEEN ? AND ?",
            [$from, $to]
        );
        $cash = $this->cashReceived($from, $to);
        return [
            'orders' => (int) $row['orders'],
            'merchandise' => (string) $row['merchandise'],
            'discounts' => (string) $row['discounts'],
            'delivery_fees' => (string) $row['delivery_fees'],
            'booked' => (string) $row['booked'],
            'avg_order' => number_format((float) $row['avg_order'], 2, '.', ''),
            'cancelled' => (int) ($lost['cancelled'] ?? 0),
            'expired' => (int) ($lost['expired'] ?? 0),
            'cash_mpesa' => $cash['mpesa'],
            'cash_cod' => $cash['cod'],
            'cash_total' => \Waimart\Core\Money::add($cash['mpesa'], $cash['cod']),
        ];
    }

    /** @return array{mpesa:string,cod:string} */
    public function cashReceived(string $from, string $to): array
    {
        $mpesa = (string) $this->db->value(
            "SELECT COALESCE(SUM(amount_confirmed),0) FROM payment_verifications WHERE decision = 'verified' AND created_at BETWEEN ? AND ?",
            [$from, $to]
        );
        $cod = (string) $this->db->value('SELECT COALESCE(SUM(amount_collected),0) FROM cod_collections WHERE collected_at BETWEEN ? AND ?', [$from, $to]);
        return ['mpesa' => $mpesa, 'cod' => $cod];
    }

    /**
     * Booked sales per day, including days with no orders.
     * @return list<array{day:string,orders:int,booked:string}>
     */
    public function daily(string $from, string $to): array
    {
        $rows = $this->db->all(
            'SELECT DATE(placed_at) AS day, COUNT(*) AS orders, SUM(grand_total) AS booked
               FROM orders WHERE placed_at BETWEEN ? AND ? AND status NOT IN ' . self::EXCLUDED . ' GROUP BY DATE(placed_at)',
            [$from, $to]
        );
        $byDay = array_column($rows, null, 'day');
        $out = [];
        for ($d = strtotime(substr($from, 0, 10)); $d <= strtotime(substr($to, 0, 10)); $d += 86400) {
            $key = date('Y-m-d', $d);
            $out[] = ['day' => $key, 'orders' => (int) ($byDay[$key]['orders'] ?? 0), 'booked' => (string) ($byDay[$key]['booked'] ?? '0.00')];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function byDimension(string $from, string $to, string $column): array
    {
        if (!in_array($column, ['payment_method', 'fulfilment_method', 'status'], true)) {
            throw new \InvalidArgumentException('Unsupported dimension.');
        }
        $excl = $column === 'status' ? '' : ' AND status NOT IN ' . self::EXCLUDED;
        return $this->db->all(
            "SELECT `$column` AS label, COUNT(*) AS orders, SUM(grand_total) AS booked FROM orders
              WHERE placed_at BETWEEN ? AND ?$excl GROUP BY `$column` ORDER BY booked DESC",
            [$from, $to]
        );
    }

    /** @return list<array<string,mixed>> */
    public function topProducts(string $from, string $to, int $limit = 20): array
    {
        return $this->db->all(
            'SELECT oi.product_id, oi.product_name, SUM(oi.quantity) AS units, SUM(oi.line_total) AS revenue, COUNT(DISTINCT oi.order_id) AS orders
               FROM order_items oi JOIN orders o ON o.id = oi.order_id
              WHERE o.placed_at BETWEEN ? AND ? AND o.status NOT IN ' . self::EXCLUDED . '
              GROUP BY oi.product_id, oi.product_name ORDER BY revenue DESC LIMIT ' . max(1, min(200, $limit)),
            [$from, $to]
        );
    }

    /**
     * Stock levels, lowest availability first. $limit caps the on-screen view; the CSV export passes null for every variant.
     * @return list<array<string,mixed>>
     */
    public function stock(?int $limit = null): array
    {
        return $this->db->all(
            'SELECT v.sku, p.name AS product, v.name AS variant, v.price, i.on_hand, i.reserved, (i.on_hand - i.reserved) AS available,
                    i.low_stock_threshold, v.is_active
               FROM inventory i JOIN product_variants v ON v.id = i.variant_id JOIN products p ON p.id = v.product_id
              ORDER BY (i.on_hand - i.reserved) ASC, p.name' . ($limit !== null ? ' LIMIT ' . max(1, $limit) : '')
        );
    }

    public function stockCount(): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM inventory');
    }

    /** @return list<array<string,mixed>> */
    public function payments(string $from, string $to): array
    {
        return $this->db->all(
            'SELECT s.transaction_code, o.order_number, s.method, s.amount_claimed, s.status, s.submitted_at,
                    v.amount_confirmed, v.created_at AS decided_at, CONCAT(u.first_name, \' \', u.last_name) AS decided_by, v.note
               FROM payment_submissions s JOIN orders o ON o.id = s.order_id
               LEFT JOIN payment_verifications v ON v.submission_id = s.id LEFT JOIN users u ON u.id = v.actor_user_id
              WHERE s.submitted_at BETWEEN ? AND ? ORDER BY s.submitted_at',
            [$from, $to]
        );
    }

    /** COD reconciliation: expected vs collected, including handed-over orders with no cash recorded. */
    public function cod(string $from, string $to): array
    {
        return $this->db->all(
            "SELECT o.order_number, o.status, o.grand_total AS expected, c.amount_collected AS collected,
                    (c.amount_collected - o.grand_total) AS difference, c.collected_at, CONCAT(u.first_name, ' ', u.last_name) AS collected_by, c.note
               FROM orders o LEFT JOIN cod_collections c ON c.order_id = o.id LEFT JOIN users u ON u.id = c.collected_by
              WHERE o.payment_method = 'cod' AND o.status IN ('dispatched','out_for_delivery','delivered','collected')
                AND COALESCE(o.completed_at, o.placed_at) BETWEEN ? AND ?
              ORDER BY c.id IS NULL DESC, o.placed_at",
            [$from, $to]
        );
    }

    /** @return list<array<string,mixed>> */
    public function refunds(string $from, string $to): array
    {
        return $this->db->all(
            'SELECT o.order_number, r.amount, r.reason, r.status, r.method, r.reference, r.created_at, r.paid_at
               FROM refunds r JOIN orders o ON o.id = r.order_id WHERE r.created_at BETWEEN ? AND ? ORDER BY r.created_at',
            [$from, $to]
        );
    }
}
