<?php

declare(strict_types=1);

namespace Waimart\Services;

use Waimart\Core\App;
use Waimart\Core\Database;
use Waimart\Core\Money;

/**
 * Marketplace reports, all computed from the seller ledger and the order/payout records it was posted from:
 *  - per-seller performance for a period,
 *  - what Waimart owes sellers right now (and what sellers owe Waimart after refunds that came after a payout),
 *  - reconciliation: independent recomputations that must match the ledger exactly.
 * Gross sales are sellers' merchandise; Waimart's marketplace revenue is commission.
 */
final class MarketplaceReportService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? App::db();
    }

    /** @return list<array<string,mixed>> one row per marketplace seller with activity or a balance */
    public function sellerPerformance(string $from, string $to): array
    {
        $parts = array_column($this->db->all(
            "SELECT so.seller_id, COUNT(*) AS parts,
                    SUM(so.status IN ('delivered','collected')) AS completed,
                    SUM(so.status = 'rejected') AS declined,
                    SUM(so.status = 'cancelled') AS cancelled
               FROM seller_orders so JOIN orders o ON o.id = so.order_id
              WHERE so.is_legacy = 0 AND o.confirmed_at BETWEEN ? AND ? GROUP BY so.seller_id",
            [$from, $to]
        ), null, 'seller_id');
        $money = array_column($this->db->all(
            "SELECT seller_id,
                    SUM(CASE WHEN entry_type = 'sale' THEN amount ELSE 0 END) AS sales,
                    -SUM(CASE WHEN entry_type = 'commission' THEN amount ELSE 0 END) AS commission,
                    SUM(CASE WHEN entry_type = 'commission_reversal' THEN amount ELSE 0 END) AS commission_returned,
                    SUM(CASE WHEN entry_type IN ('cancellation','refund','correction') THEN amount ELSE 0 END) AS refunds_reversals
               FROM seller_ledger_entries WHERE created_at BETWEEN ? AND ? GROUP BY seller_id",
            [$from, $to]
        ), null, 'seller_id');
        $balances = $this->balancesBySeller();
        $out = [];
        foreach ($this->db->all('SELECT id, name, status FROM sellers WHERE is_platform = 0 ORDER BY name') as $s) {
            $id = (int) $s['id'];
            $p = $parts[$id] ?? null;
            $m = $money[$id] ?? null;
            $b = $balances[$id] ?? ['pending' => '0.00', 'available' => '0.00', 'reserved' => '0.00', 'paid' => '0.00'];
            if ($p === null && $m === null && !array_filter($b, fn ($v) => !Money::isZero((string) $v))) {
                continue;
            }
            $sales = Money::normalize((string) ($m['sales'] ?? '0'));
            $commission = Money::sub((string) ($m['commission'] ?? '0'), (string) ($m['commission_returned'] ?? '0'));
            $adj = Money::normalize((string) ($m['refunds_reversals'] ?? '0'));
            $out[] = [
                'seller_id' => $id, 'seller_name' => $s['name'], 'seller_status' => $s['status'],
                'parts' => (int) ($p['parts'] ?? 0), 'completed' => (int) ($p['completed'] ?? 0),
                'declined' => (int) ($p['declined'] ?? 0), 'cancelled' => (int) ($p['cancelled'] ?? 0),
                'decline_rate' => ($p['parts'] ?? 0) ? round(100 * (int) $p['declined'] / (int) $p['parts'], 1) : null,
                'sales' => $sales, 'commission' => $commission, 'refunds_reversals' => $adj,
                'net' => Money::add(Money::sub($sales, $commission), $adj),
                'balances' => $b,
            ];
        }
        return $out;
    }

    /** @return array{pending:string,available:string,reserved:string,paid:string,owed_to_sellers:string,owed_by_sellers:string,sellers_owing:int} */
    public function liability(): array
    {
        $tot = ['pending' => '0.00', 'available' => '0.00', 'reserved' => '0.00', 'paid' => '0.00'];
        $owedBy = '0.00';
        $owing = 0;
        foreach ($this->balancesBySeller() as $b) {
            foreach ($tot as $k => $v) {
                $tot[$k] = Money::add($v, $b[$k]);
            }
            if (Money::cmp($b['available'], '0') < 0) {
                $owedBy = Money::sub($owedBy, $b['available']);
                $owing++;
            }
        }
        // Owed to sellers: everything earned and not yet paid, counting negative balances as zero (they are owed BY sellers).
        $owedTo = Money::add($tot['pending'], $tot['reserved'], Money::add($tot['available'], $owedBy));
        return $tot + ['owed_to_sellers' => $owedTo, 'owed_by_sellers' => $owedBy, 'sellers_owing' => $owing];
    }

    /**
     * Independent checks that the ledger agrees with the records it was posted from. Each must hold exactly.
     * @return list<array{check:string,expected:string,actual:string,ok:bool}>
     */
    public function reconcile(): array
    {
        $v = fn (string $sql, array $p = []) => Money::normalize((string) ($this->db->value($sql, $p) ?? '0'));
        $accrued = "FROM seller_orders so JOIN sellers s ON s.id = so.seller_id WHERE s.is_platform = 0 AND so.is_legacy = 0 AND so.earnings_status <> 'not_accrued'";
        $checks = [
            ['Sales recorded = value of paid marketplace parts',
                $v("SELECT COALESCE(SUM(so.items_subtotal), 0) $accrued"),
                $v("SELECT COALESCE(SUM(amount), 0) FROM seller_ledger_entries WHERE entry_type = 'sale'")],
            ['Commission recorded = commission on paid marketplace parts',
                $v("SELECT COALESCE(SUM(so.commission_total), 0) $accrued"),
                $v("SELECT COALESCE(-SUM(amount), 0) FROM seller_ledger_entries WHERE entry_type = 'commission'")],
            ['Parts not supplied are fully reversed (net 0)',
                '0.00',
                $v("SELECT COALESCE(SUM(e.amount), 0) FROM seller_ledger_entries e JOIN seller_orders so ON so.id = e.seller_order_id WHERE so.earnings_status = 'reversed'")],
            ['Nothing left pending on parts already released',
                '0.00',
                $v("SELECT COALESCE(SUM(e.amount), 0) FROM seller_ledger_entries e JOIN seller_orders so ON so.id = e.seller_order_id WHERE so.earnings_status = 'available' AND e.bucket = 'pending'")],
            ['Cancelled refunds leave no deduction (net 0)',
                '0.00',
                $v("SELECT COALESCE(SUM(e.amount), 0) FROM seller_ledger_entries e JOIN refunds r ON r.id = e.refund_id WHERE r.status = 'cancelled'")],
            ['"In payout requests" = open payout requests',
                $v("SELECT COALESCE(SUM(amount), 0) FROM seller_payouts WHERE status IN ('requested','approved')"),
                $v("SELECT COALESCE(SUM(amount), 0) FROM seller_ledger_entries WHERE bucket = 'reserved'")],
            ['"Paid out" = payouts recorded as paid',
                $v("SELECT COALESCE(SUM(paid_amount), 0) FROM seller_payouts WHERE status = 'paid'"),
                $v("SELECT COALESCE(SUM(amount), 0) FROM seller_ledger_entries WHERE bucket = 'paid'")],
            ['Moves between balances net to zero',
                '0.00',
                $v("SELECT COALESCE(SUM(amount), 0) FROM seller_ledger_entries WHERE entry_type IN ('release','payout_request','payout_return','payout_paid')")],
        ];
        return array_map(fn ($c) => ['check' => $c[0], 'expected' => $c[1], 'actual' => $c[2], 'ok' => Money::cmp($c[1], $c[2]) === 0], $checks);
    }

    /** Seller Centre sales report for one seller. @return array<string,mixed> */
    public function sellerReport(int $sellerId, string $from, string $to): array
    {
        $parts = (array) $this->db->one(
            "SELECT COUNT(*) AS parts, COALESCE(SUM(so.status IN ('delivered','collected')), 0) AS completed,
                    COALESCE(SUM(so.status = 'rejected'), 0) AS declined, COALESCE(SUM(so.status = 'cancelled'), 0) AS cancelled
               FROM seller_orders so JOIN orders o ON o.id = so.order_id
              WHERE so.seller_id = ? AND so.is_legacy = 0 AND o.confirmed_at BETWEEN ? AND ?",
            [$sellerId, $from, $to]
        );
        $m = (array) $this->db->one(
            "SELECT COALESCE(SUM(CASE WHEN entry_type = 'sale' THEN amount END), 0) AS sales,
                    COALESCE(-SUM(CASE WHEN entry_type = 'commission' THEN amount END), 0) AS commission,
                    COALESCE(SUM(CASE WHEN entry_type = 'commission_reversal' THEN amount END), 0) AS commission_returned,
                    COALESCE(SUM(CASE WHEN entry_type IN ('cancellation','refund','correction') THEN amount END), 0) AS adjustments
               FROM seller_ledger_entries WHERE seller_id = ? AND created_at BETWEEN ? AND ?",
            [$sellerId, $from, $to]
        );
        $commission = Money::sub((string) $m['commission'], (string) $m['commission_returned']);
        $top = $this->db->all(
            "SELECT oi.product_name, SUM(oi.quantity - oi.returned_qty) AS units, SUM((oi.quantity - oi.returned_qty) * oi.unit_price) AS value
               FROM order_items oi JOIN seller_orders so ON so.id = oi.seller_order_id JOIN orders o ON o.id = oi.order_id
              WHERE so.seller_id = ? AND so.status IN ('delivered','collected') AND o.confirmed_at BETWEEN ? AND ?
              GROUP BY oi.product_name HAVING units > 0 ORDER BY units DESC, value DESC LIMIT 10",
            [$sellerId, $from, $to]
        );
        return [
            'parts' => (int) $parts['parts'], 'completed' => (int) $parts['completed'], 'declined' => (int) $parts['declined'], 'cancelled' => (int) $parts['cancelled'],
            'sales' => Money::normalize((string) $m['sales']), 'commission' => $commission, 'adjustments' => Money::normalize((string) $m['adjustments']),
            'net' => Money::add(Money::sub((string) $m['sales'], $commission), (string) $m['adjustments']),
            'returns' => (int) $this->db->value("SELECT COUNT(*) FROM return_requests r JOIN seller_orders so ON so.id = r.seller_order_id WHERE so.seller_id = ? AND r.requested_at BETWEEN ? AND ?", [$sellerId, $from, $to]),
            'cases' => (int) $this->db->value("SELECT COUNT(*) FROM seller_disputes d JOIN seller_orders so ON so.id = d.seller_order_id WHERE so.seller_id = ? AND d.created_at BETWEEN ? AND ?", [$sellerId, $from, $to]),
            'top' => $top,
        ];
    }

    /** @return array<int,array{pending:string,available:string,reserved:string,paid:string}> */
    private function balancesBySeller(): array
    {
        $out = [];
        foreach ($this->db->all('SELECT seller_id, bucket, SUM(amount) AS t FROM seller_ledger_entries GROUP BY seller_id, bucket') as $r) {
            $out[(int) $r['seller_id']] ??= ['pending' => '0.00', 'available' => '0.00', 'reserved' => '0.00', 'paid' => '0.00'];
            $out[(int) $r['seller_id']][$r['bucket']] = Money::normalize((string) $r['t']);
        }
        return $out;
    }
}
